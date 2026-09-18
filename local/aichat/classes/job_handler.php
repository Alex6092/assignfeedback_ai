<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de la file de jobs partagée (local_aifeedback) pour la génération du
 * brief pédagogique.
 *
 * Les conversations du tuteur, elles, ne passent PAS par cette file : elles
 * sont interactives et empruntent le pool de serveurs (voir stream.php).
 */
class job_handler implements \local_aifeedback\job_handler {

    public function execute(\stdClass $payload): void {
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

    public function find_drainable_payloads(): array {
        global $DB;
        $rows = $DB->get_records('local_aichat_activity',
            array('briefstatus' => 'pending'), 'timemodified ASC', 'id, cmid', 0, 1);
        if (empty($rows)) {
            return array();
        }
        $first   = reset($rows);
        $payload = new \stdClass();
        $payload->cmid = (int)$first->cmid;
        return array($payload);
    }
}
