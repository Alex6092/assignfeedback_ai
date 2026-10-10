<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Registre des objets créés par le pont (table local_aibridge_created). Le
 * pont ne supprime que ce qu'il a lui-même créé, et seulement tant que
 * l'objet est masqué.
 */
class created {

    const TABLE = 'local_aibridge_created';

    const COURSE = 'course';
    const SECTION = 'section';
    const CM = 'cm';

    /** Inscrit un objet que le pont vient de créer. */
    public static function add(string $type, int $objectid, int $courseid): void {
        global $DB, $USER;
        if ($DB->record_exists(self::TABLE, array('objecttype' => $type, 'objectid' => $objectid))) {
            return;
        }
        $DB->insert_record(self::TABLE, (object)array(
            'objecttype'  => $type,
            'objectid'    => $objectid,
            'courseid'    => $courseid,
            'userid'      => (int)$USER->id,
            'timecreated' => time(),
        ));
    }

    /** L'objet a-t-il été créé par le pont ? */
    public static function has(string $type, int $objectid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, array('objecttype' => $type, 'objectid' => $objectid));
    }

    /** Retire un objet du registre (après sa suppression). */
    public static function remove(string $type, int $objectid): void {
        global $DB;
        $DB->delete_records(self::TABLE, array('objecttype' => $type, 'objectid' => $objectid));
    }

    /** Retire tout ce qui concerne un cours supprimé. */
    public static function remove_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, array('courseid' => $courseid));
    }
}
