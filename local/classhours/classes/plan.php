<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Réglages d'une activité surveillée : options (code de séance, mode examen)
 * et date prévue, pour tout le cours et, au besoin, par groupe.
 *
 * Une activité surveillée sans ligne a les valeurs par défaut : pas de code,
 * pas de mode examen, pas de date.
 */
class plan {

    const OPTIONS = 'local_classhours_supervised';
    const DATES = 'local_classhours_plan';

    /** @var array courseid => [cmid => options] */
    private static $options = array();

    /** @var array courseid => [cmid => [groupid => date]] */
    private static $dates = array();

    public static function reset_cache(): void {
        self::$options = array();
        self::$dates = array();
    }

    // -------------------------------------------------------------------------
    //  Options
    // -------------------------------------------------------------------------

    /**
     * Options des activités surveillées d'un cours. Une requête par cours.
     *
     * @return \stdClass[] cmid => {sessioncode, exammode}
     */
    public static function options_for_course(int $courseid): array {
        global $DB;
        if (!isset(self::$options[$courseid])) {
            self::$options[$courseid] = array();
            foreach ($DB->get_records(self::OPTIONS, array('courseid' => $courseid), 'id',
                    'id, cmid, sessioncode, exammode') as $row) {
                self::$options[$courseid][(int)$row->cmid] = $row;
            }
        }
        return self::$options[$courseid];
    }

    /** Options d'une activité (valeurs par défaut sans ligne). */
    public static function options(int $courseid, int $cmid): \stdClass {
        return self::options_for_course($courseid)[$cmid]
            ?? (object)array('cmid' => $cmid, 'sessioncode' => 0, 'exammode' => 0);
    }

    /** Enregistre les options d'une activité. */
    public static function save_options(int $courseid, int $cmid, bool $sessioncode, bool $exammode): void {
        global $DB;
        $row = $DB->get_record(self::OPTIONS, array('cmid' => $cmid));
        $data = array('sessioncode' => (int)$sessioncode, 'exammode' => (int)$exammode, 'timemodified' => time());
        if ($row) {
            $DB->update_record(self::OPTIONS, (object)(array('id' => $row->id) + $data));
        } else {
            $DB->insert_record(self::OPTIONS, (object)(array('courseid' => $courseid, 'cmid' => $cmid) + $data));
        }
        self::reset_cache();
    }

    // -------------------------------------------------------------------------
    //  Dates prévues
    // -------------------------------------------------------------------------

    /**
     * Dates prévues des activités d'un cours. Une requête par cours.
     *
     * @return array cmid => [groupid => date] (groupid 0 : tout le cours)
     */
    public static function dates_for_course(int $courseid): array {
        global $DB;
        if (!isset(self::$dates[$courseid])) {
            self::$dates[$courseid] = array();
            foreach ($DB->get_records(self::DATES, array('courseid' => $courseid), 'id',
                    'id, cmid, groupid, timeplanned') as $row) {
                self::$dates[$courseid][(int)$row->cmid][(int)$row->groupid] = (int)$row->timeplanned;
            }
        }
        return self::$dates[$courseid];
    }

    /**
     * Date prévue pour un élève : celle d'un de ses groupes (la plus proche),
     * sinon celle de tout le cours ; 0 si aucune.
     *
     * @param int   $courseid
     * @param int   $cmid
     * @param int[] $groupids groupes de l'élève
     */
    public static function date_for(int $courseid, int $cmid, array $groupids): int {
        $dates = self::dates_for_course($courseid)[$cmid] ?? array();
        $best = 0;
        foreach ($groupids as $groupid) {
            if (!empty($dates[$groupid]) && ($best === 0 || $dates[$groupid] < $best)) {
                $best = (int)$dates[$groupid];
            }
        }
        return $best ?: (int)($dates[0] ?? 0);
    }

    /**
     * Enregistre les dates prévues d'une activité.
     *
     * @param int   $courseid
     * @param int   $cmid
     * @param int[] $dates groupid => date (0 ou absent : pas de date)
     */
    public static function save_dates(int $courseid, int $cmid, array $dates): void {
        global $DB;
        $DB->delete_records(self::DATES, array('cmid' => $cmid));
        foreach ($dates as $groupid => $time) {
            if ((int)$time > 0) {
                $DB->insert_record(self::DATES, (object)array('courseid' => $courseid, 'cmid' => $cmid,
                    'groupid' => (int)$groupid, 'timeplanned' => (int)$time));
            }
        }
        self::reset_cache();
    }

    /** Supprime les réglages d'une activité supprimée. */
    public static function delete_for_cm(int $cmid): void {
        global $DB;
        $DB->delete_records(self::OPTIONS, array('cmid' => $cmid));
        $DB->delete_records(self::DATES, array('cmid' => $cmid));
        self::reset_cache();
    }
}
