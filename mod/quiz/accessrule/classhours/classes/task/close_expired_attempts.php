<?php
namespace quizaccess_classhours\task;

defined('MOODLE_INTERNAL') || die();

use mod_quiz\quiz_attempt;

/**
 * Clôt les tentatives dont le créneau est terminé (élève parti sans envoyer,
 * navigateur fermé).
 *
 * Le cœur s'en charge déjà quand timecheckstate vaut la fin du créneau (posé
 * au début de la tentative), mais ce champ est recalculé SANS les règles
 * d'accès quand les dates ou les dérogations du test changent. Cette tâche
 * repasse donc sur les tentatives ouvertes des tests restreints.
 */
class close_expired_attempts extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_close', 'quizaccess_classhours');
    }

    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $sql = "SELECT qa.id, qa.state
                  FROM {quiz_attempts} qa
                  JOIN {course_modules} cm ON cm.instance = qa.quiz
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE qa.state IN (:inprogress, :overdue)
                   AND qa.preview = 0
                   AND cm.deletioninprogress = 0
                   AND " . $DB->sql_like('cm.availability', ':pattern');
        $attempts = $DB->get_records_sql($sql, array(
            'modname'    => 'quiz',
            'inprogress' => quiz_attempt::IN_PROGRESS,
            'overdue'    => quiz_attempt::OVERDUE,
            'pattern'    => '%"classhours"%',
        ));

        $now = time();
        $closed = 0;
        foreach ($attempts as $attempt) {
            try {
                quiz_attempt::create((int)$attempt->id)->handle_if_time_expired($now, false);
                $state = $DB->get_field('quiz_attempts', 'state', array('id' => $attempt->id));
                if ($state !== $attempt->state) {
                    $closed++;
                }
            } catch (\Throwable $e) {
                mtrace('quizaccess_classhours : tentative ' . $attempt->id . ' — ' . $e->getMessage());
            }
        }
        mtrace('quizaccess_classhours : ' . $closed . ' tentative(s) traitée(s) sur ' . count($attempts) . '.');
    }
}
