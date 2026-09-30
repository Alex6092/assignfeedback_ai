<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Notifications des accès ponctuels : la demande part vers les enseignants,
 * la décision vers l'élève.
 *
 * Un échec d'envoi ne doit jamais faire échouer la demande ou la décision :
 * chaque envoi est protégé.
 */
class notifier {

    /**
     * Nouvelle demande : prévient les enseignants qui peuvent l'accorder.
     *
     * Dans un cours en groupes séparés, seuls ceux qui partagent un groupe avec
     * l'élève la reçoivent ; s'il n'y en a aucun, tous la reçoivent, pour
     * qu'une demande ne reste jamais sans destinataire.
     */
    public static function request_sent(\cm_info $cm, \stdClass $grant): void {
        try {
            $student = \core_user::get_user((int)$grant->userid);
            if (!$student) {
                return;
            }
            $a = (object)array(
                'student'  => fullname($student),
                'activity' => format_string($cm->name, true, array('context' => \context_module::instance($cm->id))),
                'course'   => format_string($cm->get_course()->shortname),
                'reason'   => trim((string)$grant->reason) !== '' ? s($grant->reason)
                    : get_string('msg_noreason', 'local_classhours'),
            );
            $url = new \moodle_url('/local/classhours/requests.php', array('courseid' => (int)$grant->courseid));
            foreach (self::teachers_for($cm, (int)$grant->userid) as $teacher) {
                self::send('accessrequest', $teacher, (int)$grant->courseid,
                    get_string('msg_request_subject', 'local_classhours', $a),
                    get_string('msg_request_body', 'local_classhours', $a),
                    $url, get_string('requests_heading', 'local_classhours'));
            }
        } catch (\Throwable $e) {
            debugging('local_classhours : notification de demande non envoyée — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /** Décision prise (acceptée, refusée ou accordée directement) : prévient l'élève. */
    public static function decision_sent(\stdClass $grant): void {
        try {
            $student = \core_user::get_user((int)$grant->userid);
            if (!$student) {
                return;
            }
            $modinfo = get_fast_modinfo((int)$grant->courseid, (int)$grant->userid);
            $cms = $modinfo->get_cms();
            if (!isset($cms[(int)$grant->cmid])) {
                return;
            }
            $cm = $cms[(int)$grant->cmid];
            $a = (object)array(
                'activity' => format_string($cm->name, true, array('context' => \context_module::instance($cm->id))),
                'until'    => schedule::for_course((int)$grant->courseid)->format_time((int)$grant->timeend),
                'comment'  => trim((string)$grant->decisioncomment) !== '' ? s($grant->decisioncomment) : '',
            );
            if ($grant->status === grant::ACCEPTED) {
                $subject = get_string('msg_accepted_subject', 'local_classhours', $a);
                $body = get_string('msg_accepted_body', 'local_classhours', $a);
                // L'adresse de l'activité, ou celle du cours pour un module sans page.
                $url = ($cm->url instanceof \moodle_url)
                    ? $cm->url : new \moodle_url('/course/view.php', array('id' => (int)$grant->courseid));
            } else {
                $subject = get_string('msg_refused_subject', 'local_classhours', $a);
                $body = get_string('msg_refused_body', 'local_classhours', $a);
                $url = new \moodle_url('/course/view.php', array('id' => (int)$grant->courseid));
            }
            if ($a->comment !== '') {
                $body .= "\n\n" . get_string('msg_comment', 'local_classhours', $a->comment);
            }
            self::send('accessdecision', $student, (int)$grant->courseid, $subject, $body, $url, $a->activity);
        } catch (\Throwable $e) {
            debugging('local_classhours : notification de décision non envoyée — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Activité surveillée : l'élève demande à la rattraper. Prévient les
     * enseignants qui ouvrent les activités surveillées (même filtrage par
     * groupes que les demandes d'accès).
     */
    public static function catchup_requested(\cm_info $cm, int $userid): void {
        try {
            $student = \core_user::get_user($userid);
            if (!$student) {
                return;
            }
            $a = (object)array(
                'student'  => fullname($student),
                'activity' => format_string($cm->name, true, array('context' => \context_module::instance($cm->id))),
                'course'   => format_string($cm->get_course()->shortname),
            );
            $url = new \moodle_url('/local/classhours/supervised.php', array('courseid' => (int)$cm->course),
                'cm' . (int)$cm->id);
            foreach (self::teachers_for($cm, $userid, 'local/classhours:supervise') as $teacher) {
                self::send('catchuprequest', $teacher, (int)$cm->course,
                    get_string('msg_catchup_subject', 'local_classhours', $a),
                    get_string('msg_catchup_body', 'local_classhours', $a),
                    $url, get_string('supervised_menu', 'local_classhours'));
            }
        } catch (\Throwable $e) {
            debugging('local_classhours : notification de rattrapage non envoyée — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Enseignants à prévenir pour une demande de cet élève sur cette activité.
     *
     * @param \cm_info $cm
     * @param int      $studentid
     * @param string   $capability capacité des enseignants à prévenir
     * @return \stdClass[]
     */
    public static function teachers_for(\cm_info $cm, int $studentid,
            string $capability = 'local/classhours:grantaccess'): array {
        global $CFG;
        require_once($CFG->libdir . '/grouplib.php');
        $context = \context_module::instance($cm->id);
        $teachers = get_users_by_capability($context, $capability,
            'u.*', 'u.lastname, u.firstname', '', '', '', '', false, true);
        unset($teachers[$studentid]);
        if (!$teachers || groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
            return array_values($teachers);
        }
        $studentgroups = groups_get_user_groups((int)$cm->course, $studentid)[0] ?? array();
        if (!$studentgroups) {
            return array_values($teachers);
        }
        $same = array_filter($teachers, function($teacher) use ($cm, $studentgroups) {
            $theirs = groups_get_user_groups((int)$cm->course, (int)$teacher->id)[0] ?? array();
            return (bool)array_intersect($studentgroups, $theirs);
        });
        return array_values($same ?: $teachers);
    }

    private static function send(string $name, \stdClass $userto, int $courseid, string $subject,
            string $body, \moodle_url $url, string $urlname): void {
        $message = new \core\message\message();
        $message->component         = 'local_classhours';
        $message->name              = $name;
        $message->userfrom          = \core_user::get_noreply_user();
        $message->userto            = $userto;
        $message->subject           = $subject;
        $message->fullmessage       = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = nl2br(s($body)) . '<p>' . \html_writer::link($url, s($urlname)) . '</p>';
        $message->smallmessage      = $subject;
        $message->notification      = 1;
        $message->contexturl        = $url->out(false);
        $message->contexturlname    = $urlname;
        $message->courseid          = $courseid;
        message_send($message);
    }
}
