<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\availability_json;
use local_classhours\gate;
use local_classhours\grant;
use local_classhours\schedule;
use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_settings;

/**
 * Test restreint aux heures de cours (condition « Pendant les heures de
 * cours » à la racine de sa restriction d'accès), ou test surveillé
 * (condition « Activité surveillée »), ou les deux.
 *
 * Heures de cours : la tentative se termine à la fin du créneau dans lequel
 * elle a commencé. end_time() suffit, le cœur du test fait le reste (compte à
 * rebours, envoi automatique par le navigateur, clôture par la tâche des
 * tentatives en retard, selon le réglage « Quand le temps est écoulé » du
 * test). La restriction d'accès reste ouverte quelques minutes après le
 * créneau (tolérance de local_classhours) pour que l'envoi automatique passe ;
 * aucune tentative ne peut commencer pendant cette tolérance.
 *
 * Test surveillé : pas de nouvelle tentative tant que l'enseignant ne l'a pas
 * ouvert pour l'élève (l'élève peut rouvrir le test après coup pour relire sa
 * tentative). Ouvert avec une durée, la tentative se termine à l'heure prévue ;
 * fermé à la main, la tentative en cours est ramassée (voir gate_collector).
 */
class quizaccess_classhours extends access_rule_base {

    /** @var bool le test porte la condition Heures de cours */
    private $classhours = false;

    /** @var bool le test porte la condition Activité surveillée */
    private $supervised = false;

    public static function make(quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        $cm = $quizobj->get_cm();
        if (empty($cm->availability)) {
            return null;
        }
        $classhours = availability_json::has_root_condition($cm->availability)
            && availability_json::condition_enabled();
        $supervised = availability_json::has_root_condition($cm->availability, null, gate::TYPE)
            && availability_json::condition_enabled(gate::TYPE);
        if (!$classhours && !$supervised) {
            return null;
        }
        $rule = new self($quizobj, $timenow);
        $rule->classhours = $classhours;
        $rule->supervised = $supervised;
        return $rule;
    }

    public function prevent_new_attempt($numprevattempts, $lastattempt) {
        global $USER;
        if (has_capability('moodle/course:ignoreavailabilityrestrictions', $this->quizobj->get_context())) {
            return false;
        }
        $userid = (int)$USER->id;
        if ($this->classhours) {
            $message = $this->classhours_prevents($userid);
            if ($message !== false) {
                return $message;
            }
        }
        if ($this->supervised && !gate::is_open_for((int)$this->quizobj->get_courseid(),
                (int)$this->quizobj->get_cmid(), $userid, (int)$this->timenow)) {
            return get_string('notopen_supervised', 'quizaccess_classhours');
        }
        return false;
    }

    /**
     * Refus des Heures de cours : hors créneau et sans accès ponctuel.
     *
     * @return string|false
     */
    private function classhours_prevents(int $userid) {
        $sched = $this->schedule();
        if ($sched->interval_for_user($userid, (int)$this->timenow) !== null) {
            return false;
        }
        // Accès ponctuel accordé par l'enseignant : la tentative peut commencer.
        if (grant::window_at((int)$this->quizobj->get_cmid(), $userid, (int)$this->timenow) !== null) {
            return false;
        }
        $next = $sched->next_opening_for_user($userid, (int)$this->timenow);
        if ($next === null) {
            return get_string('notinslot', 'quizaccess_classhours');
        }
        return get_string('notinslot_next', 'quizaccess_classhours', $sched->format_time($next));
    }

    /**
     * La plus proche des fins connues :
     *   - Heures de cours : fin du créneau (fusionné) qui contient le début de
     *     la tentative ; pour une tentative commencée pendant un accès
     *     ponctuel, fin de cet accès ;
     *   - test surveillé ouvert avec une durée : heure de fermeture prévue.
     *
     * @param stdClass $attempt
     * @return int|false
     */
    public function end_time($attempt) {
        if (empty($attempt->timestart) || empty($attempt->userid)) {
            return false;
        }
        $ends = array();
        if ($this->classhours) {
            $interval = $this->schedule()->interval_for_user((int)$attempt->userid, (int)$attempt->timestart);
            if ($interval !== null) {
                $ends[] = (int)$interval[1];
            } else {
                $window = grant::window_at((int)$this->quizobj->get_cmid(), (int)$attempt->userid,
                    (int)$attempt->timestart);
                if ($window !== null) {
                    $ends[] = (int)$window[1];
                }
            }
        }
        if ($this->supervised) {
            $end = gate::end_for((int)$this->quizobj->get_courseid(), (int)$this->quizobj->get_cmid(),
                (int)$attempt->userid, (int)$this->timenow);
            if ($end !== null && $end > 0) {
                $ends[] = $end;
            }
        }
        return $ends ? min($ends) : false;
    }

    public function description() {
        $descriptions = array();
        if ($this->classhours) {
            $descriptions[] = get_string('ruledescription', 'quizaccess_classhours');
        }
        if ($this->supervised) {
            $descriptions[] = get_string('ruledescription_supervised', 'quizaccess_classhours');
        }
        return $descriptions;
    }

    private function schedule(): schedule {
        return schedule::for_course((int)$this->quizobj->get_courseid());
    }
}
