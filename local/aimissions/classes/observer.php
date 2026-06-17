<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Observateurs de cycle de vie pour local_aimissions.
 */
class observer {

    /**
     * Un module de cours vient d'être supprimé. Si c'est le devoir d'une
     * mission générée, on supprime la ligne mission correspondante et on
     * rembobine le projet (pour qu'une nouvelle génération reparte du même
     * sprint). Évite les lignes orphelines impossibles à nettoyer.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $other = (array)$event->other;
        if (($other['modulename'] ?? '') !== 'assign') {
            return;
        }
        $cmid = (int)$event->objectid;
        if ($cmid <= 0) {
            return;
        }

        // On ne touche QUE nos propres missions (la config EFE est nettoyée par
        // delete_mission_row, donc scoped à nos devoirs — pas aux autres).
        $mission = $DB->get_record(job_handler::TABLE_MISSION, array('assigncmid' => $cmid));
        if (!$mission) {
            return;
        }
        // Le module est déjà supprimé par le coeur : on ne réappelle pas
        // course_delete_module, on nettoie juste notre ligne + le projet.
        mission_manager::delete_mission_row($mission, true);
    }

    /**
     * Un cours est supprimé : purge toutes les données du plugin pour ce cours
     * (projets, missions, tickets, événements, jobs).
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = (int)$event->objectid;
        if ($courseid <= 0) {
            return;
        }

        $projectids = $DB->get_fieldset_select(job_handler::TABLE_PROJECT, 'id',
            'courseid = ?', array($courseid));
        if (!empty($projectids)) {
            list($insql, $params) = $DB->get_in_or_equal($projectids);
            $DB->delete_records_select(job_handler::TABLE_MISSION, "projectid $insql", $params);
            $DB->delete_records_select('local_aimissions_ticket', "projectid $insql", $params);
            $DB->delete_records_select('local_aimissions_event',  "projectid $insql", $params);
        }
        $DB->delete_records(job_handler::TABLE_PROJECT, array('courseid' => $courseid));
        $DB->delete_records(job_handler::TABLE_JOB,     array('courseid' => $courseid));
    }
}
