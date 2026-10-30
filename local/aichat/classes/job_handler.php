<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de la file partagée (local_aifeedback) pour les traitements
 * différés du tuteur, confiés au load balancer comme jobs asynchrones :
 *   - {messageid} : analyse de modération d'un message d'élève ;
 *   - {cmid}      : génération du brief pédagogique d'une activité.
 *
 * Les conversations elles-mêmes ne passent PAS par cette file : elles sont
 * interactives et utilisent le mode streaming du load balancer (stream.php).
 */
class job_handler implements \local_aifeedback\async_handler {

    public function build_request(\stdClass $payload): ?\local_aifeedback\request {
        if (!empty($payload->messageid)) {
            return moderation::build_request((int)$payload->messageid);
        }
        $cmid = isset($payload->cmid) ? (int)$payload->cmid : 0;
        return ($cmid > 0) ? brief::build_request($cmid) : null;
    }

    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        if (!empty($payload->messageid)) {
            moderation::complete((int)$payload->messageid, $result);
        } else if (!empty($payload->cmid)) {
            brief::complete((int)$payload->cmid, $result);
        }
        return null;
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        if (!empty($payload->messageid)) {
            return moderation::fail((int)$payload->messageid, $permanent);
        }
        if (!empty($payload->cmid)) {
            return brief::fail((int)$payload->cmid, $error, $permanent);
        }
        return null;
    }

    /**
     * Analyses et briefs restés en attente sans job (mise en file perdue).
     * Les analyses en « retry » attendent leur tâche différée : elles ne sont
     * reprises que si elles attendent depuis longtemps.
     */
    public function pending_payloads(int $before): array {
        global $DB;
        $out = array();

        $ids = $DB->get_fieldset_select(conversation::TABLE_MSG, 'id',
            'role = ? AND flagstatus IN (?, ?) AND timecreated < ? AND (flagtime = 0 OR flagtime < ?)',
            array('user', 'pending', 'retry', $before, $before - moderation::RETRY_DELAY));
        foreach (array_slice($ids, 0, 50) as $id) {
            $out[] = (object)array('messageid' => (int)$id);
        }

        $cmids = $DB->get_fieldset_select('local_aichat_activity', 'cmid',
            'briefstatus = ? AND timemodified < ?', array('pending', $before));
        foreach (array_slice($cmids, 0, 20) as $cmid) {
            $out[] = (object)array('cmid' => (int)$cmid);
        }
        return $out;
    }
}
