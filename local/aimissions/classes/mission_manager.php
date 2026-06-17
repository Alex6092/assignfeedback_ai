<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Opérations de cycle de vie sur les missions : suppression propre (devoir +
 * ligne + rembobinage du projet), avec un point d'entrée unique pour éviter
 * les doubles traitements entre l'UI et l'observer course_module_deleted.
 */
class mission_manager {

    /**
     * Supprime une mission « pour de vrai » depuis l'UI :
     *   - si le devoir existe encore → course_delete_module() (le coeur supprime
     *     le module, les notes, les soumissions ; l'observer nettoiera notre
     *     ligne + rembobinera le projet) ;
     *   - sinon (orpheline : devoir déjà supprimé) → on nettoie directement.
     *
     * @return bool true si quelque chose a été supprimé.
     */
    public static function delete_mission_full(int $missionid): bool {
        global $DB, $CFG;

        $mission = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid));
        if (!$mission) {
            return false;
        }

        $cmid = (int)$mission->assigncmid;
        if ($cmid > 0 && $DB->record_exists('course_modules', array('id' => $cmid))) {
            require_once($CFG->dirroot . '/course/lib.php');
            // Déclenche course_module_deleted → observer::course_module_deleted
            // qui supprime la ligne mission et rembobine le projet.
            course_delete_module($cmid);
            // Filet de sécurité si l'observer n'a pas matché (course_delete_module
            // peut être asynchrone selon la config) : on nettoie la ligne restante.
            $still = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid));
            if ($still) {
                self::delete_mission_row($still, true);
            }
            return true;
        }

        // Mission orpheline : pas (ou plus) de devoir.
        self::delete_mission_row($mission, true);
        return true;
    }

    /**
     * Supprime la ligne mission et, si demandé, rembobine le projet (uniquement
     * si la mission supprimée était bien le DERNIER sprint du projet).
     *
     * Appelée par l'observer (réconciliation après suppression du module) et,
     * en filet de sécurité, par delete_mission_full().
     */
    public static function delete_mission_row(\stdClass $mission, bool $rollback): void {
        global $DB;

        if ((int)$mission->assigncmid > 0) {
            efe_bridge::detach((int)$mission->assigncmid);
        }
        $DB->delete_records(job_handler::TABLE_MISSION, array('id' => (int)$mission->id));

        if ($rollback) {
            self::rollback_project((int)$mission->projectid, (int)$mission->sprint);
        }
    }

    /**
     * Rembobine le projet d'un cran si on vient de supprimer son dernier sprint :
     * décrémente currentsprint et retire la dernière entrée du dossier, afin
     * qu'une nouvelle génération reparte du même numéro de sprint.
     */
    private static function rollback_project(int $projectid, int $sprint): void {
        global $DB;

        $project = $DB->get_record(job_handler::TABLE_PROJECT, array('id' => $projectid));
        if (!$project) {
            return;
        }
        // On ne rembobine que si on a retiré le sprint courant (le plus récent).
        if ((int)$project->currentsprint !== $sprint) {
            return;
        }
        $dossier = json_decode((string)$project->dossier, true) ?: array();
        if (!empty($dossier['history']) && is_array($dossier['history'])) {
            array_pop($dossier['history']);
        }
        $update = new \stdClass();
        $update->id            = $projectid;
        $update->currentsprint = max(0, $sprint - 1);
        $update->dossier       = json_encode($dossier, JSON_UNESCAPED_UNICODE);
        $update->timemodified  = time();
        $DB->update_record(job_handler::TABLE_PROJECT, $update);
    }
}
