<?php
namespace assignfeedback_ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Implémentation du contrat \local_aifeedback\job_handler pour les feedbacks
 * de devoirs. Le routage depuis la queue partagée se fait par nom de
 * composant : 'assignfeedback_ai' → \assignfeedback_ai\job_handler.
 */
class job_handler implements \local_aifeedback\job_handler {

    /** Nombre max de tentatives avant de basculer une ligne en status=failed. */
    const MAX_ATTEMPTS = 3;

    /**
     * Enqueue un job pour traiter la ligne $rowid de mdl_assignfeedback_ai_grade.
     */
    public static function enqueue($rowid) {
        $payload = new \stdClass();
        $payload->rowid = (int)$rowid;
        \local_aifeedback\task\run_job::enqueue('assignfeedback_ai', $payload);
    }

    public function execute(\stdClass $payload): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return;
        }

        $fb = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
        if (!$fb) {
            return; // ligne supprimée entre-temps
        }
        if ($fb->status !== \assign_feedback_ai::STATUS_PENDING) {
            return; // déjà traitée
        }

        try {
            list($course, $cm) = get_course_and_cm_from_instance($fb->assignment, 'assign');
            $context = \context_module::instance($cm->id);
            $assign  = new \assign($context, $cm, $course);
            $plugin  = new \assign_feedback_ai($assign, 'ai');
            $plugin->process_feedback_row($rowid);
        } catch (\Throwable $e) {
            $this->record_failure($rowid, $e->getMessage());
            $current = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
            if ($current && (int)$current->attempts < self::MAX_ATTEMPTS) {
                self::enqueue($rowid);
            }
            // Propage pour que le dispatcher arrête le drainage (LLM peut-être down).
            throw $e;
        }
    }

    public function find_drainable_payloads(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id FROM {' . \assign_feedback_ai::TABLE_GRADE . '}
             WHERE status = ?
             ORDER BY timecreated ASC',
            array(\assign_feedback_ai::STATUS_PENDING),
            0, 1
        );
        if (empty($rows)) {
            return array();
        }
        $first = reset($rows);
        $payload = new \stdClass();
        $payload->rowid = (int)$first->id;
        return array($payload);
    }

    private function record_failure($rowid, $errmsg) {
        global $DB;

        $fb = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
        if (!$fb) {
            return;
        }
        $fb->attempts      = (int)$fb->attempts + 1;
        $fb->error_message = (string)$errmsg;
        $fb->timemodified  = time();
        $fb->status        = ((int)$fb->attempts >= self::MAX_ATTEMPTS)
            ? \assign_feedback_ai::STATUS_FAILED
            : \assign_feedback_ai::STATUS_PENDING;
        $DB->update_record(\assign_feedback_ai::TABLE_GRADE, $fb);
    }
}
