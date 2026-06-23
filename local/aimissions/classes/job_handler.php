<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Moteur de génération des missions client (Agent 1 — « Product Owner »).
 *
 * Implémente le contrat \local_aifeedback\job_handler : le dispatcher partagé
 * (run_job) tient le lock LLM global et appelle execute()/find_drainable_payloads().
 *
 * Pour chaque job (un groupe, un sprint) :
 *   1. charge/crée le projet (l'entreprise fictive persistante du groupe) ;
 *   2. lit le dossier projet et demande au LLM la mission suivante (cohérente,
 *      progressive, ton du persona) — schéma JSON strict ;
 *   3. crée un DEVOIR caché, restreint au groupe, avec soumission texte+fichier
 *      et la correction IA (assignfeedback_ai) activée et PRÉ-CONFIGURÉE ;
 *   4. pose la compétence EFE sur le devoir (via efe_bridge, no-op si absent) ;
 *   5. met à jour le dossier du projet et le numéro de sprint.
 */
class job_handler implements \local_aifeedback\job_handler {

    /** @var string */
    const TABLE_JOB     = 'local_aimissions_job';
    /** @var string */
    const TABLE_PROJECT = 'local_aimissions_project';
    /** @var string */
    const TABLE_MISSION = 'local_aimissions_mission';
    /** @var string */
    const TABLE_EVENT   = 'local_aimissions_event';
    /** @var int Tentatives avant de basculer un job en failed. */
    const MAX_ATTEMPTS  = 3;

    /**
     * Enfile un job de génération dans la file partagée.
     */
    public static function enqueue($jobid) {
        $payload = new \stdClass();
        $payload->rowid = (int)$jobid;
        \local_aifeedback\task\run_job::enqueue('local_aimissions', $payload);
    }

    /**
     * Traite un job. Le lock LLM est déjà tenu par le dispatcher.
     */
    public function execute(\stdClass $payload): void {
        global $DB;

        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return;
        }
        $job = $DB->get_record(self::TABLE_JOB, array('id' => $rowid));
        if (!$job) {
            return; // supprimé entre-temps
        }
        if ($job->status !== 'pending') {
            return; // déjà traité
        }

        // Passe en running (visible sur la page de statut).
        $job->status       = 'running';
        $job->timemodified = time();
        $DB->update_record(self::TABLE_JOB, $job);

        try {
            if ($job->kind === 'event') {
                $this->process_event($job);
            } else {
                $this->process_one($job);
            }

            $job = $DB->get_record(self::TABLE_JOB, array('id' => $rowid));
            if ($job) {
                $job->status       = 'done';
                $job->lasterror    = null;
                $job->timemodified = time();
                $DB->update_record(self::TABLE_JOB, $job);
            }
        } catch (\Throwable $e) {
            $this->record_failure($rowid, $e);
            $current = $DB->get_record(self::TABLE_JOB, array('id' => $rowid));
            if ($current && (int)$current->attempts < self::MAX_ATTEMPTS) {
                self::enqueue($rowid);
            }
            // Propage pour que le dispatcher arrête le drainage (LLM peut-être down).
            throw $e;
        }
    }

    /**
     * Renvoie le prochain job en attente (drainage sous le même lock).
     */
    public function find_drainable_payloads(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id FROM {' . self::TABLE_JOB . '}
              WHERE status = ?
           ORDER BY timecreated ASC',
            array('pending'), 0, 1);
        if (empty($rows)) {
            return array();
        }
        $first = reset($rows);
        $payload = new \stdClass();
        $payload->rowid = (int)$first->id;
        return array($payload);
    }

    // =====================================================================
    //  ORCHESTRATION
    // =====================================================================

    /**
     * Génère une mission pour le projet/groupe du job.
     */
    private function process_one(\stdClass $job): void {
        global $DB;

        $params = json_decode((string)$job->params, true) ?: array();

        // --- 1. Projet (entreprise fictive du groupe) -------------------
        $project = $this->load_or_create_project($job, $params);
        $this->log($job, 'Projet : « ' . $project->companyname . ' » (groupe '
            . (int)$project->groupid . ', sprint à venir n°' . ((int)$project->currentsprint + 1) . ')');

        $sprint = (int)$project->currentsprint + 1;
        $maxsprints = (int)(get_config('local_aimissions', 'maxsprints') ?: 20);
        if ($sprint > $maxsprints) {
            throw new \moodle_exception('error_maxsprints', 'local_aimissions');
        }

        // --- 2. Appel LLM : la mission suivante -------------------------
        $this->log($job, 'Appel LLM (Agent 1) pour générer la mission…');
        $spec = $this->generate_mission($project, $sprint, $params);
        $this->log($job, 'Mission reçue : « ' . $spec['title'] . ' »');

        // --- 3. Devoir caché, restreint au groupe -----------------------
        $name = $this->truncate($spec['title'], 250);
        $intro = $this->ensure_html($spec['clientrequest']);
        $this->log($job, 'Création du devoir caché…');
        $cm = $this->create_assign_module($job, $project, $name, $intro);
        $assignid = (int)$cm->instance;
        $cmid     = (int)$cm->id;

        // --- 4. Pré-config de la correction IA (Agent 3) ----------------
        $this->prefill_feedback_config($assignid, $project, $spec);
        $this->log($job, 'Correction IA pré-configurée sur le devoir.');

        // --- 5. Compétence EFE positionnée sur le devoir ----------------
        $codes = array(
            'n1' => (string)($params['efe_n1'] ?? ''),
            'n2' => (string)($params['efe_n2'] ?? ''),
            'n3' => (string)($params['efe_n3'] ?? ''),
        );
        if (efe_bridge::attach_competency($cmid, (int)$job->courseid, $codes, (int)$job->userid)) {
            $this->log($job, 'Compétence EFE rattachée (report automatique à la correction).');
        } else if (!efe_bridge::is_available()) {
            $this->log($job, 'local_efenotes absent : report de compétence ignoré.');
        }

        // --- 6. Enregistre la mission + met à jour le dossier -----------
        $missionid = $this->store_mission($project, $sprint, $spec, $cmid, $codes);
        $this->update_project_dossier($project, $spec, $sprint);

        $DB->set_field(self::TABLE_JOB, 'resultmissionid', $missionid, array('id' => $job->id));
        $this->log($job, 'Mission #' . $missionid . ' enregistrée (cmid ' . $cmid
            . ', devoir caché — à relire puis publier).');
    }

    /**
     * Charge le projet du (cours, groupe), ou le crée à la 1ʳᵉ génération.
     */
    private function load_or_create_project(\stdClass $job, array $params): \stdClass {
        global $DB;

        $courseid = (int)$job->courseid;
        $groupid  = (int)($params['groupid'] ?? 0);

        if ((int)$job->projectid > 0) {
            $existing = $DB->get_record(self::TABLE_PROJECT, array('id' => (int)$job->projectid));
            if ($existing) {
                return $existing;
            }
        }
        $existing = $DB->get_record(self::TABLE_PROJECT,
            array('courseid' => $courseid, 'groupid' => $groupid));
        if ($existing) {
            return $existing;
        }

        // Première génération pour ce groupe : on crée le projet. L'entreprise
        // (companyname/sector/persona) sera renseignée par le 1ᵉ appel LLM ;
        // on pose un nom provisoire pour respecter la contrainte NOT NULL.
        $now = time();
        $project = new \stdClass();
        $project->courseid          = $courseid;
        $project->groupid           = $groupid;
        $project->companyname       = '(entreprise à générer)';
        $project->sector            = null;
        $project->persona           = null;
        $project->personaprofile    = (string)($params['personaprofile'] ?? 'neutre');
        $project->competencytargets = (string)($params['competencylabel'] ?? '');
        $project->dossier           = json_encode(array('history' => array(), 'technos' => array(),
            'constraints' => array()), JSON_UNESCAPED_UNICODE);
        $project->currentsprint     = 0;
        $project->timecreated       = $now;
        $project->timemodified      = $now;
        $project->id = $DB->insert_record(self::TABLE_PROJECT, $project);
        $DB->set_field(self::TABLE_JOB, 'projectid', $project->id, array('id' => $job->id));
        return $project;
    }

    // =====================================================================
    //  ÉVÉNEMENTS (le client se manifeste : besoin / bug / RGPD / budget)
    // =====================================================================

    /**
     * Génère une communication client (événement) pour un projet existant.
     * Stockée en attente de publication (revue enseignant), comme une mission.
     */
    private function process_event(\stdClass $job): void {
        global $DB;

        $params    = json_decode((string)$job->params, true) ?: array();
        $projectid = (int)($params['projectid'] ?? $job->projectid);
        $project   = $DB->get_record(self::TABLE_PROJECT, array('id' => $projectid));
        if (!$project) {
            throw new \moodle_exception('error_event_noproject', 'local_aimissions');
        }
        $type = (string)($params['eventtype'] ?? 'besoin');
        $hint = (string)($params['hint'] ?? '');

        $this->log($job, 'Génération d\'un événement « ' . $type . ' » pour « '
            . $project->companyname . ' »…');

        $missions = $DB->get_records(self::TABLE_MISSION,
            array('projectid' => $projectid, 'status' => 'published'), 'sprint DESC', '*', 0, 1);
        $mission = $missions ? reset($missions) : null;

        $body = $this->generate_event_body($project, $mission, $type, $hint);

        $ev = new \stdClass();
        $ev->projectid   = $projectid;
        $ev->missionid   = $mission ? (int)$mission->id : 0;
        $ev->type        = $type;
        $ev->body        = $body;
        $ev->applied     = 0; // en attente de publication
        $ev->timecreated = time();
        $evid = (int)$DB->insert_record(self::TABLE_EVENT, $ev);

        $this->log($job, 'Événement #' . $evid . ' généré (en attente de publication).');
    }

    /**
     * Appel LLM produisant le corps du message client (texte brut).
     */
    private function generate_event_body(\stdClass $project, ?\stdClass $mission,
                                         string $type, string $hint): string {
        $messages = array(
            array('role' => 'system', 'content' => $this->event_system_prompt($project, $mission)),
            array('role' => 'user',   'content' => $this->event_user_prompt($type, $hint)),
        );
        $options = array('temperature' => 0.6, 'max_tokens' => 700);
        $model = (string)get_config('local_aimissions', 'model');
        if ($model !== '') {
            $options['model'] = $model;
        }
        $result = \local_aifeedback\api::call($messages, $options);
        $text = is_array($result) ? trim((string)($result['__text__'] ?? '')) : '';
        if ($text === '') {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }
        return $text;
    }

    private function event_system_prompt(\stdClass $project, ?\stdClass $mission): string {
        $contact = trim((string)$project->persona);
        $p  = "Tu ES le client de l'entreprise « " . $project->companyname . " »";
        if ($contact !== '') {
            $p .= ' (contact : ' . $contact . ')';
        }
        $p .= '. ' . personas::instruction((string)$project->personaprofile) . "\n\n";
        $p .= "Tu écris SPONTANÉMENT un court message (courriel) à ton prestataire (une équipe ";
        $p .= "d'étudiants BTS CIEL) pour l'informer d'un changement. Reste dans ton rôle : parle ";
        $p .= "MÉTIER, n'impose aucune solution technique ni techno. 3 à 6 phrases.\n";
        if (!empty($project->sector)) {
            $p .= 'Secteur : ' . $project->sector . "\n";
        }
        $dossier = json_decode((string)$project->dossier, true) ?: array();
        if (!empty($dossier['history']) && is_array($dossier['history'])) {
            $p .= "Historique du projet :\n";
            foreach ($dossier['history'] as $i => $h) {
                $p .= '  - Sprint ' . ($i + 1) . ' : ' . $h . "\n";
            }
        }
        if ($mission) {
            $p .= 'Travail en cours (sprint ' . (int)$mission->sprint . ') : '
                . \core_text::substr(trim(strip_tags((string)$mission->clientrequest)), 0, 800) . "\n";
        }
        return $p;
    }

    private function event_user_prompt(string $type, string $hint): string {
        $u = 'Situation à communiquer : ' . $this->event_type_instruction($type) . "\n";
        if (trim($hint) !== '') {
            $u .= 'Élément précis à intégrer : ' . trim($hint) . "\n";
        }
        $u .= "Rédige le message du client (juste le corps, sans objet d'en-tête).";
        return $u;
    }

    private function event_type_instruction(string $type): string {
        switch ($type) {
            case 'bug':
                return "des utilisateurs signalent un PROBLÈME CRITIQUE ; décris le SYMPTÔME constaté "
                     . "(pas la cause technique), avec l'inquiétude d'un client.";
            case 'rgpd':
                return "une nouvelle RÉGLEMENTATION (type RGPD) s'applique désormais ; explique la "
                     . "nouvelle contrainte en termes métier (consentement, anonymisation, conservation…).";
            case 'budget':
                return "le BUDGET du projet vient d'être RÉDUIT ; annonce la contrainte et demande de "
                     . "prioriser l'essentiel.";
            case 'besoin':
            default:
                return "tes BESOINS ont évolué ; annonce un changement concret de besoin qui impacte "
                     . "le travail en cours.";
        }
    }

    // =====================================================================
    //  AGENT 1 — APPEL LLM
    // =====================================================================

    /**
     * Demande au LLM la mission du sprint, en lui fournissant le dossier projet.
     *
     * @return array spec validée : title, clientrequest, rubric, competencies[],
     *               deliverables[], companyname, sector, persona, dossier_update.
     */
    private function generate_mission(\stdClass $project, int $sprint, array $params): array {
        $messages = array(
            array('role' => 'system', 'content' => $this->system_prompt($project, $sprint, $params)),
            array('role' => 'user',   'content' => $this->user_prompt($project, $sprint, $params)),
        );

        $options = array(
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'local_aimissions_mission',
                    'strict' => true,
                    'schema' => $this->mission_schema(),
                ),
            ),
            'temperature' => 0.6, // un peu de créativité pour varier les contextes
            'max_tokens'  => 4096,
        );
        $model = (string)get_config('local_aimissions', 'model');
        if ($model !== '') {
            $options['model'] = $model;
        }

        $result = \local_aifeedback\api::call($messages, $options);
        if (!$this->is_valid_spec($result)) {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }
        return $result;
    }

    /**
     * Prompt système : rôle, paradigme, persona, consignes de progression.
     */
    private function system_prompt(\stdClass $project, int $sprint, array $params): string {
        $level      = (string)($params['level'] ?? 'BTS CIEL 1ère année');
        $complexity = (string)($params['complexity'] ?? 'Intermédiaire');
        $module     = (string)($params['module'] ?? '');
        $complabel  = (string)($params['competencylabel'] ?? '');
        $nbcontr    = (int)($params['constraints'] ?? 3);

        $p  = "Tu es un PRODUCT OWNER fictif qui rédige des demandes client pour des étudiants ";
        $p .= "en " . $level . " (BTS CIEL — Cybersécurité, Informatique et réseaux, Électronique). ";
        $p .= "Tu génères un cahier des charges réaliste sous forme d'un message d'un client à un ";
        $p .= "prestataire informatique. L'étudiant (en équipe projet) doit ANALYSER le besoin, ";
        $p .= "le CONCEVOIR puis le RÉALISER, et livrer un résultat.\n\n";

        $p .= "RÈGLE PÉDAGOGIQUE CAPITALE : le client parle MÉTIER, jamais technique. ";
        $p .= "Tu dois faire travailler la (les) compétence(s) visée(s) SANS JAMAIS NOMMER les ";
        $p .= "technologies ni les concepts : c'est à l'étudiant de les déduire du besoin.\n";
        if ($complabel !== '') {
            $p .= "Compétence(s) à faire travailler (NE PAS la nommer dans l'énoncé) : "
                . $complabel . ".\n";
        }
        if ($module !== '') {
            $p .= "Module / matière : " . $module . ".\n";
        }
        $p .= "Niveau : " . $level . ". Complexité visée : " . $complexity . ". ";
        $p .= "Nombre de contraintes à intégrer : environ " . $nbcontr . ".\n\n";

        $p .= "PERSONA DU CLIENT : " . $this->persona_instruction((string)$project->personaprofile) . "\n\n";

        if ($sprint <= 1) {
            $p .= "C'est le PREMIER sprint : invente une entreprise fictive crédible (nom, secteur, ";
            $p .= "contact) et une première demande accessible qui pose les fondations du projet.\n";
        } else {
            $p .= "C'est le sprint n°" . $sprint . " d'un projet EN COURS. Tu DOIS rester cohérent ";
            $p .= "avec le dossier projet fourni (même entreprise, mêmes choix déjà faits) et ";
            $p .= "proposer une ÉVOLUTION progressive du besoin (nouvelle fonctionnalité, montée en ";
            $p .= "charge, contrainte nouvelle…), comme un vrai client qui revient vers son prestataire.\n";
        }
        $p .= "\nLes livrables doivent tenir dans une remise de devoir Moodle (rapport + fichiers ";
        $p .= "sources + captures) : ne demande pas de déploiement externe invérifiable.\n";
        $p .= "La structure JSON de ta réponse est imposée par le schéma fourni.";
        return $p;
    }

    /**
     * Prompt utilisateur : le dossier projet (mémoire) + la demande.
     */
    private function user_prompt(\stdClass $project, int $sprint, array $params): string {
        $u = "DOSSIER PROJET (mémoire — à respecter pour la cohérence) :\n";
        if ($sprint <= 1) {
            $u .= "(projet neuf, aucune histoire pour l'instant)\n";
        } else {
            $u .= 'Entreprise : ' . $project->companyname . "\n";
            if (!empty($project->sector)) {
                $u .= 'Secteur : ' . $project->sector . "\n";
            }
            if (!empty($project->persona)) {
                $u .= 'Contact client : ' . $project->persona . "\n";
            }
            $dossier = json_decode((string)$project->dossier, true) ?: array();
            $hist = $dossier['history'] ?? array();
            if (!empty($hist)) {
                $u .= "Historique des sprints précédents :\n";
                foreach ($hist as $i => $h) {
                    $u .= '  - Sprint ' . ($i + 1) . ' : ' . $h . "\n";
                }
            }
            if (!empty($dossier['technos'])) {
                $u .= 'Technologies déjà retenues : ' . implode(', ', (array)$dossier['technos']) . "\n";
            }
            if (!empty($dossier['constraints'])) {
                $u .= 'Contraintes connues : ' . implode(', ', (array)$dossier['constraints']) . "\n";
            }
        }
        $u .= "\nGénère la demande client du sprint n°" . $sprint . ".";
        return $u;
    }

    /**
     * Traduction du profil psychologique en consigne de ton.
     */
    private function persona_instruction(string $code): string {
        return personas::instruction($code);
    }

    /**
     * Schéma JSON strict (compatible mode strict OpenAI).
     */
    private function mission_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'companyname'   => array('type' => 'string'),
                'sector'        => array('type' => 'string'),
                'persona'       => array('type' => 'string'),
                'title'         => array('type' => 'string'),
                'clientrequest' => array('type' => 'string'),
                'rubric'        => array('type' => 'string'),
                'competencies'  => array('type' => 'array', 'items' => array('type' => 'string')),
                'deliverables'  => array('type' => 'array', 'items' => array('type' => 'string')),
                'dossier_update' => array('type' => 'string'),
            ),
            'required' => array('companyname', 'sector', 'persona', 'title', 'clientrequest',
                'rubric', 'competencies', 'deliverables', 'dossier_update'),
        );
    }

    /**
     * Valide la réponse du LLM.
     */
    private function is_valid_spec($spec): bool {
        return is_array($spec)
            && !empty($spec['title'])
            && !empty($spec['clientrequest'])
            && !empty($spec['rubric']);
    }

    // =====================================================================
    //  CRÉATION DU DEVOIR (Stratégie INSERT direct, cf. local_aiquizgen)
    // =====================================================================

    /**
     * Crée un module « assign » caché, restreint au groupe du projet, avec
     * soumission texte + fichier et la correction IA activée.
     *
     * INSERT direct (comme local_aiquizgen pour les quiz) : on NE PASSE PAS par
     * assign_add_instance() qui attend une structure form complète. On assemble
     * nous-mêmes le record {assign}, on active les plugins voulus via
     * {assign_plugin_config}, et on inscrit l'activité au carnet de notes.
     *
     * @return \stdClass course module final (avec ->instance = assign.id).
     */
    private function create_assign_module(\stdClass $job, \stdClass $project,
                                          string $name, string $intro): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $course   = get_course((int)$job->courseid);
        $moduleid = (int)$DB->get_field('modules', 'id', array('name' => 'assign'));
        $now      = time();
        $groupid  = (int)$project->groupid;

        // Restriction d'accès au groupe (caché aux autres) si un groupe est défini.
        $availability = null;
        if ($groupid > 0) {
            $availability = json_encode(array(
                'op'    => '&',
                'c'     => array(array('type' => 'group', 'id' => $groupid)),
                'showc' => array(false), // masqué aux non-membres
            ));
        }

        // --- 1. {course_modules} (sans instance encore) -----------------
        $newcm = new \stdClass();
        $newcm->course                    = (int)$job->courseid;
        $newcm->module                    = $moduleid;
        $newcm->instance                  = 0;
        $newcm->visible                   = 0; // caché : l'enseignant relit avant
        $newcm->visibleoncoursepage       = 1;
        $newcm->groupmode                 = 0;
        $newcm->groupingid                = 0;
        $newcm->completion                = 0;
        $newcm->completiongradeitemnumber = null;
        $newcm->completionpassgrade       = 0;
        $newcm->completionview            = 0;
        $newcm->completionexpected        = 0;
        $newcm->availability              = $availability;
        $newcm->showdescription           = 0;
        $cmid = (int)add_course_module($newcm);
        if (!$cmid) {
            throw new \moodle_exception('error_assign_creation', 'local_aimissions');
        }
        // Force la création du contexte module (sinon « Invalid context id »).
        \context_module::instance($cmid);

        // --- 2. INSERT direct dans {assign} -----------------------------
        $assign = new \stdClass();
        $assign->course                      = (int)$job->courseid;
        $assign->name                        = $name;
        $assign->intro                       = $intro;
        $assign->introformat                 = FORMAT_HTML;
        $assign->alwaysshowdescription       = 1;
        $assign->nosubmissions               = 0;
        $assign->submissiondrafts            = 0;
        $assign->sendnotifications           = 0;
        $assign->sendlatenotifications       = 0;
        $assign->duedate                     = 0;
        $assign->allowsubmissionsfromdate    = 0;
        $assign->grade                       = 100; // points : la correction IA mappe 0–100
        $assign->timemodified                = $now;
        $assign->requiresubmissionstatement  = 0;
        $assign->completionsubmit            = 0;
        $assign->cutoffdate                  = 0;
        $assign->gradingduedate              = 0;
        $assign->teamsubmission              = 0; // Phase 1 : soumission individuelle (restreinte au groupe)
        $assign->requireallteammemberssubmit = 0;
        $assign->teamsubmissiongroupingid    = 0;
        $assign->blindmarking                = 0;
        $assign->hidegrader                  = 0;
        $assign->revealidentities            = 0;
        $assign->attemptreopenmethod         = 'none';
        $assign->maxattempts                 = -1; // illimité
        $assign->markingworkflow             = 0;
        $assign->markingallocation           = 0;
        $assign->markercount                 = 1;
        $assign->markinganonymous            = 0;
        $assign->sendstudentnotifications    = 1;
        $assign->preventsubmissionnotingroup = 0;
        $assign->activity                    = null;
        $assign->activityformat              = 0;
        $assign->timelimit                   = 0;
        $assign->submissionattachments       = 0;
        $assign->gradepenalty                = 0;

        try {
            $assignid = (int)$DB->insert_record('assign', $assign);
        } catch (\Throwable $e) {
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
            $DB->delete_records('course_modules', array('id' => $cmid));
            throw $e;
        }
        $assign->id = $assignid;

        // --- 3. cm.instance ← assign.id ---------------------------------
        $DB->set_field('course_modules', 'instance', $assignid, array('id' => $cmid));

        // --- 4. Section générale ----------------------------------------
        course_add_cm_to_section($course, $cmid, 0);

        // --- 5. Activation des plugins (soumission + correction IA) -----
        // is_enabled() lit {assign_plugin_config}.name='enabled' ; absent = off.
        $this->enable_plugin($assignid, 'assignsubmission', 'onlinetext', array('enabled' => '1'));
        $this->enable_plugin($assignid, 'assignsubmission', 'file', array(
            'enabled'                => '1',
            'maxfilesubmissions'     => '5',
            'maxsubmissionsizebytes' => '0', // 0 = limite du cours
            'filetypeslist'          => '',
        ));
        $this->enable_plugin($assignid, 'assignfeedback', 'ai', array('enabled' => '1'));

        // --- 6. Carnet de notes -----------------------------------------
        $assign->cmidnumber = '';
        $assign->cmid       = $cmid;
        $assign->coursemodule = $cmid;
        try {
            assign_grade_item_update($assign);
        } catch (\Throwable $e) {
            $DB->delete_records('assign_plugin_config', array('assignment' => $assignid));
            $DB->delete_records('assign', array('id' => $assignid));
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
            $DB->delete_records('course_modules', array('id' => $cmid));
            throw $e;
        }

        // --- 7. Reconstruit le cache du cours ---------------------------
        rebuild_course_cache((int)$job->courseid, true);

        $cm = get_coursemodule_from_instance('assign', $assignid);
        if (!$cm) {
            throw new \moodle_exception('error_assign_creation', 'local_aimissions');
        }
        return $cm;
    }

    /**
     * Pose les lignes {assign_plugin_config} d'un plugin (active + réglages).
     */
    private function enable_plugin(int $assignid, string $subtype, string $plugin, array $settings): void {
        global $DB;
        foreach ($settings as $name => $value) {
            $rec = new \stdClass();
            $rec->assignment = $assignid;
            $rec->subtype    = $subtype;
            $rec->plugin     = $plugin;
            $rec->name       = $name;
            $rec->value      = (string)$value;
            $DB->insert_record('assign_plugin_config', $rec);
        }
    }

    /**
     * Pré-remplit la ligne {assignfeedback_ai} : la correction IA tourne au
     * dépôt sans aucune saisie de l'enseignant (Agent 3 = plugin existant).
     */
    private function prefill_feedback_config(int $assignid, \stdClass $project, array $spec): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('assignfeedback_ai')) {
            return; // plugin de correction absent : devoir créé quand même
        }

        $competencies = '';
        if (!empty($spec['competencies']) && is_array($spec['competencies'])) {
            $competencies = implode("\n", array_map('strval', $spec['competencies']));
        }
        $now = time();

        $cfg = new \stdClass();
        $cfg->assignment             = $assignid;
        $cfg->systemprompt           = $this->correction_system_prompt($project);
        $cfg->exercise               = $this->ensure_html((string)$spec['clientrequest']);
        $cfg->expectedanswer         = (string)$spec['rubric'];
        $cfg->competencies           = $competencies;
        $cfg->apiurl                 = '';
        $cfg->apiurl_override        = 0;
        $cfg->model                  = '';
        $cfg->model_override         = 0;
        $cfg->apikey                 = '';
        $cfg->apikey_override        = 0;
        $cfg->vision_enabled         = 0;
        $cfg->vision_enabled_override = 0;
        $cfg->timecreated            = $now;
        $cfg->timemodified           = $now;

        // La clé unique est sur 'assignment' : insert simple (devoir neuf).
        if (!$DB->record_exists('assignfeedback_ai', array('assignment' => $assignid))) {
            $DB->insert_record('assignfeedback_ai', $cfg);
        }
    }

    /**
     * Système de correction (Agent 3) : l'IA évalue en se mettant à la place
     * du client, sur plusieurs dimensions.
     */
    private function correction_system_prompt(\stdClass $project): string {
        // Ajout d'éléments de contexte (BTS CIEL, barème, compétences)
        $p  = "Tu es un correcteur pédagogique spécialisé dans l'enseignement supérieur technologique français";
        $p .= " (BTS Informatique / BTS CIEL).\n";
        $p .= "Ton rôle est d'évaluer objectivement les réponses d'étudiants à partir :\n";
        $p .= "- d'un exercice,\n- des compétences visées,\n- des attentes pédagogiques.\n";


        $p .= "Tu dois :\n";
        $p .= "1. analyser la réponse de l'étudiant,\n";
        $p .= "2. identifier les éléments corrects,\n";
        $p .= "3. identifier les erreurs, oublis ou imprécisions,\n";
        $p .= "4. produire un retour pédagogique constructif,\n";
        $p .= "5. déterminer un niveau de maîtrise.\n\n";
        $p .= "Les niveaux possibles sont STRICTEMENT :\n";
        $p .= "- \"Maîtrise insuffisante\"\n- \"Maîtrise fragile\"\n";
        $p .= "- \"Maîtrise satisfaisante\"\n- \"Très bonne maîtrise\"\n\n";
        $p .= "Règles importantes :\n";
        $p .= "- Rester factuel et pédagogique.\n";
        $p .= "- Ne jamais humilier l'étudiant.\n";
        $p .= "- Expliquer précisément ce qui est correct et incorrect.\n";
        $p .= "- Valoriser les éléments réussis même si la réponse est incomplète.\n";
        $p .= "- Ne jamais inventer des connaissances absentes du corrigé ou du sujet.\n";
        $p .= "- Privilégier la cohérence pédagogique.\n";
        $p .= "- Tenir compte du niveau attendu en BTS.\n";
        $p .= "- Une réponse partiellement correcte n'est pas totalement fausse.\n";
        $p .= "- Les fautes mineures de français ne pénalisent pas si les concepts techniques sont corrects.\n";
        $p .= "- Distinguer : erreur de compréhension, oubli, imprécision, confusion technique.\n\n";
        $p .= "Critères :\n";
        $p .= "- Très bonne maîtrise (80-100) : réponse complète, concepts corrects, vocabulaire maîtrisé.\n";
        $p .= "- Maîtrise satisfaisante (50-79) : notions principales comprises, quelques imprécisions.\n";
        $p .= "- Maîtrise fragile (25-49) : compréhension partielle, plusieurs oublis, erreurs techniques.\n";
        $p .= "- Maîtrise insuffisante (0-24) : hors sujet, erreurs majeures, concepts non compris.\n\n";
        $p .= "La structure de ta réponse JSON est imposée par le schéma fourni dans la requête.";

        // Existant :
        $p .= "Tu corriges le livrable d'une équipe d'étudiants BTS CIEL répondant à une demande ";
        $p .= "client. Tu joues le rôle du client « " . $project->companyname . " » : "
            . $this->persona_instruction((string)$project->personaprofile) . "\n\n";
        $p .= "Évalue le livrable sur : (1) la RÉPONSE AU BESOIN exprimé par le client, ";
        $p .= "(2) la QUALITÉ TECHNIQUE, (3) la DOCUMENTATION/clarté du rapport, ";
        $p .= "(4) la COMMUNICATION (le livrable est-il présenté comme à un client ?). ";
        $p .= "Sois bienveillant mais exigeant, et justifie tes points d'amélioration en te ";
        $p .= "référant à la demande initiale. Le barème suit le schéma JSON imposé.";
        return $p;
    }

    // =====================================================================
    //  PERSISTANCE
    // =====================================================================

    /**
     * Enregistre la mission générée (statut draft).
     *
     * @return int mission id
     */
    private function store_mission(\stdClass $project, int $sprint, array $spec,
                                   int $cmid, array $codes): int {
        global $DB;

        $competencies = '';
        if (!empty($spec['competencies']) && is_array($spec['competencies'])) {
            $competencies = implode("\n", array_map('strval', $spec['competencies']));
        }
        $m = new \stdClass();
        $m->projectid         = (int)$project->id;
        $m->sprint            = $sprint;
        $m->title             = $this->truncate((string)$spec['title'], 250);
        $m->clientrequest     = $this->ensure_html((string)$spec['clientrequest']);
        $m->rubric            = (string)$spec['rubric'];
        $m->competencies      = $competencies;
        $m->efe_competence_n1 = ($codes['n1'] !== '') ? $codes['n1'] : null;
        $m->efe_competence_n2 = ($codes['n2'] !== '') ? $codes['n2'] : null;
        $m->efe_competence_n3 = ($codes['n3'] !== '') ? $codes['n3'] : null;
        $m->assigncmid        = $cmid;
        $m->status            = 'draft';
        $m->timecreated       = time();
        return (int)$DB->insert_record(self::TABLE_MISSION, $m);
    }

    /**
     * Met à jour le dossier du projet (mémoire) + le numéro de sprint, et
     * fige l'entreprise au 1ᵉ sprint.
     */
    private function update_project_dossier(\stdClass $project, array $spec, int $sprint): void {
        global $DB;

        $dossier = json_decode((string)$project->dossier, true) ?: array();
        $dossier['history']  = $dossier['history']  ?? array();
        // Résumé concis (pas le texte intégral) pour ne pas gonfler le contexte.
        $summary = $this->truncate(strip_tags((string)($spec['dossier_update'] ?: $spec['title'])), 300);
        $dossier['history'][] = $summary;

        $update = new \stdClass();
        $update->id            = (int)$project->id;
        $update->currentsprint = $sprint;
        $update->dossier       = json_encode($dossier, JSON_UNESCAPED_UNICODE);
        $update->timemodified  = time();

        // Au 1ᵉ sprint, on fige l'entreprise inventée par le LLM.
        if ($sprint <= 1) {
            $update->companyname = $this->truncate((string)($spec['companyname'] ?: $project->companyname), 250);
            $update->sector      = $this->truncate((string)($spec['sector'] ?? ''), 250) ?: null;
            $update->persona     = (string)($spec['persona'] ?? '') ?: null;
        }
        $DB->update_record(self::TABLE_PROJECT, $update);
    }

    // =====================================================================
    //  HELPERS
    // =====================================================================

    /**
     * Ajoute une ligne horodatée au journal du job (visible sur status.php).
     */
    private function log(\stdClass $job, string $message): void {
        global $DB;
        $line = '[' . userdate(time(), '%H:%M:%S') . '] ' . $message;
        $current = (string)$DB->get_field(self::TABLE_JOB, 'log', array('id' => $job->id));
        $current = $current === '' ? $line : ($current . "\n" . $line);
        $DB->set_field(self::TABLE_JOB, 'log', $current, array('id' => $job->id));
        $DB->set_field(self::TABLE_JOB, 'timemodified', time(), array('id' => $job->id));
    }

    /**
     * Incrémente le compteur d'essais et enregistre l'erreur (avec debuginfo).
     */
    private function record_failure(int $rowid, \Throwable $e): void {
        global $DB;
        $job = $DB->get_record(self::TABLE_JOB, array('id' => $rowid));
        if (!$job) {
            return;
        }
        $msg = $e->getMessage();
        if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
            $msg .= ' — ' . $e->debuginfo;
        }
        $job->attempts     = (int)$job->attempts + 1;
        $job->lasterror    = $msg;
        $job->timemodified = time();
        $job->status       = ((int)$job->attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending';
        $DB->update_record(self::TABLE_JOB, $job);
    }

    /**
     * Garantit du HTML (enveloppe le texte brut dans des <p>).
     */
    private function ensure_html(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (strip_tags($text) === $text) {
            // Texte brut : on transforme les sauts de ligne en paragraphes.
            $parts = preg_split('/\n{2,}/', $text);
            return implode('', array_map(function($pp) {
                return '<p>' . nl2br(s(trim($pp))) . '</p>';
            }, $parts));
        }
        return $text;
    }

    /**
     * Tronque proprement une chaîne.
     */
    private function truncate(string $s, int $max): string {
        $s = trim($s);
        if (\core_text::strlen($s) <= $max) {
            return $s;
        }
        return \core_text::substr($s, 0, $max - 1) . '…';
    }
}
