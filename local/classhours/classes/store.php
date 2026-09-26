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

    /** « Cours » des périodes fermées globales du site (vacances). */
    const SITE = 0;

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
     * @param int         $courseid  self::SITE = période fermée globale
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
        if ($courseid === self::SITE && ($type !== self::CLOSED || $groupid !== 0)) {
            throw new \coding_exception('Une période globale est une période fermée pour tout le cours');
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
        self::reset_cache($courseid);
        return (int)$id;
    }

    /**
     * @param int $courseid self::SITE pour une période globale
     * @param int $id
     */
    public static function delete_period(int $courseid, int $id): void {
        global $DB;
        $DB->delete_records('local_classhours_period', array('id' => $id, 'courseid' => $courseid));
        self::reset_cache($courseid);
    }

    /** Une période globale change l'emploi du temps de tous les cours. */
    private static function reset_cache(int $courseid): void {
        schedule::reset_cache($courseid === self::SITE ? null : $courseid);
    }

    // -------------------------------------------------------------------------
    //  Réglages du cours : option EFE, périodes globales
    // -------------------------------------------------------------------------

    /**
     * Option « restreindre automatiquement les activités à remontée EFE » du
     * cours ; un cours qui ne l'a jamais réglée suit le défaut du site.
     */
    public static function get_efeauto(int $courseid): bool {
        return (bool)self::get_setting($courseid, 'efeauto');
    }

    public static function set_efeauto(int $courseid, bool $on): void {
        self::set_setting($courseid, 'efeauto', $on);
    }

    /**
     * Le cours applique-t-il les périodes fermées globales du site ? Un cours
     * qui ne l'a jamais réglé suit le défaut du site (oui, sauf réglage).
     */
    public static function get_useglobal(int $courseid): bool {
        return (bool)self::get_setting($courseid, 'useglobal');
    }

    public static function set_useglobal(int $courseid, bool $on): void {
        self::set_setting($courseid, 'useglobal', $on);
        schedule::reset_cache($courseid);
    }

    /** Valeur par défaut du site d'un réglage de cours. */
    private static function default_setting(string $field): int {
        if ($field === 'useglobal') {
            $value = get_config('local_classhours', 'useglobal_default');
            return ($value === false) ? 1 : (int)(bool)$value;
        }
        return (int)(bool)get_config('local_classhours', 'efeauto_default');
    }

    private static function get_setting(int $courseid, string $field): int {
        global $DB;
        $value = $DB->get_field('local_classhours_course', $field, array('courseid' => $courseid));
        return ($value === false) ? self::default_setting($field) : (int)$value;
    }

    /**
     * Enregistre un réglage du cours. À la création de la ligne, les autres
     * réglages prennent la valeur qu'ils avaient (défaut du site) : régler l'un
     * ne doit pas changer l'autre.
     */
    private static function set_setting(int $courseid, string $field, bool $on): void {
        global $DB;
        $record = $DB->get_record('local_classhours_course', array('courseid' => $courseid));
        if ($record) {
            $record->$field = $on ? 1 : 0;
            $record->timemodified = time();
            $DB->update_record('local_classhours_course', $record);
            return;
        }
        $record = (object)array(
            'courseid'     => $courseid,
            'efeauto'      => self::default_setting('efeauto'),
            'useglobal'    => self::default_setting('useglobal'),
            'timemodified' => time(),
        );
        $record->$field = $on ? 1 : 0;
        $DB->insert_record('local_classhours_course', $record);
    }

    // -------------------------------------------------------------------------
    //  Exclusions de l'option EFE
    // -------------------------------------------------------------------------

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
