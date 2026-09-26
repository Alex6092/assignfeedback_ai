<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Décisions des enseignants (tentative jugée légitime) et durée minimale
 * attendue par activité.
 */
class review {

    const TABLE = 'local_attemptcheck_review';
    const ACTIVITY = 'local_attemptcheck_activity';

    /**
     * Tentatives jugées légitimes dans le cours.
     *
     * @return \stdClass[] clé d'item (quiz:12) => ligne
     */
    public static function legit(int $courseid): array {
        global $DB;
        $out = array();
        foreach ($DB->get_records(self::TABLE, array('courseid' => $courseid)) as $row) {
            $out[$row->itemtype . ':' . $row->itemid] = $row;
        }
        return $out;
    }

    public static function is_legit(string $type, int $itemid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, array('itemtype' => $type, 'itemid' => $itemid));
    }

    /**
     * Marque (ou démarque) une tentative comme légitime.
     */
    public static function set_legit(int $courseid, int $cmid, string $type, int $itemid, int $userid, bool $legit): void {
        global $DB, $USER;
        $existing = $DB->get_record(self::TABLE, array('itemtype' => $type, 'itemid' => $itemid));
        if (!$legit) {
            if ($existing) {
                $DB->delete_records(self::TABLE, array('id' => $existing->id));
            }
            return;
        }
        $record = (object)array(
            'courseid'     => $courseid,
            'cmid'         => $cmid,
            'itemtype'     => $type,
            'itemid'       => $itemid,
            'userid'       => $userid,
            'status'       => 'legit',
            'reviewerid'   => (int)$USER->id,
            'timemodified' => time(),
        );
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /** Oublie la décision d'une tentative supprimée. */
    public static function forget(string $type, int $itemid): void {
        global $DB;
        $DB->delete_records(self::TABLE, array('itemtype' => $type, 'itemid' => $itemid));
    }

    /** Durée minimale attendue d'une activité (secondes, 0 = aucune). */
    public static function get_minduration(int $cmid): int {
        global $DB;
        return (int)$DB->get_field(self::ACTIVITY, 'minduration', array('cmid' => $cmid));
    }

    public static function set_minduration(int $cmid, int $courseid, int $seconds): void {
        global $DB;
        $seconds = max(0, $seconds);
        $id = $DB->get_field(self::ACTIVITY, 'id', array('cmid' => $cmid));
        if ($seconds === 0) {
            if ($id) {
                $DB->delete_records(self::ACTIVITY, array('id' => $id));
            }
            return;
        }
        $record = (object)array('cmid' => $cmid, 'courseid' => $courseid, 'minduration' => $seconds,
            'timemodified' => time());
        if ($id) {
            $record->id = $id;
            $DB->update_record(self::ACTIVITY, $record);
        } else {
            $DB->insert_record(self::ACTIVITY, $record);
        }
    }

    public static function delete_cm(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, array('cmid' => $cmid));
        $DB->delete_records(self::ACTIVITY, array('cmid' => $cmid));
    }

    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, array('courseid' => $courseid));
        $DB->delete_records(self::ACTIVITY, array('courseid' => $courseid));
    }
}
