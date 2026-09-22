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
 *   3. crée le DEVOIR caché, restreint au groupe (assign_factory) : correction IA
 *      pré-configurée, compétences EFE, Tuteur IA si demandé ;
 *   4. met à jour le dossier du projet et le numéro de sprint.
 *
 * Un job peut aussi ADAPTER la mission d'un autre groupe (params.adaptfrom) :
 * même besoin et même grille, réécrits pour l'entreprise du groupe.
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
    /** @var string */
    const TABLE_TICKET  = 'local_aimissions_ticket';
    /** @var int Tentatives avant de basculer un job en failed. */
    const MAX_ATTEMPTS  = 3;

    /**
     * Enfile un job dans la file partagée, éventuellement DIFFÉRÉ de $delaysec
     * secondes (réponses client différées).
     */
    public static function enqueue($jobid, $delaysec = 0) {
        $payload = new \stdClass();
        $payload->rowid = (int)$jobid;
        \local_aifeedback\task\run_job::enqueue('local_aimissions', $payload, (int)$delaysec);
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
            } else if ($job->kind === 'ticket') {
                $this->process_ticket($job);
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
        // notbefore : on ne draine PAS les jobs encore différés (réponses client).
        $rows = $DB->get_records_sql(
            'SELECT id FROM {' . self::TABLE_JOB . '}
              WHERE status = ? AND notbefore <= ?
           ORDER BY timecreated ASC',
            array('pending', time()), 0, 1);
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

        // Adaptation d'un sprint d'un autre groupe (duplication « Adapter par l'IA »).
        $source = null;
        if (!empty($params['adaptfrom'])) {
            $source = $DB->get_record(self::TABLE_MISSION, array('id' => (int)$params['adaptfrom']));
            if (!$source) {
                throw new \moodle_exception('error_adapt_nosource', 'local_aimissions');
            }
        }

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
        if ($source) {
            $this->log($job, 'Appel LLM (Agent 1) pour adapter la mission « ' . $source->title
                . ' » à cette entreprise…');
        } else {
            $this->log($job, 'Appel LLM (Agent 1) pour générer la mission…');
        }
        $spec = $this->generate_mission($project, $sprint, $params, $source);
        $spec['title']         = $this->truncate((string)$spec['title'], 250);
        $spec['clientrequest'] = $this->ensure_html((string)$spec['clientrequest']);
        $this->log($job, 'Mission reçue : « ' . $spec['title'] . ' »');

        // --- 3. Devoir caché, restreint au groupe, puis correction IA,
        //        compétences EFE et tuteur ---------------------------------
        $codes = self::job_efe_codes($params);
        $this->log($job, 'Création du devoir caché…');
        $cm = assign_factory::create((int)$job->courseid, $project, $spec, array(
            'efe_codes'    => $codes,
            'profid'       => (int)$job->userid,
            'aichat'       => !empty($params['aichat']),
            'aichatsearch' => (int)($params['aichatsearch'] ?? 0),
        ), function($line) use ($job) {
            $this->log($job, $line);
        });
        $cmid = (int)$cm->id;

        // --- 4. Enregistre la mission + met à jour le dossier -----------
        $context = (string)($params['pedagogicalcontext'] ?? ($params['module'] ?? ''));
        $missionid = $this->store_mission($project, $sprint, $spec, $cmid, $codes, $context,
            $source ? (int)$source->id : 0);
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
    //  RÉPONSES CLIENT DIFFÉRÉES (tickets asynchrones)
    // =====================================================================

    /**
     * Planifie (si besoin) la réponse différée du client pour un projet. Crée UN
     * job kind=ticket s'il n'en existe pas déjà un en attente, avec un délai
     * modulé par le persona. Les messages suivants rejoignent le même lot.
     */
    public static function schedule_reply(\stdClass $project): void {
        global $DB;
        $exists = $DB->record_exists_select(self::TABLE_JOB,
            "projectid = ? AND kind = 'ticket' AND status = 'pending'",
            array((int)$project->id));
        if ($exists) {
            return;
        }
        $delay = personas::reply_delay((string)$project->personaprofile);
        $now = time();
        $job = new \stdClass();
        $job->courseid        = (int)$project->courseid;
        $job->userid          = 0; // système
        $job->projectid       = (int)$project->id;
        $job->kind            = 'ticket';
        $job->params          = json_encode(array('projectid' => (int)$project->id), JSON_UNESCAPED_UNICODE);
        $job->status          = 'pending';
        $job->log             = '';
        $job->lasterror       = null;
        $job->resultmissionid = 0;
        $job->attempts        = 0;
        $job->notbefore       = $now + $delay;
        $job->timecreated     = $now;
        $job->timemodified    = $now;
        $job->id = (int)$DB->insert_record(self::TABLE_JOB, $job);
        self::enqueue($job->id, $delay);
    }

    /**
     * Génère la réponse différée du client à TOUS les messages en attente d'un
     * projet (le lot), pose la réponse + la réaction, met à jour l'état client.
     */
    private function process_ticket(\stdClass $job): void {
        global $DB;

        $params    = json_decode((string)$job->params, true) ?: array();
        $projectid = (int)($params['projectid'] ?? $job->projectid);
        $project   = $DB->get_record(self::TABLE_PROJECT, array('id' => $projectid));
        if (!$project) {
            throw new \moodle_exception('error_event_noproject', 'local_aimissions');
        }
        if ((string)$project->clientstatus === 'ended') {
            $this->log($job, 'Client en rupture : pas de réponse.');
            return;
        }

        $batch = array_values($DB->get_records(self::TABLE_TICKET,
            array('projectid' => $projectid, 'status' => 'pending'), 'timecreated ASC'));
        if (empty($batch)) {
            $this->log($job, 'Aucun message en attente.');
            return;
        }

        $missions = $DB->get_records(self::TABLE_MISSION,
            array('projectid' => $projectid, 'status' => 'published'), 'sprint DESC', '*', 0, 1);
        $mission = $missions ? reset($missions) : null;

        $metrics = $this->batch_metrics($batch);
        $this->log($job, 'Réponse du client à ' . count($batch) . ' message(s)…');

        $res = client::respond($project, $mission, $batch, $metrics);
        $reaction    = $res['reaction'];
        $reactionval = ($reaction === 'none') ? null : $reaction;
        $now = time();

        // Réponse sur le DERNIER message ; réaction sur TOUS (attribution).
        $last = end($batch);
        $latestid = (int)$last->id;
        foreach ($batch as $t) {
            $upd = new \stdClass();
            $upd->id           = (int)$t->id;
            $upd->status       = 'answered';
            $upd->reaction     = $reactionval;
            $upd->timeanswered = $now;
            $upd->answer       = ((int)$t->id === $latestid) ? $res['reply'] : null;
            $DB->update_record(self::TABLE_TICKET, $upd);
        }

        // État de la relation client.
        if ($reaction === 'warning') {
            $DB->set_field(self::TABLE_PROJECT, 'clientstatus', 'warned', array('id' => $projectid));
            $DB->set_field(self::TABLE_PROJECT, 'clientwarnings',
                (int)$project->clientwarnings + 1, array('id' => $projectid));
        } else if ($reaction === 'ended') {
            $DB->set_field(self::TABLE_PROJECT, 'clientstatus', 'ended', array('id' => $projectid));
        }
        $DB->set_field(self::TABLE_PROJECT, 'timemodified', $now, array('id' => $projectid));

        // Les précisions données comptent dans la correction du livrable.
        if ($mission) {
            correction_sync::sync_for_mission((int)$mission->id);
        }

        $this->log($job, 'Réponse posée (réaction : ' . $reaction . ').');
    }

    /**
     * Métriques de relance d'un lot (pour juger l'éventuel harcèlement).
     */
    private function batch_metrics(array $batch): array {
        $senders = array();
        $times = array();
        foreach ($batch as $t) {
            $senders[(int)$t->userid] = true;
            $times[] = (int)$t->timecreated;
        }
        sort($times);
        $mininterval = 0;
        for ($i = 1; $i < count($times); $i++) {
            $gap = $times[$i] - $times[$i - 1];
            if ($i === 1 || $gap < $mininterval) {
                $mininterval = $gap;
            }
        }
        return array(
            'count'       => count($batch),
            'senders'     => count($senders),
            'mininterval' => $mininterval,
            'sincefirst'  => time() - (int)(isset($times[0]) ? $times[0] : time()),
        );
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
    private function generate_mission(\stdClass $project, int $sprint, array $params,
            ?\stdClass $source = null): array {
        $system = $this->system_prompt($project, $sprint, $params);
        if ($source) {
            $system .= "\n\n" . $this->adaptation_rules($sprint);
        }
        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => $this->user_prompt($project, $sprint, $params, $source)),
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
        // « module » : ancien champ « Module / matière », jobs mis en file avant 0.8.0.
        $context    = trim((string)($params['pedagogicalcontext'] ?? ($params['module'] ?? '')));
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
        if ($context !== '') {
            $p .= "\nCONTEXTE PÉDAGOGIQUE DONNÉ PAR L'ENSEIGNANT (à respecter) :\n" . $context . "\n";
            $p .= "Les choix technologiques que ce contexte IMPOSE font exception à la règle ci-dessus : ";
            $p .= "le client les exprime comme une contrainte de son entreprise (« notre service ";
            $p .= "informatique impose… », « nous travaillons déjà avec… »), sans jamais en faire la ";
            $p .= "solution ni expliquer comment s'en servir.\n\n";
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
    private function user_prompt(\stdClass $project, int $sprint, array $params,
            ?\stdClass $source = null): string {
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
        if ($source) {
            $u .= "\nMISSION D'ORIGINE À ADAPTER (rédigée pour une autre entreprise) :\n";
            $u .= 'Titre : ' . $source->title . "\n";
            $u .= "Demande client :\n"
                . \core_text::substr(trim(html_to_text((string)$source->clientrequest, 0, false)), 0, 6000) . "\n";
            $u .= "Grille d'évaluation :\n" . \core_text::substr(trim((string)$source->rubric), 0, 4000) . "\n";
            if (trim((string)$source->competencies) !== '') {
                $u .= "Compétences évaluées :\n" . trim((string)$source->competencies) . "\n";
            }
            $u .= "\nGénère la demande client du sprint n°" . $sprint
                . " : la même mission, adaptée à l'entreprise de ce projet.";
            return $u;
        }
        $u .= "\nGénère la demande client du sprint n°" . $sprint . ".";
        return $u;
    }

    /**
     * Consignes de la duplication « Adapter par l'IA » : même mission, autre
     * entreprise. Les textes diffèrent d'un groupe à l'autre, le travail
     * demandé et la grille restent équivalents.
     */
    private function adaptation_rules(int $sprint): string {
        $p  = "=== ADAPTATION D'UNE MISSION EXISTANTE ===\n";
        $p .= "L'enseignant réutilise pour ce groupe une mission déjà rédigée pour une AUTRE ";
        $p .= "entreprise (fournie dans le message). Réécris-la pour l'entreprise de ce projet : ";
        $p .= "mêmes besoins fonctionnels, mêmes contraintes, même difficulté, même périmètre et ";
        $p .= "mêmes critères d'évaluation (la grille « rubric » reste équivalente, dans le ";
        $p .= "vocabulaire de cette entreprise ; les compétences restent les mêmes). Change le ";
        $p .= "contexte métier, les noms, les exemples et les données pour qu'ils correspondent à ";
        $p .= "l'entreprise ; ne reprends jamais le nom ni les détails de l'entreprise d'origine.\n";
        if ($sprint <= 1) {
            $p .= "Le projet de ce groupe est neuf : invente son entreprise (nom, secteur, contact), ";
            $p .= "dans un domaine où ce besoin a du sens, différente de l'entreprise d'origine.\n";
        }
        return $p;
    }

    /**
     * Codes EFE d'un job : liste (0.8.0), ou ancien trio N1/N2/N3 des jobs mis
     * en file avant (la compétence effective d'EFE : N3, sinon N2, sinon N1).
     *
     * @return string[]
     */
    private static function job_efe_codes(array $params): array {
        if (isset($params['efe_codes']) && is_array($params['efe_codes'])) {
            return assign_factory::clean_codes($params['efe_codes']);
        }
        foreach (array('efe_n3', 'efe_n2', 'efe_n1') as $key) {
            $code = trim((string)($params[$key] ?? ''));
            if ($code !== '') {
                return array($code);
            }
        }
        return array();
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
    //  PERSISTANCE
    // =====================================================================

    /**
     * Enregistre la mission générée (statut draft).
     *
     * @param string[] $codes     compétences EFE rattachées au devoir
     * @param string   $context   contexte pédagogique (réutilisé par la duplication)
     * @param int      $sourceid  mission d'origine (adaptation), 0 sinon
     * @return int mission id
     */
    private function store_mission(\stdClass $project, int $sprint, array $spec,
                                   int $cmid, array $codes, string $context = '', int $sourceid = 0): int {
        global $DB;

        $m = new \stdClass();
        $m->projectid          = (int)$project->id;
        $m->sprint             = $sprint;
        $m->title              = $this->truncate((string)$spec['title'], 250);
        $m->clientrequest      = $this->ensure_html((string)$spec['clientrequest']);
        $m->rubric             = (string)$spec['rubric'];
        $m->competencies       = assign_factory::competencies_text($spec['competencies'] ?? array());
        $m->pedagogicalcontext = (trim($context) !== '') ? $context : null;
        $m->efe_competences    = !empty($codes) ? json_encode(array_values($codes), JSON_UNESCAPED_UNICODE) : null;
        // Ancien format (une compétence) : la première, pour les lecteurs d'avant 0.8.0.
        $m->efe_competence_n1  = !empty($codes) ? $codes[0] : null;
        $m->efe_competence_n2  = null;
        $m->efe_competence_n3  = null;
        $m->sourcemissionid    = $sourceid;
        $m->assigncmid         = $cmid;
        $m->status             = 'draft';
        $m->timecreated        = time();
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

    /** Garantit du HTML (enveloppe le texte brut dans des <p>). */
    private function ensure_html(string $text): string {
        return assign_factory::ensure_html($text);
    }

    /** Tronque proprement une chaîne. */
    private function truncate(string $s, int $max): string {
        return assign_factory::truncate($s, $max);
    }
}
