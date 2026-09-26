<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Notification des enseignants quand une tentative remise déclenche un
 * indicateur (appelé par la tâche ad hoc analyse_item, après la remise).
 */
class notifier {

    /** Notifications activées sur le site ? (par défaut : oui) */
    public static function enabled(): bool {
        $value = get_config('local_attemptcheck', 'notify');
        return $value === false || !empty($value);
    }

    /**
     * Analyse une tentative et avertit les enseignants si besoin.
     *
     * @param string $type quiz|assign
     * @param int    $id   quiz_attempts.id ou assign_submission.id
     */
    public static function check(string $type, int $id): void {
        global $DB;
        if (!self::enabled()) {
            return;
        }
        if ($type === 'quiz') {
            $row = $DB->get_record_sql("SELECT qa.id, qa.preview, q.id AS instance, q.course
                                          FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz
                                         WHERE qa.id = ?", array($id));
            if (!$row || !empty($row->preview)) {
                return;
            }
        } else {
            $row = $DB->get_record_sql("SELECT s.id, a.id AS instance, a.course
                                          FROM {assign_submission} s JOIN {assign} a ON a.id = s.assignment
                                         WHERE s.id = ? AND s.userid > 0", array($id));
            if (!$row) {
                return;
            }
        }
        if (review::is_legit($type, $id)) {
            return;
        }
        $course = get_course((int)$row->course);
        $cm = get_fast_modinfo($course)->get_instances_of($type)[(int)$row->instance] ?? null;
        if (!$cm) {
            return;
        }
        $item = checker::run_item($course, (int)$cm->id, $type . ':' . $id);
        if (!$item || !$item->signals) {
            return;
        }
        self::send($course, $cm, $item);
    }

    private static function send(\stdClass $course, \cm_info $cm, \stdClass $item): void {
        $context = \context_module::instance($cm->id);
        $student = \core_user::get_user((int)$item->userid);
        if (!$student) {
            return;
        }
        $teachers = get_enrolled_users($context, 'local/attemptcheck:notify', 0, 'u.*', null, 0, 0, true);
        if (!$teachers) {
            return;
        }
        $lines = array();
        foreach ($item->signals as $signal) {
            $lines[] = '- ' . presenter::label($signal['code']) . ' : ' . presenter::text($signal, (int)$course->id);
        }
        $url = new \moodle_url('/local/attemptcheck/report.php', array('courseid' => $course->id, 'cmid' => $cm->id));
        $a = (object)array(
            'student'  => fullname($student),
            'activity' => $cm->get_formatted_name(),
            'course'   => format_string($course->shortname, true, array('context' => $context)),
            'signals'  => implode("\n", $lines),
            'url'      => $url->out(false),
        );
        foreach ($teachers as $teacher) {
            if ((int)$teacher->id === (int)$student->id) {
                continue;
            }
            $msg = new \core\message\message();
            $msg->component         = 'local_attemptcheck';
            $msg->name              = 'suspicious';
            $msg->userfrom          = \core_user::get_noreply_user();
            $msg->userto            = $teacher;
            $msg->subject           = get_string('notify_subject', 'local_attemptcheck', $a);
            $msg->fullmessage       = get_string('notify_body', 'local_attemptcheck', $a);
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml   = nl2br(s($msg->fullmessage));
            $msg->smallmessage      = get_string('notify_small', 'local_attemptcheck', $a);
            $msg->notification      = 1;
            $msg->contexturl        = $url->out(false);
            $msg->contexturlname    = get_string('notify_linkname', 'local_attemptcheck');
            $msg->courseid          = (int)$course->id;
            try {
                message_send($msg);
            } catch (\Throwable $e) {
                debugging('local_attemptcheck : notification impossible — ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
