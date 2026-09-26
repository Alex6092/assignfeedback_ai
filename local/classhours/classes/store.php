<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Écritures dans les tables du plugin. Chaque modification de l'emploi du
 * temps vide le cache de requête de {@see schedule}.
 */
class store {

    /** Types de période. */
    const OPEN = 'open';
    const CLOSED = 'closed';

    // -------------------------------------------------------------------------
    //  Créneaux hebdomadaires
    // -------------------------------------------------------------------------

    /**
     * @param int $courseid
     * @param int $groupid   0 = tout le cours
     * @param int $weekday   1 = lundi ... 7 = dimanche
     * @param int $starttime minutes depuis minuit
     * @param int $endtime   minutes depuis minuit
     * @return int id du créneau
     */
    public static function add_slot(int $courseid, int $groupid, int $weekday, int $starttime, int $endtime): int {
        global $DB;
        $id = $DB->insert_record('local_classhours_slot', (object)array(
            'courseid'     => $courseid,
            'groupid'      => max(0, $groupid),
            'weekday'      => min(7, max(1, $weekday)),
            'starttime'    => $starttime,
            'endtime'      => $endtime,
            'timemodified' => time(),
        ));
        schedule::reset_cache($courseid);
        return (int)$id;
    }

    public static function delete_slot(int $courseid, int $id): void {
        global $DB;
        $DB->delete_records('local_classhours_slot', array('id' => $id, 'courseid' => $courseid));
        schedule::reset_cache($courseid);
    }

    // -------------------------------------------------------------------------
    //  Ouvertures exceptionnelles et périodes fermées
    // -------------------------------------------------------------------------

    /**
     * @param int         $courseid
     * @param int         $groupid   0 = tout le cours
     * @param string      $type      self::OPEN | self::CLOSED
     * @param int         $timestart
     * @param int         $timeend   exclu
     * @param string|null $name
     * @return int id de la période
     */
    public static function add_period(int $courseid, int $groupid, string $type, int $timestart, int $timeend,
            ?string $name): int {
        global $DB;
        if ($type !== self::OPEN && $type !== self::CLOSED) {
            throw new \coding_exception('Type de période inconnu : ' . $type);
        }
        $name = ($name === null) ? '' : trim($name);
        $id = $DB->insert_record('local_classhours_period', (object)array(
            'courseid'     => $courseid,
            'groupid'      => max(0, $groupid),
            'type'         => $type,
            'timestart'    => $timestart,
            'timeend'      => $timeend,
            'name'         => ($name === '') ? null : \core_text::substr($name, 0, 255),
            'timemodified' => time(),
        ));
        schedule::reset_cache($courseid);
        return (int)$id;
    }

    public static function delete_period(int $courseid, int $id): void {
        global $DB;
        $DB->delete_records('local_classhours_period', array('id' => $id, 'courseid' => $courseid));
        schedule::reset_cache($courseid);
    }

    // -------------------------------------------------------------------------
    //  Option EFE et exclusions
    // -------------------------------------------------------------------------

    /**
     * Option « restreindre automatiquement les activités à remontée EFE » du
     * cours ; un cours qui ne l'a jamais réglée suit le défaut du site.
     */
    public static function get_efeauto(int $courseid): bool {
        global $DB;
        $value = $DB->get_field('local_classhours_course', 'efeauto', array('courseid' => $courseid));
        if ($value === false) {
            return (bool)get_config('local_classhours', 'efeauto_default');
        }
        return (bool)$value;
    }

    public static function set_efeauto(int $courseid, bool $on): void {
        global $DB;
        $id = $DB->get_field('local_classhours_course', 'id', array('courseid' => $courseid));
        $record = (object)array('courseid' => $courseid, 'efeauto' => $on ? 1 : 0, 'timemodified' => time());
        if ($id) {
            $record->id = $id;
            $DB->update_record('local_classhours_course', $record);
        } else {
            $DB->insert_record('local_classhours_course', $record);
        }
    }

    /**
     * Activités du cours exclues de l'option EFE.
     *
     * @return int[] cmid => cmid
     */
    public static function excluded_cmids(int $courseid): array {
        global $DB;
        $cmids = $DB->get_fieldset_select('local_classhours_exclude', 'cmid', 'courseid = ?', array($courseid));
        $cmids = array_map('intval', $cmids);
        return $cmids ? array_combine($cmids, $cmids) : array();
    }

    public static function set_excluded(int $cmid, int $courseid, bool $excluded): void {
        global $DB;
        $exists = $DB->record_exists('local_classhours_exclude', array('cmid' => $cmid));
        if ($excluded && !$exists) {
            $DB->insert_record('local_classhours_exclude', (object)array(
                'cmid' => $cmid, 'courseid' => $courseid, 'timecreated' => time()));
        } else if (!$excluded && $exists) {
            $DB->delete_records('local_classhours_exclude', array('cmid' => $cmid));
        }
    }

    // -------------------------------------------------------------------------
    //  Nettoyage
    // -------------------------------------------------------------------------

    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records('local_classhours_slot', array('courseid' => $courseid));
        $DB->delete_records('local_classhours_period', array('courseid' => $courseid));
        $DB->delete_records('local_classhours_course', array('courseid' => $courseid));
        $DB->delete_records('local_classhours_exclude', array('courseid' => $courseid));
        schedule::reset_cache($courseid);
    }

    /** Un groupe supprimé emporte ses créneaux et ses périodes. */
    public static function delete_group(int $groupid, int $courseid): void {
        global $DB;
        if ($groupid <= 0) {
            return;
        }
        $DB->delete_records('local_classhours_slot', array('groupid' => $groupid));
        $DB->delete_records('local_classhours_period', array('groupid' => $groupid));
        schedule::reset_cache($courseid);
    }

    public static function delete_cm(int $cmid): void {
        global $DB;
        $DB->delete_records('local_classhours_exclude', array('cmid' => $cmid));
    }
}
