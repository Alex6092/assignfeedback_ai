<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de la file de jobs partagée (local_aifeedback) pour les traitements
 * différés du tuteur :
 *   - {messageid} : analyse de modération d'un message d'élève ;
 *   - {cmid}      : génération du brief pédagogique d'une activité.
 *
 * Les conversations elles-mêmes ne passent PAS par cette file : elles sont
 * interactives et empruntent le pool de serveurs (voir stream.php).
 */
class job_handler implements \local_aifeedback\job_handler {

    public function execute(\stdClass $payload): void {
        if (!empty($payload->messageid)) {
            moderation::analyse((int)$payload->messageid);
            return;
        }

        $cmid = isset($payload->cmid) ? (int)$payload->cmid : 0;
        if ($cmid <= 0) {
            return;
        }
        $config = activity::get($cmid);
        if ($config === null || $config->briefstatus !== 'pending') {
            return; // déjà traité, ou activité reconfigurée entre-temps
        }
        brief::generate($cmid);
    }

    /**
     * Prochain travail en attente, pour le drainage de la file. Les analyses
     * passent avant les briefs : un signalement doit arriver vite, un brief
     * peut attendre. Les analyses en « retry » ne sont PAS reprises ici (elles
     * attendent leur tâche différée), ce qui évite de reboucler sur un serveur
     * indisponible.
     */
    public function find_drainable_payloads(): array {
        global $DB;

        $rows = $DB->get_records(conversation::TABLE_MSG,
            array('flagstatus' => 'pending'), 'id ASC', 'id', 0, 1);
        if (!empty($rows)) {
            $payload = new \stdClass();
            $payload->messageid = (int)reset($rows)->id;
            return array($payload);
        }

        $rows = $DB->get_records('local_aichat_activity',
            array('briefstatus' => 'pending'), 'timemodified ASC', 'id, cmid', 0, 1);
        if (!empty($rows)) {
            $payload = new \stdClass();
            $payload->cmid = (int)reset($rows)->cmid;
            return array($payload);
        }
        return array();
    }
}
