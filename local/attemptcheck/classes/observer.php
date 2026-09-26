<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

use local_attemptcheck\task\analyse_item;

/**
 * Analyse après chaque remise, et nettoyage des décisions.
 */
class observer {

    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        if (notifier::enabled()) {
            analyse_item::queue('quiz', (int)$event->objectid);
        }
    }

    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event) {
        if (notifier::enabled()) {
            analyse_item::queue('assign', (int)$event->objectid);
        }
    }

    public static function attempt_deleted(\mod_quiz\event\attempt_deleted $event) {
        review::forget('quiz', (int)$event->objectid);
    }

    public static function course_module_deleted(\core\event\course_module_deleted $event) {
        review::delete_cm((int)$event->objectid);
    }

    public static function course_deleted(\core\event\course_deleted $event) {
        review::delete_course((int)$event->objectid);
    }
}
