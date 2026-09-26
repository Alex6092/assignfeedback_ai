<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Pont OPTIONNEL vers le plugin local_efenotes (remontée des notes vers EFE).
 *
 * local_classhours ne dépend PAS de local_efenotes : on ne référence ses
 * classes qu'après class_exists(), et toute erreur est avalée. Le plugin EFE
 * n'est pas modifié ; on lit seulement sa configuration par activité.
 *
 * Une activité a une « remontée EFE » quand sa ligne local_efenotes_activity
 * est activée ET a au moins une compétence, de note ou de ponctualité : c'est
 * ce qu'EFE lui-même vérifie avant de reporter une note
 * (activity_config::is_reportable / is_punct_reportable).
 */
class efe_bridge {

    /** Le plugin local_efenotes est-il installé ? */
    public static function available(): bool {
        return class_exists('\local_efenotes\activity_config');
    }

    /**
     * Activités du cours qui ont une remontée EFE.
     *
     * @return int[]|null cmid => cmid ; null si EFE n'a pas pu être lu (on ne
     *                    doit alors rien retirer : ce n'est pas « aucune »)
     */
    public static function reporting_cmids(int $courseid): ?array {
        global $DB;
        if (!self::available()) {
            return array();
        }
        try {
            $rows = $DB->get_records('local_efenotes_activity', array('courseid' => $courseid, 'enabled' => 1));
            $cmids = array();
            foreach ($rows as $row) {
                if (self::is_reporting_row($row)) {
                    $cmids[(int)$row->cmid] = (int)$row->cmid;
                }
            }
            return $cmids;
        } catch (\Throwable $e) {
            debugging('local_classhours : lecture EFE impossible — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Configuration EFE complète et active ?
     *
     * @param \stdClass $row ligne local_efenotes_activity
     */
    private static function is_reporting_row(\stdClass $row): bool {
        $config = '\local_efenotes\activity_config';
        if (empty($row->enabled)) {
            return false;
        }
        if (method_exists($config, 'is_reportable') && $config::is_reportable($row)) {
            return true;
        }
        return method_exists($config, 'is_punct_reportable') && $config::is_punct_reportable($row);
    }
}
