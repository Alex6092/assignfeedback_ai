<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Observateurs d'événements du cœur.
 */
class observer {

    /**
     * Activité supprimée : on efface la configuration du tuteur et toutes les
     * conversations qui s'y rapportaient (elles n'ont plus de contexte et ne
     * seraient plus accessibles à personne).
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event) {
        activity::delete_for_cmid((int)$event->objectid);
    }
}
