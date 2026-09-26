<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Suppression d'une tentative jugée non légitime.
 *
 *   - Test : suppression standard de la tentative (quiz_delete_attempt), qui
 *     recalcule la note. Les corrections IA en attente de cette tentative
 *     (local_aifeedback) sont annulées avant.
 *   - Devoir : la remise est effacée (contenu, statut « nouveau » ou
 *     « rouvert »), puis le feedback IA et la note. L'élève peut redéposer.
 *
 * Droits : local/attemptcheck:delete dans le cours, plus la capacité Moodle de
 * l'activité (mod/quiz:deleteattempts, mod/assign:grade).
 *
 * La note effacée est poussée au carnet de notes : les plugins qui écoutent
 * user_graded (remontée EFE) reçoivent une note vide.
 */
class remover {

    /**
     * Description d'une tentative pour la page de confirmation ; vérifie
     * qu'elle appartient au cours.
     *
     * @return \stdClass|null type, id, key, cmid, userid, activity, attempt, timestart, timeend, candelete
     */
    public static function describe(string $type, int $id, int $courseid): ?\stdClass {
        global $DB;
        if ($type === 'quiz') {
            $row = $DB->get_record_sql("SELECT qa.id, qa.userid, qa.attempt, qa.timestart, qa.timefinish AS timeend,
                                               q.id AS instance, q.course
                                          FROM {quiz_attempts} qa
                                          JOIN {quiz} q ON q.id = qa.quiz
                                         WHERE qa.id = :id AND qa.preview = 0", array('id' => $id));
        } else if ($type === 'assign') {
            $row = $DB->get_record_sql("SELECT s.id, s.userid, s.attemptnumber + 1 AS attempt, s.timecreated AS timestart,
                                               s.timemodified AS timeend, a.id AS instance, a.course
                                          FROM {assign_submission} s
                                          JOIN {assign} a ON a.id = s.assignment
                                         WHERE s.id = :id AND s.userid > 0", array('id' => $id));
        } else {
            return null;
        }
        if (!$row || (int)$row->course !== $courseid) {
            return null;
        }
        $cm = get_fast_modinfo($courseid)->get_instances_of($type)[(int)$row->instance] ?? null;
        if (!$cm) {
            return null;
        }
        return (object)array(
            'type'      => $type,
            'id'        => (int)$row->id,
            'key'       => $type . ':' . (int)$row->id,
            'cmid'      => (int)$cm->id,
            'userid'    => (int)$row->userid,
            'activity'  => $cm->get_formatted_name(),
            'attempt'   => (int)$row->attempt,
            'timestart' => (int)$row->timestart,
            'timeend'   => (int)$row->timeend,
            'candelete' => self::can_delete($type, (int)$cm->id, $courseid),
        );
    }

    /** L'utilisateur courant peut-il supprimer dans cette activité ? */
    public static function can_delete(string $type, int $cmid, int $courseid): bool {
        if (!has_capability('local/attemptcheck:delete', \context_course::instance($courseid))) {
            return false;
        }
        $context = \context_module::instance($cmid);
        return $type === 'quiz'
            ? has_capability('mod/quiz:deleteattempts', $context)
            : has_capability('mod/assign:grade', $context);
    }

    /**
     * Supprime une tentative (test) ou efface une remise (devoir).
     *
     * @throws \moodle_exception tentative inconnue ou droits insuffisants
     */
    public static function delete(string $type, int $id, int $courseid): void {
        $item = self::describe($type, $id, $courseid);
        if (!$item) {
            throw new \moodle_exception('error_notfound', 'local_attemptcheck');
        }
        if (!$item->candelete) {
            throw new \required_capability_exception(\context_module::instance($item->cmid),
                'local/attemptcheck:delete', 'nopermissions', '');
        }
        if ($type === 'quiz') {
            self::delete_quiz_attempt($item, $courseid);
        } else {
            self::erase_assign_submission($item, $courseid);
        }
        review::forget($type, $id);
    }

    private static function delete_quiz_attempt(\stdClass $item, int $courseid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attempt = $DB->get_record('quiz_attempts', array('id' => $item->id), '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', array('id' => $attempt->quiz), '*', MUST_EXIST);
        $quiz->cmid = $item->cmid;

        // Corrections IA en attente : leur tentative va disparaître.
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_aifeedback_qgrading')) {
            $DB->delete_records_select('local_aifeedback_qgrading',
                'questionattemptid IN (SELECT id FROM {question_attempts} WHERE questionusageid = :usage)',
                array('usage' => (int)$attempt->uniqueid));
        }

        quiz_delete_attempt($attempt, $quiz);
    }

    /**
     * Même effet que assign::remove_submission(), sans exiger
     * mod/assign:editothersubmission (que les enseignants n'ont pas par
     * défaut), puis effacement du feedback IA et de la note.
     */
    private static function erase_assign_submission(\stdClass $item, int $courseid): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');

        list($course, $cm) = get_course_and_cm_from_cmid($item->cmid, 'assign');
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        if ($assign->get_instance()->teamsubmission) {
            throw new \moodle_exception('error_teamsubmission', 'local_attemptcheck');
        }

        $submission = $DB->get_record('assign_submission', array('id' => $item->id), '*', MUST_EXIST);
        $submission->status = $submission->attemptnumber ? ASSIGN_SUBMISSION_STATUS_REOPENED : ASSIGN_SUBMISSION_STATUS_NEW;
        $submission->timemodified = time();
        $DB->update_record('assign_submission', $submission);

        foreach ($assign->get_submission_plugins() as $plugin) {
            if ($plugin->is_enabled() && $plugin->is_visible()) {
                $plugin->remove($submission);
            }
        }

        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) && $assign->get_instance()->completionsubmit) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, (int)$submission->userid);
        }
        \mod_assign\event\submission_removed::create_from_submission($assign, $submission)->trigger();
        \mod_assign\event\submission_status_updated::create_from_submission($assign, $submission)->trigger();

        // Feedback IA (une correction en attente n'aura plus rien à faire), puis
        // note : poussée vide au carnet de notes.
        $grade = $assign->get_user_grade((int)$submission->userid, false, (int)$submission->attemptnumber);
        if ($grade) {
            if ($DB->get_manager()->table_exists('assignfeedback_ai_grade')) {
                $DB->delete_records('assignfeedback_ai_grade',
                    array('assignment' => $assign->get_instance()->id, 'grade' => $grade->id));
            }
            $grade->grade = -1;
            $grade->grader = (int)$USER->id;
            $assign->update_grade($grade);
        }
    }
}
