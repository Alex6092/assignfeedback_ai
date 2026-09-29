<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\availability_json;
use local_classhours\grant;
use local_classhours\schedule;
use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_settings;

/**
 * Test restreint aux heures de cours (condition « Pendant les heures de
 * cours » à la racine de sa restriction d'accès).
 *
 * La tentative se termine à la fin du créneau dans lequel elle a commencé :
 * end_time() suffit, le cœur du test fait le reste (compte à rebours, envoi
 * automatique par le navigateur, clôture par la tâche des tentatives en
 * retard, selon le réglage « Quand le temps est écoulé » du test).
 *
 * La restriction d'accès reste ouverte quelques minutes après le créneau
 * (tolérance de local_classhours) pour que l'envoi automatique passe ; aucune
 * tentative ne peut commencer pendant cette tolérance.
 */
class quizaccess_classhours extends access_rule_base {

    public static function make(quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        $cm = $quizobj->get_cm();
        if (empty($cm->availability) || !availability_json::has_root_condition($cm->availability)
                || !availability_json::condition_enabled()) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    public function prevent_new_attempt($numprevattempts, $lastattempt) {
        global $USER;
        if (has_capability('moodle/course:ignoreavailabilityrestrictions', $this->quizobj->get_context())) {
            return false;
        }
        $sched = $this->schedule();
        if ($sched->interval_for_user((int)$USER->id, (int)$this->timenow) !== null) {
            return false;
        }
        // Accès ponctuel accordé par l'enseignant : la tentative peut commencer.
        if (grant::window_at((int)$this->quizobj->get_cmid(), (int)$USER->id, (int)$this->timenow) !== null) {
            return false;
        }
        $next = $sched->next_opening_for_user((int)$USER->id, (int)$this->timenow);
        if ($next === null) {
            return get_string('notinslot', 'quizaccess_classhours');
        }
        return get_string('notinslot_next', 'quizaccess_classhours', $sched->format_time($next));
    }

    /**
     * Fin du créneau (fusionné) qui contient le début de la tentative ; pour
     * une tentative commencée pendant un accès ponctuel, fin de cet accès.
     *
     * @param stdClass $attempt
     * @return int|false
     */
    public function end_time($attempt) {
        if (empty($attempt->timestart) || empty($attempt->userid)) {
            return false;
        }
        $interval = $this->schedule()->interval_for_user((int)$attempt->userid, (int)$attempt->timestart);
        if ($interval !== null) {
            return $interval[1];
        }
        $window = grant::window_at((int)$this->quizobj->get_cmid(), (int)$attempt->userid, (int)$attempt->timestart);
        return $window !== null ? $window[1] : false;
    }

    public function description() {
        return get_string('ruledescription', 'quizaccess_classhours');
    }

    private function schedule(): schedule {
        return schedule::for_course((int)$this->quizobj->get_courseid());
    }
}
