<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Pont OPTIONNEL vers local_classhours : une tentative a-t-elle été faite en
 * dehors des heures de cours de l'élève ?
 *
 * L'emploi du temps actuel est appliqué aux tentatives passées, y compris
 * celles d'avant la mise en place des heures de cours (c'est le but) ; les
 * périodes fermées ou ouvertures exceptionnelles passées ne sont connues que
 * si elles ont été saisies.
 */
class offslot {

    /** local_classhours est-il installé ? */
    public static function available(): bool {
        return class_exists('\local_classhours\schedule');
    }

    /** Le cours a-t-il un emploi du temps ? */
    public static function has_schedule(int $courseid): bool {
        if (!self::available()) {
            return false;
        }
        try {
            $sched = \local_classhours\schedule::for_course($courseid);
            return !empty($sched->get_slots()) || !empty($sched->get_periods('open'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Fonction de calcul de l'indicateur pour une activité, ou null si elle ne
     * s'applique pas (pas d'emploi du temps, activité non restreinte alors
     * qu'on ne regarde que les restreintes).
     *
     * @param int  $courseid
     * @param bool $restricted l'activité porte-t-elle la condition Heures de cours ?
     * @param bool $restrictedonly ne regarder que les activités restreintes
     * @return callable|null function(\stdClass $item): ?array
     */
    public static function checker(int $courseid, bool $restricted, bool $restrictedonly): ?callable {
        if (($restrictedonly && !$restricted) || !self::has_schedule($courseid)) {
            return null;
        }
        $sched = \local_classhours\schedule::for_course($courseid);
        $grace = \local_classhours\schedule::grace();
        return function(\stdClass $item) use ($sched, $grace) {
            try {
                if ($item->type === 'quiz') {
                    if ($item->timestart > 0 && $sched->interval_for_user((int)$item->userid, (int)$item->timestart) === null) {
                        return array('when' => 'start', 'time' => (int)$item->timestart);
                    }
                    if ($item->timeend > 0
                            && $sched->interval_for_user((int)$item->userid, (int)$item->timeend, $grace) === null) {
                        return array('when' => 'end', 'time' => (int)$item->timeend);
                    }
                    return null;
                }
                if ($item->timeend > 0 && $sched->interval_for_user((int)$item->userid, (int)$item->timeend, $grace) === null) {
                    return array('when' => 'submit', 'time' => (int)$item->timeend);
                }
            } catch (\Throwable $e) {
                debugging('local_attemptcheck : heures de cours — ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return null;
        };
    }

    /** Heure affichée dans le fuseau de l'établissement. */
    public static function format_time(int $courseid, int $t): string {
        if (self::available()) {
            return \local_classhours\schedule::for_course($courseid)->format_time($t);
        }
        return userdate($t);
    }
}
