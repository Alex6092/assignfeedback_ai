<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Observateurs d'événements du cœur.
 */
class observer {

    /**
     * Activité créée ou modifiée : met le brief en file s'il manque ou si le
     * corrigé a changé.
     *
     * Ce n'est pas fait dans local_aichat_coursemodule_edit_post_actions() :
     * à la création d'un devoir, mod_assign n'enregistre pas encore la
     * configuration de la correction IA (le devoir n'a pas d'identifiant quand
     * il appelle les plugins) ; c'est l'observateur de assignfeedback_ai qui
     * s'en charge, après la validation de la transaction. Le brief ne pouvait
     * donc jamais partir à la création. Cet observateur passe après lui.
     *
     * @param \core\event\base $event course_module_created ou course_module_updated
     */
    public static function course_module_saved(\core\event\base $event) {
        $other = (array)$event->other;
        if (!isset($other['modulename']) || !in_array($other['modulename'], activity::SUPPORTED_MODS, true)) {
            return;
        }
        try {
            brief::schedule_if_stale((int)$event->objectid);
        } catch (\Throwable $e) {
            // Ne jamais faire échouer l'enregistrement de l'activité pour ça :
            // l'enseignant peut lancer la génération depuis la page du tuteur.
            debugging('local_aichat brief: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

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
