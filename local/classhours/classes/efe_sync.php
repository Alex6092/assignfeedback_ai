<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Option EFE : restreint automatiquement aux heures de cours toute activité
 * qui a une remontée EFE.
 *
 * La condition posée est MARQUÉE ({"type":"classhours","efe":1}) : la
 * synchronisation ne retire jamais que ses propres conditions, une restriction
 * posée à la main reste en place.
 *
 * Une activité est voulue restreinte quand :
 *   - l'option est active pour le cours ;
 *   - le cours a au moins un créneau (sans créneau, l'option n'a aucun effet :
 *     le défaut du site ne peut pas bloquer un cours qui n'utilise pas les
 *     heures de cours) ;
 *   - l'activité a une remontée EFE ;
 *   - elle n'est pas exclue.
 *
 * Déclencheurs : enregistrement du formulaire d'une activité (en fin de
 * requête, voir defer_cm), page Heures de cours, et tâche planifiée sync_efe
 * (activités configurées par programme, restaurations).
 */
class efe_sync {

    /** @var int[] activités à synchroniser en fin de requête */
    private static $deferred = array();

    /**
     * Activités du cours à restreindre.
     *
     * @return int[]|null cmid => cmid ; null : on ne sait pas décider (condition
     *                    désactivée, EFE illisible), on ne touche à rien
     */
    public static function wanted_cmids(int $courseid): ?array {
        if (!availability_json::condition_enabled()) {
            return null;
        }
        if (!store::get_efeauto($courseid) || !efe_bridge::available()
                || !schedule::for_course($courseid)->has_slots()) {
            return array();
        }
        $cmids = efe_bridge::reporting_cmids($courseid);
        if ($cmids === null) {
            return null;
        }
        return array_diff_key($cmids, store::excluded_cmids($courseid));
    }

    /**
     * Synchronise toutes les activités d'un cours.
     *
     * @return int nombre d'activités modifiées
     */
    public static function sync_course(int $courseid): int {
        global $DB;
        $wanted = self::wanted_cmids($courseid);
        if ($wanted === null) {
            return 0;
        }
        $cms = $DB->get_records('course_modules', array('course' => $courseid, 'deletioninprogress' => 0),
            '', 'id, course, availability');
        $changed = 0;
        foreach ($cms as $cm) {
            if (self::apply($cm, isset($wanted[(int)$cm->id]))) {
                $changed++;
            }
        }
        if ($changed) {
            availability_json::rebuild($courseid);
        }
        return $changed;
    }

    /**
     * Synchronise une activité.
     *
     * @return bool vrai si sa restriction a changé
     */
    public static function sync_cm(int $cmid): bool {
        global $DB;
        $cm = $DB->get_record('course_modules', array('id' => $cmid), 'id, course, availability, deletioninprogress');
        if (!$cm || !empty($cm->deletioninprogress)) {
            return false;
        }
        $wanted = self::wanted_cmids((int)$cm->course);
        if ($wanted === null) {
            return false;
        }
        if (!self::apply($cm, isset($wanted[$cmid]))) {
            return false;
        }
        availability_json::rebuild((int)$cm->course);
        return true;
    }

    /**
     * Synchronise l'activité à la FIN de la requête.
     *
     * À l'enregistrement du formulaire d'une activité, EFE écrit sa
     * configuration dans son propre coursemodule_edit_post_actions, appelé
     * APRÈS le nôtre (ordre alphabétique des plugins) ; l'événement
     * course_module_created, lui, est déclenché avant ces rappels. En fin de
     * requête, tout est enregistré, et la redirection vers le cours n'est pas
     * encore terminée.
     */
    public static function defer_cm(int $cmid): void {
        if ($cmid <= 0 || isset(self::$deferred[$cmid])) {
            return;
        }
        if (!self::$deferred) {
            \core_shutdown_manager::register_function(array(self::class, 'run_deferred'));
        }
        self::$deferred[$cmid] = $cmid;
    }

    /** Rappel de fin de requête : ne doit jamais lever d'erreur. */
    public static function run_deferred(): void {
        $cmids = self::$deferred;
        self::$deferred = array();
        foreach ($cmids as $cmid) {
            try {
                schedule::reset_cache();
                self::sync_cm($cmid);
            } catch (\Throwable $e) {
                debugging('local_classhours : synchronisation EFE de l\'activité ' . $cmid
                    . ' impossible — ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Pose ou retire la condition marquée d'une activité.
     *
     * @param \stdClass $cm   id, course, availability
     * @param bool      $want l'activité doit-elle être restreinte ?
     * @return bool vrai si la restriction a changé
     */
    private static function apply(\stdClass $cm, bool $want): bool {
        $json = $cm->availability;
        if ($want && !availability_json::has_root_condition($json)) {
            $new = availability_json::add($json, true);
        } else if (!$want && availability_json::has_root_condition($json, true)) {
            $new = availability_json::remove($json, true);
        } else {
            return false;
        }
        availability_json::store((int)$cm->course, (int)$cm->id, $new);
        return true;
    }
}
