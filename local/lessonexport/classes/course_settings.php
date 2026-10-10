<?php
namespace local_lessonexport;

defined('MOODLE_INTERNAL') || die();

/**
 * Activation des leçons imprimables, cours par cours. Sans réglage propre au
 * cours, le réglage par défaut du site s'applique.
 */
class course_settings {

    const TABLE = 'local_lessonexport_course';

    /** @var bool[] courseid => activé (cache de la requête) */
    private static $cache = array();

    public static function enabled(int $courseid): bool {
        global $DB;
        if (!array_key_exists($courseid, self::$cache)) {
            $value = $DB->get_field(self::TABLE, 'enabled', array('courseid' => $courseid));
            self::$cache[$courseid] = $value === false
                ? (bool)get_config('local_lessonexport', 'defaultenabled')
                : (bool)$value;
        }
        return self::$cache[$courseid];
    }

    public static function set_enabled(int $courseid, bool $enabled): void {
        global $DB;
        $id = $DB->get_field(self::TABLE, 'id', array('courseid' => $courseid));
        $record = (object)array('courseid' => $courseid, 'enabled' => (int)$enabled, 'timemodified' => time());
        if ($id) {
            $record->id = $id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
        self::$cache[$courseid] = $enabled;
    }

    /**
     * Le compte peut-il ouvrir les leçons imprimables de ce cours ? Les
     * gestionnaires du réglage y ont toujours accès (pour vérifier avant
     * d'activer) ; les autres seulement si le cours l'a activé.
     */
    public static function can_view(int $courseid): bool {
        $context = \context_course::instance($courseid);
        if (has_capability('local/lessonexport:manage', $context)) {
            return true;
        }
        return self::enabled($courseid) && has_capability('local/lessonexport:view', $context);
    }
}
