<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Tiers-temps : élèves d'un cours dont la durée d'une activité surveillée est
 * majorée (aménagement PAP, PAI…). Le pourcentage s'applique au temps écoulé
 * entre l'ouverture et la fin de base (voir gate::user_end()).
 */
class extratime {

    const TABLE = 'local_classhours_extratime';

    /** Majoration proposée par défaut (réglage du site extratimedefault). */
    const DEFAULT_PERCENT = 33;

    /** @var array courseid => [userid => percent] */
    private static $cache = array();

    public static function reset_cache(): void {
        self::$cache = array();
    }

    /** Majoration par défaut du site. */
    public static function default_percent(): int {
        $value = get_config('local_classhours', 'extratimedefault');
        return ($value === false || $value === '') ? self::DEFAULT_PERCENT : max(0, (int)$value);
    }

    /**
     * Élèves au tiers-temps du cours. Une requête par cours, mémorisée.
     *
     * @return int[] userid => pourcentage
     */
    public static function for_course(int $courseid): array {
        global $DB;
        if (!isset(self::$cache[$courseid])) {
            self::$cache[$courseid] = array();
            foreach ($DB->get_records(self::TABLE, array('courseid' => $courseid), 'userid', 'id, userid, percent') as $row) {
                self::$cache[$courseid][(int)$row->userid] = (int)$row->percent;
            }
        }
        return self::$cache[$courseid];
    }

    /** Majoration d'un élève (0 s'il n'est pas au tiers-temps). */
    public static function percent(int $courseid, int $userid): int {
        return self::for_course($courseid)[$userid] ?? 0;
    }

    /**
     * Enregistre les élèves au tiers-temps du cours.
     *
     * @param int   $courseid
     * @param int[] $percents userid => pourcentage (les autres élèves sont retirés)
     */
    public static function save(int $courseid, array $percents): void {
        global $DB;
        $current = self::for_course($courseid);
        foreach ($percents as $userid => $percent) {
            $userid = (int)$userid;
            $percent = max(1, min(200, (int)$percent));
            if (!isset($current[$userid])) {
                $DB->insert_record(self::TABLE, (object)array('courseid' => $courseid, 'userid' => $userid,
                    'percent' => $percent));
            } else if ($current[$userid] !== $percent) {
                $DB->set_field(self::TABLE, 'percent', $percent, array('courseid' => $courseid, 'userid' => $userid));
            }
        }
        foreach (array_keys($current) as $userid) {
            if (!array_key_exists($userid, $percents)) {
                $DB->delete_records(self::TABLE, array('courseid' => $courseid, 'userid' => $userid));
            }
        }
        self::reset_cache();
    }
}
