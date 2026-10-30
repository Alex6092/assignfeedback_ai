<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Moteur de génération des missions client (Agent 1 — « Product Owner »),
 * des messages spontanés du client, de ses réponses différées (Agent 2) et
 * des évaluations de communication.
 *
 * Implémente \local_aifeedback\async_handler : chaque job ne demande qu'UNE
 * génération, confiée au load balancer comme job asynchrone ; son résultat
 * est appliqué par complete() quand il revient.
 *
 * Pour une mission (un groupe, un sprint) :
 *   1. charge/crée le projet (l'entreprise fictive persistante du groupe) ;
 *   2. demande au LLM la mission suivante (cohérente, progressive, ton du
 *      persona) — schéma JSON strict ;
 *   3. au retour : crée le DEVOIR caché, restreint au groupe (assign_factory) :
 *      correction IA pré-configurée, compétences EFE, Tuteur IA si demandé ;
 *   4. met à jour le dossier du projet et le numéro de sprint.
 *
 * Un job peut aussi ADAPTER la mission d'un autre groupe (params.adaptfrom) :
 * même besoin et même grille, réécrits pour l'entreprise du groupe.
 *
 * Le contexte d'une génération (sprint, mission en cours, lot de tickets…)
 * est figé dans params à la préparation : le résultat s'applique à ce qui a
 * été demandé, même si d'autres messages sont arrivés entre-temps.
 */
class job_handler implements \local_aifeedback\async_handler {

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
    /** @var string */
    const TABLE_COMMEVAL = 'local_aimissions_commeval';
    /** @var int Tentatives avant de basculer un job en failed. */
    const MAX_ATTEMPTS  = 3;
    /** @var int Délai avant un nouvel essai (secondes). */
    const RETRY_DELAY   = 120;

    /**
     * Met un job en file, éventuellement DIFFÉRÉ de $delaysec secondes
     * (réponses client différées).
     */
    public static function enqueue($jobid, $delaysec = 0) {
        \local_aifeedback\jobs::enqueue('local_aimissions', (object)array('rowid' => (int)$jobid), (int)$delaysec);
    }

    public function build_request(\stdClass $payload): ?\local_aifeedback\request {
        global $DB;

        $job = $this->open_job($payload);
        if ($job === null) {
            return null;
        }
        // Réponse client pas encore due (job remis en file trop tôt) : on
        // la replanifie à son heure.
        if ((int)$job->notbefore > time() && $job->status === 'pending') {
            \local_aifeedback\task\run_job::queue('local_aimissions', $payload, (int)$job->notbefore - time());
            return null;
        }

        $params = json_decode((string)$job->params, true) ?: array();
        switch ($job->kind) {
            case 'event':
                $spec = $this->event_request($job, $params);
                break;
            case 'ticket':
                $spec = $this->ticket_request($job, $params);
                break;
            case 'commeval':
                $spec = $this->commeval_request($job, $params);
                break;
            default:
                $spec = $this->mission_request($job, $params);
        }
        if ($spec === null) {
            $this->finish($job); // plus rien à générer
            return null;
        }

        // Contexte figé + passage en running (visible sur la page de statut).
        $DB->update_record(self::TABLE_JOB, (object)array(
            'id'           => (int)$job->id,
            'params'       => json_encode($params, JSON_UNESCAPED_UNICODE),
            'status'       => 'running',
            'timemodified' => time(),
        ));
        return new \local_aifeedback\request($spec['messages'], $spec['options'],
            \local_aifeedback\models::for_component('local_aimissions'), (int)$job->userid);
    }

    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        global $DB;

        $job = $this->open_job($payload);
        if ($job === null) {
            return null;
        }
        $params = json_decode((string)$job->params, true) ?: array();
        switch ($job->kind) {
            case 'event':
                $this->event_complete($job, $params, $result);
                break;
            case 'ticket':
                $this->ticket_complete($job, $params, $result);
                break;
            case 'commeval':
                $this->commeval_complete($job, $params, $result);
                break;
            default:
                $this->mission_complete($job, $params, $result);
        }
        $this->finish($job);
        return null;
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        global $DB;

        $job = $this->open_job($payload);
        if ($job === null) {
            return null;
        }
        $job->attempts     = (int)$job->attempts + 1;
        $job->lasterror    = $error;
        $job->timemodified = time();
        $job->status       = ($permanent || (int)$job->attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending';
        if ($job->kind === 'ticket') {
            // Nouvel essai : le lot sera relu, messages arrivés depuis compris.
            $params = json_decode((string)$job->params, true) ?: array();
            unset($params['_batch'], $params['_metrics']);
            $job->params = json_encode($params, JSON_UNESCAPED_UNICODE);
        }
        $DB->update_record(self::TABLE_JOB, $job);
        $this->log($job, 'ÉCHEC : ' . $error);

        if ($job->status === 'failed' && $job->kind === 'commeval') {
            $params = json_decode((string)$job->params, true) ?: array();
            $DB->set_field_select(self::TABLE_COMMEVAL, 'status', 'failed', 'projectid = ? AND userid = ?',
                array((int)($params['projectid'] ?? 0), (int)($params['userid'] ?? 0)));
        }
        return ($job->status === 'pending') ? self::RETRY_DELAY : null;
    }

    /**
     * Jobs en attente sans génération en cours (mise en file perdue). Les
     * réponses client différées ne sont reprises qu'une fois dues.
     */
    public function pending_payloads(int $before): array {
        global $DB;
        $ids = $DB->get_fieldset_select(self::TABLE_JOB, 'id',
            'status IN (?, ?) AND notbefore <= ? AND timemodified < ?',
            array('pending', 'running', time(), $before));
        $out = array();
        foreach (array_slice($ids, 0, 50) as $id) {
            $out[] = (object)array('rowid' => (int)$id);
        }
        return $out;
    }

    /**
     * Job encore à traiter (en attente, ou en cours de génération), ou null.
     */
    private function open_job(\stdClass $payload) {
        global $DB;
        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return null;
        }
        $job = $DB->get_record(self::TABLE_JOB, array('id' => $rowid));
        return ($job && in_array($job->status, array('pending', 'running'), true)) ? $job : null;
    }

    /** Job terminé avec succès. */
    private function finish(\stdClass $job): void {
        global $DB;
        $DB->update_record(self::TABLE_JOB, (object)array(
            'id'           => (int)$job->id,
            'status'       => 'done',
            'lasterror'    => null,
            'timemodified' => time(),
        ));
    }

    // =====================================================================
    //  ORCHESTRATION
    // =====================================================================

    /**
     * Demande de la mission du sprint suivant pour le projet/groupe du job.
     * Le sprint visé est figé dans $params['_sprint'].
     */
    private function mission_request(\stdClass $job, array &$params): ?array {
        global $DB;

        // Adaptation d'un sprint d'un autre groupe (duplication « Adapter par l'IA »).
        $source = $this->adapt_source($params);

        // --- 1. Projet (entreprise fictive du groupe) -------------------
        $project = $this->load_or_create_project($job, $params);
        if (!isset($params['_sprint'])) {
            $this->log($job, 'Projet : « ' . $project->companyname . ' » (groupe '
                . (int)$project->groupid . ', sprint à venir n°' . ((int)$project->currentsprint + 1) . ')');
            $params['_sprint'] = (int)$project->currentsprint + 1;
        }
        $sprint = (int)$params['_sprint'];
        $maxsprints = (int)(get_config('local_aimissions', 'maxsprints') ?: 20);
        if ($sprint > $maxsprints) {
            throw new \moodle_exception('error_maxsprints', 'local_aimissions');
        }

        // --- 2. Demande au LLM : la mission suivante ----------------------
        if ($source) {
            $this->log($job, 'Demande au serveur IA (Agent 1) : adapter la mission « ' . $source->title
                . ' » à cette entreprise…');
        } else {
            $this->log($job, 'Demande au serveur IA (Agent 1) : générer la mission…');
        }
        return $this->mission_messages($project, $sprint, $params, $source);
    }

    /**
     * Applique la mission reçue : devoir caché, mission, dossier du projet.
     */
    private function mission_complete(\stdClass $job, array $params, array $spec): void {
        global $DB;

        if (!$this->is_valid_spec($spec)) {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }
        $source  = $this->adapt_source($params);
        $project = $this->load_or_create_project($job, $params);
        $sprint  = (int)($params['_sprint'] ?? ((int)$project->currentsprint + 1));

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
     * Mission source d'une adaptation (params.adaptfrom), ou null.
     */
    private function adapt_source(array $params) {
        global $DB;
        if (empty($params['adaptfrom'])) {
            return null;
        }
        $source = $DB->get_record(self::TABLE_MISSION, array('id' => (int)$params['adaptfrom']));
        if (!$source) {
            throw new \moodle_exception('error_adapt_nosource', 'local_aimissions');
        }
        return $source;
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
     * Demande d'une communication client (événement) pour un projet existant.
     * La mission en cours est figée dans $params['_missionid'].
     */
    private function event_request(\stdClass $job, array &$params): ?array {
        global $DB;

        $project = $this->event_project($job, $params);
        $type    = (string)($params['eventtype'] ?? 'besoin');
        $hint    = (string)($params['hint'] ?? '');

        if (!isset($params['_missionid'])) {
            $this->log($job, 'Génération d\'un événement « ' . $type . ' » pour « '
                . $project->companyname . ' »…');
            $missions = $DB->get_records(self::TABLE_MISSION,
                array('projectid' => (int)$project->id, 'status' => 'published'), 'sprint DESC', '*', 0, 1);
            $params['_missionid'] = $missions ? (int)reset($missions)->id : 0;
        }
        $mission = $params['_missionid']
            ? $DB->get_record(self::TABLE_MISSION, array('id' => (int)$params['_missionid'])) : null;

        return array(
            'messages' => array(
                array('role' => 'system', 'content' => $this->event_system_prompt($project, $mission ?: null)),
                array('role' => 'user',   'content' => $this->event_user_prompt($type, $hint)),
            ),
            'options'  => array('temperature' => 0.6, 'max_tokens' => 700),
        );
    }

    /**
     * Enregistre l'événement reçu, en attente de publication (revue enseignant).
     */
    private function event_complete(\stdClass $job, array $params, array $result): void {
        global $DB;

        $project = $this->event_project($job, $params);
        $body    = trim((string)($result['__text__'] ?? ''));
        if ($body === '') {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }

        $ev = new \stdClass();
        $ev->projectid   = (int)$project->id;
        $ev->missionid   = (int)($params['_missionid'] ?? 0);
        $ev->type        = (string)($params['eventtype'] ?? 'besoin');
        $ev->body        = $body;
        $ev->applied     = 0; // en attente de publication
        $ev->timecreated = time();
        $evid = (int)$DB->insert_record(self::TABLE_EVENT, $ev);

        $this->log($job, 'Événement #' . $evid . ' généré (en attente de publication).');
    }

    /** Projet d'un job d'événement ou de réponse client. */
    private function event_project(\stdClass $job, array $params): \stdClass {
        global $DB;
        $projectid = (int)($params['projectid'] ?? $job->projectid);
        $project   = $DB->get_record(self::TABLE_PROJECT, array('id' => $projectid));
        if (!$project) {
            throw new \moodle_exception('error_event_noproject', 'local_aimissions');
        }
        return $project;
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
     * Demande de la réponse différée du client à TOUS les messages en attente
     * d'un projet (le lot). Le lot et ses métriques sont figés dans params.
     */
    private function ticket_request(\stdClass $job, array &$params): ?array {
        global $DB;

        $project = $this->event_project($job, $params);
        if ((string)$project->clientstatus === 'ended') {
            $this->log($job, 'Client en rupture : pas de réponse.');
            return null;
        }

        if (!isset($params['_batch'])) {
            // Messages déjà confiés à une réponse en cours de génération : ils
            // ne font pas partie de ce lot.
            $taken = array();
            $others = $DB->get_records_select(self::TABLE_JOB, 'projectid = ? AND kind = ? AND status = ? AND id <> ?',
                array((int)$project->id, 'ticket', 'running', (int)$job->id), '', 'id, params');
            foreach ($others as $other) {
                $op = json_decode((string)$other->params, true) ?: array();
                foreach ((array)($op['_batch'] ?? array()) as $id) {
                    $taken[(int)$id] = true;
                }
            }
            $batch = array_values(array_filter($DB->get_records(self::TABLE_TICKET,
                array('projectid' => (int)$project->id, 'status' => 'pending'), 'timecreated ASC'),
                function($t) use ($taken) {
                    return !isset($taken[(int)$t->id]);
                }));
            if (empty($batch)) {
                $this->log($job, 'Aucun message en attente.');
                return null;
            }
            $missions = $DB->get_records(self::TABLE_MISSION,
                array('projectid' => (int)$project->id, 'status' => 'published'), 'sprint DESC', '*', 0, 1);
            $params['_batch']     = array_map(function($t) {
                return (int)$t->id;
            }, $batch);
            $params['_metrics']   = $this->batch_metrics($batch);
            $params['_missionid'] = $missions ? (int)reset($missions)->id : 0;
            $this->log($job, 'Réponse du client à ' . count($batch) . ' message(s)…');
        }

        $batch = $this->ticket_batch($params);
        if (empty($batch)) {
            return null;
        }
        $mission = !empty($params['_missionid'])
            ? $DB->get_record(self::TABLE_MISSION, array('id' => (int)$params['_missionid'])) : null;
        return client::request($project, $mission ?: null, $batch, (array)$params['_metrics']);
    }

    /**
     * Pose la réponse du client sur le lot demandé, puis met à jour l'état de
     * la relation client.
     */
    private function ticket_complete(\stdClass $job, array $params, array $result): void {
        global $DB;

        $project = $this->event_project($job, $params);
        $res     = client::parse($result);
        $batch   = $this->ticket_batch($params);
        if (empty($batch)) {
            return;
        }
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
        $projectid = (int)$project->id;
        if ($reaction === 'warning') {
            $DB->set_field(self::TABLE_PROJECT, 'clientstatus', 'warned', array('id' => $projectid));
            $DB->set_field(self::TABLE_PROJECT, 'clientwarnings',
                (int)$project->clientwarnings + 1, array('id' => $projectid));
        } else if ($reaction === 'ended') {
            $DB->set_field(self::TABLE_PROJECT, 'clientstatus', 'ended', array('id' => $projectid));
        }
        $DB->set_field(self::TABLE_PROJECT, 'timemodified', $now, array('id' => $projectid));

        // Les précisions données comptent dans la correction du livrable.
        if (!empty($params['_missionid'])) {
            correction_sync::sync_for_mission((int)$params['_missionid']);
        }

        $this->log($job, 'Réponse posée (réaction : ' . $reaction . ').');

        // Messages arrivés pendant la génération : ils auront leur réponse.
        if ($DB->record_exists(self::TABLE_TICKET, array('projectid' => $projectid, 'status' => 'pending'))) {
            self::schedule_reply($DB->get_record(self::TABLE_PROJECT, array('id' => $projectid)));
        }
    }

    /**
     * Tickets du lot figé encore sans réponse, dans l'ordre.
     *
     * @return \stdClass[]
     */
    private function ticket_batch(array $params): array {
        global $DB;
        $ids = isset($params['_batch']) ? array_map('intval', (array)$params['_batch']) : array();
        if (empty($ids)) {
            return array();
        }
        list($insql, $inparams) = $DB->get_in_or_equal($ids);
        return array_values($DB->get_records_select(self::TABLE_TICKET,
            "id $insql AND status = ?", array_merge($inparams, array('pending')), 'timecreated ASC'));
    }

    // =====================================================================
    //  ÉVALUATION DE LA COMMUNICATION CLIENT (un job par étudiant)
    // =====================================================================

    /**
     * Met en file l'évaluation de la communication d'un étudiant.
     *
     * @param \stdClass $project
     * @param int       $userid  étudiant évalué
     * @param int       $teacherid enseignant qui lance l'évaluation
     * @param string    $efecode compétence EFE visée
     */
    public static function schedule_commeval(\stdClass $project, int $userid, int $teacherid, string $efecode): void {
        global $DB;
        $now = time();

        $existing = $DB->get_record(self::TABLE_COMMEVAL,
            array('projectid' => (int)$project->id, 'userid' => $userid));
        $rec = $existing ?: new \stdClass();
        $rec->projectid    = (int)$project->id;
        $rec->userid       = $userid;
        $rec->efecode      = $efecode;
        $rec->status       = 'pending';
        $rec->timemodified = $now;
        if ($existing) {
            $DB->update_record(self::TABLE_COMMEVAL, $rec);
        } else {
            $rec->score       = 0;
            $rec->timecreated = $now;
            $DB->insert_record(self::TABLE_COMMEVAL, $rec);
        }

        $job = new \stdClass();
        $job->courseid        = (int)$project->courseid;
        $job->userid          = $teacherid;
        $job->projectid       = (int)$project->id;
        $job->kind            = 'commeval';
        $job->params          = json_encode(array('projectid' => (int)$project->id, 'userid' => $userid),
            JSON_UNESCAPED_UNICODE);
        $job->status          = 'pending';
        $job->log             = '';
        $job->lasterror       = null;
        $job->resultmissionid = 0;
        $job->attempts        = 0;
        $job->notbefore       = 0;
        $job->timecreated     = $now;
        $job->timemodified    = $now;
        $job->id = (int)$DB->insert_record(self::TABLE_JOB, $job);
        self::enqueue($job->id);
    }

    /**
     * Demande d'évaluation de la communication d'un étudiant, à partir de ses
     * tickets. Les incidents (recadrage, rupture) sont figés dans params.
     */
    private function commeval_request(\stdClass $job, array &$params): ?array {
        global $DB;

        $project = $this->event_project($job, $params);
        $tickets = $DB->get_records(self::TABLE_TICKET,
            array('projectid' => (int)$project->id, 'userid' => (int)($params['userid'] ?? 0)), 'timecreated ASC');
        try {
            $spec = communication::request($project, array_values($tickets));
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'comm_notickets') {
                $DB->set_field_select(self::TABLE_COMMEVAL, 'status', 'failed', 'projectid = ? AND userid = ?',
                    array((int)$project->id, (int)($params['userid'] ?? 0)));
                $this->log($job, $e->getMessage());
                return null;
            }
            throw $e;
        }
        $params['_flags'] = $spec['flags'];
        return $spec;
    }

    /**
     * Enregistre l'évaluation (brouillon, à relire puis envoyer vers EFE).
     */
    private function commeval_complete(\stdClass $job, array $params, array $result): void {
        global $DB;

        $eval = communication::parse($result, (array)($params['_flags'] ?? array()));
        $rec  = $DB->get_record(self::TABLE_COMMEVAL,
            array('projectid' => (int)($params['projectid'] ?? 0), 'userid' => (int)($params['userid'] ?? 0)));
        if (!$rec) {
            return; // évaluation supprimée entre-temps
        }
        $rec->colour       = $eval['colour'];
        $rec->score        = $eval['score'];
        $rec->comment      = $eval['comment'];
        $rec->status       = 'draft';
        $rec->timemodified = time();
        $DB->update_record(self::TABLE_COMMEVAL, $rec);
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
     * Demande au LLM la mission du sprint, en lui fournissant le dossier
     * projet. La spec renvoyée (title, clientrequest, rubric, competencies[],
     * deliverables[], companyname, sector, persona, dossier_update) est
     * validée au retour (is_valid_spec).
     *
     * @return array {messages, options}
     */
    private function mission_messages(\stdClass $project, int $sprint, array $params,
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
        return array('messages' => $messages, 'options' => $options);
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

    /** Garantit du HTML (enveloppe le texte brut dans des <p>). */
    private function ensure_html(string $text): string {
        return assign_factory::ensure_html($text);
    }

    /** Tronque proprement une chaîne. */
    private function truncate(string $s, int $max): string {
        return assign_factory::truncate($s, $max);
    }
}
