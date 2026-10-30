<?php
namespace assignfeedback_ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de la file partagée (\local_aifeedback\jobs) pour les feedbacks de
 * devoirs. Le routage se fait par nom de composant :
 * 'assignfeedback_ai' → \assignfeedback_ai\job_handler.
 *
 * La génération est confiée au load balancer (job asynchrone) ; le résultat
 * revient par callback et est appliqué par complete(). Un devoir configuré
 * sur une API externe est corrigé directement par celle-ci.
 */
class job_handler implements \local_aifeedback\async_handler {

    /** Nombre max de tentatives avant de basculer une ligne en status=failed. */
    const MAX_ATTEMPTS = 3;

    /** Délai avant un nouvel essai après un échec (secondes). */
    const RETRY_DELAY = 120;

    /**
     * Met en file la correction de la ligne $rowid de mdl_assignfeedback_ai_grade.
     *
     * @param int  $rowid
     * @param bool $restart remplacer une correction en cours (copie modifiée, relance)
     */
    public static function enqueue($rowid, $restart = false) {
        $payload = (object)array('rowid' => (int)$rowid);
        if ($restart) {
            \local_aifeedback\jobs::restart('assignfeedback_ai', $payload);
        } else {
            \local_aifeedback\jobs::enqueue('assignfeedback_ai', $payload);
        }
    }

    public function build_request(\stdClass $payload): ?\local_aifeedback\request {
        $plugin = $this->plugin_for($payload);
        return ($plugin === null) ? null : $plugin->build_feedback_request((int)$payload->rowid);
    }

    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        $plugin = $this->plugin_for($payload);
        if ($plugin !== null) {
            $plugin->complete_feedback_row((int)$payload->rowid, $result);
        }
        return null;
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        $fb    = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
        if (!$fb || $fb->status !== \assign_feedback_ai::STATUS_PENDING) {
            return null;
        }
        $fb->attempts      = (int)$fb->attempts + 1;
        $fb->error_message = (string)$error;
        $fb->timemodified  = time();
        $fb->status        = ($permanent || (int)$fb->attempts >= self::MAX_ATTEMPTS)
            ? \assign_feedback_ai::STATUS_FAILED
            : \assign_feedback_ai::STATUS_PENDING;
        $DB->update_record(\assign_feedback_ai::TABLE_GRADE, $fb);

        return ($fb->status === \assign_feedback_ai::STATUS_PENDING) ? self::RETRY_DELAY : null;
    }

    public function pending_payloads(int $before): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        $ids = $DB->get_fieldset_select(\assign_feedback_ai::TABLE_GRADE, 'id',
            'status = ? AND timemodified < ?', array(\assign_feedback_ai::STATUS_PENDING, $before));
        $out = array();
        foreach (array_slice($ids, 0, 50) as $id) {
            $out[] = (object)array('rowid' => (int)$id);
        }
        return $out;
    }

    /**
     * Plugin de feedback du devoir de la ligne, si elle est encore à traiter.
     *
     * @param \stdClass $payload {rowid}
     * @return \assign_feedback_ai|null
     */
    private function plugin_for(\stdClass $payload) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return null;
        }
        $fb = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
        if (!$fb || $fb->status !== \assign_feedback_ai::STATUS_PENDING) {
            return null; // ligne supprimée entre-temps, ou déjà traitée
        }

        list($course, $cm) = get_course_and_cm_from_instance($fb->assignment, 'assign');
        $context = \context_module::instance($cm->id);
        $assign  = new \assign($context, $cm, $course);
        return new \assign_feedback_ai($assign, 'ai');
    }
}
