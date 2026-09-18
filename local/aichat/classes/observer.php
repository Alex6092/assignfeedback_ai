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

    /**
     * Cours supprimé : la suppression d'un cours entier ne déclenche PAS
     * d'événement par activité, d'où ce rattrapage par identifiant de cours.
     */
    public static function course_deleted(\core\event\course_deleted $event) {
        foreach (activity::cmids_for_course((int)$event->objectid) as $cmid) {
            activity::delete_for_cmid($cmid);
        }
    }

    /**
     * Cours réinitialisé : les plugins locaux ne reçoivent pas de rappel de
     * réinitialisation (réservé aux modules), mais l'événement de fin transmet
     * toutes les options cochées.
     *
     * Les conversations suivent le sort des remises : si l'enseignant efface
     * les remises des devoirs, il repart de zéro pour ces activités, et les
     * échanges des anciens élèves avec le tuteur n'ont plus lieu d'être. La
     * configuration du tuteur (et son brief) est conservée pour la réutilisation.
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event) {
        $options = isset($event->other['reset_options']) ? (array)$event->other['reset_options'] : array();
        if (empty($options['reset_assign_submissions'])) {
            return;
        }
        $courseid = !empty($options['courseid']) ? (int)$options['courseid'] : (int)$event->courseid;
        foreach (activity::cmids_for_course($courseid, 'assign') as $cmid) {
            activity::delete_conversations_for_cmid($cmid);
        }
    }
}
