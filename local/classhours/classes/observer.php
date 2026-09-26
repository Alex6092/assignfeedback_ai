<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Nettoyage des données du plugin quand un cours, un groupe ou une activité
 * disparaît.
 */
class observer {

    public static function course_deleted(\core\event\course_deleted $event) {
        store::delete_course((int)$event->objectid);
    }

    /** Les créneaux d'un groupe supprimé ne concernent plus personne. */
    public static function group_deleted(\core\event\group_deleted $event) {
        store::delete_group((int)$event->objectid, (int)$event->courseid);
    }

    public static function course_module_deleted(\core\event\course_module_deleted $event) {
        store::delete_cm((int)$event->objectid);
    }
}
