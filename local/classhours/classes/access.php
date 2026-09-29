<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Exceptions à la fermeture d'une activité restreinte aux heures de cours, et
 * question « cette activité est-elle fermée pour cet élève maintenant ? ».
 *
 * Deux exceptions ouvrent une activité hors créneau :
 *   - la LECTURE : un élève qui a déjà une note ou un feedback sur un devoir,
 *     ou une tentative terminée sur un test, peut rouvrir l'activité pour les
 *     lire. Sans cela, un feedback IA arrivé après la fin du créneau restait
 *     invisible jusqu'au créneau suivant. Le verrou de remise (lib.php) et la
 *     règle d'accès des tests empêchent pour autant tout nouveau travail ;
 *   - l'ACCÈS PONCTUEL accordé par un enseignant (voir grant), qui, lui, ouvre
 *     aussi la remise.
 */
class access {

    /** Types d'activité concernés par la lecture hors créneau et l'accès ponctuel. */
    const READ_MODS = array('assign', 'quiz');

    /** Actions de mod/assign/view.php qui modifient une remise. */
    const WRITE_ACTIONS = array('editsubmission', 'savesubmission', 'submit', 'confirmsubmit',
        'editprevioussubmission', 'removesubmission', 'removesubmissionconfirm');

    /** @var array "courseid|userid" => ['assign' => [id => true], 'quiz' => [id => true]] */
    private static $released = array();

    /** @var bool|null la table du feedback IA existe-t-elle ? */
    private static $aitable = null;

    /** Oublie l'état mémorisé pour la requête (dans les tests, après une notation). */
    public static function reset_cache(): void {
        self::$released = array();
        grant::reset_cache();
    }

    /**
     * Instances de devoir et de test du cours où l'élève a déjà de quoi lire.
     * Une requête par type, mémorisée pour la requête.
     *
     * Devoir : une note, un commentaire de l'enseignant ou un feedback IA
     * généré. Si le suivi d'évaluation (marking workflow) est actif, seulement
     * une fois publié : avant, le cœur cache de toute façon le feedback.
     * Test : au moins une tentative terminée.
     *
     * @return array ['assign' => [instanceid => true], 'quiz' => [instanceid => true]]
     */
    public static function released_work(int $courseid, int $userid): array {
        global $DB;
        $key = $courseid . '|' . $userid;
        if (isset(self::$released[$key])) {
            return self::$released[$key];
        }

        $feedback = array(
            'g.grade >= 0',
            "EXISTS (SELECT 1 FROM {assignfeedback_comments} fc
                      WHERE fc.grade = g.id AND fc.commenttext IS NOT NULL AND fc.commenttext <> '')",
        );
        if (self::ai_table_exists()) {
            $feedback[] = "EXISTS (SELECT 1 FROM {assignfeedback_ai_grade} ai
                                   WHERE ai.grade = g.id AND ai.status = 'generated')";
        }
        $assigns = $DB->get_fieldset_sql(
            "SELECT DISTINCT a.id
               FROM {assign} a
               JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = :userid
          LEFT JOIN {assign_user_flags} uf ON uf.assignment = a.id AND uf.userid = :userid2
              WHERE a.course = :courseid
                AND (a.markingworkflow = 0 OR uf.workflowstate = :released)
                AND (" . implode(' OR ', $feedback) . ")",
            array('userid' => $userid, 'userid2' => $userid, 'courseid' => $courseid, 'released' => 'released'));

        $quizzes = $DB->get_fieldset_sql(
            "SELECT DISTINCT q.id
               FROM {quiz} q
               JOIN {quiz_attempts} qa ON qa.quiz = q.id
              WHERE q.course = :courseid AND qa.userid = :userid AND qa.state = :finished",
            array('courseid' => $courseid, 'userid' => $userid, 'finished' => 'finished'));

        return self::$released[$key] = array(
            'assign' => array_fill_keys(array_map('intval', $assigns), true),
            'quiz'   => array_fill_keys(array_map('intval', $quizzes), true),
        );
    }

    /**
     * Une exception ouvre-t-elle cette activité à cet élève hors créneau ?
     *
     * N'utilise que les champs de base du module (id, modname, instance) : la
     * condition d'accès est évaluée pendant la construction des données
     * dynamiques du module, où les accesseurs magiques sont interdits.
     *
     * @param int                $courseid
     * @param \cm_info|\stdClass $cm
     * @param int                $userid
     */
    public static function exception_applies(int $courseid, $cm, int $userid): bool {
        if (!in_array($cm->modname, self::READ_MODS, true)) {
            return false;
        }
        if (grant::is_active($courseid, (int)$cm->id, $userid)) {
            return true;
        }
        return isset(self::released_work($courseid, $userid)[$cm->modname][(int)$cm->instance]);
    }

    /**
     * L'activité est-elle fermée à cet élève en ce moment, pour du TRAVAIL
     * (remise, Tuteur IA) ? La lecture hors créneau ne compte pas ici : seul un
     * accès ponctuel rouvre le travail.
     *
     * @param \cm_info|\stdClass $cm
     * @param int                $userid
     */
    public static function is_closed_for($cm, int $userid): bool {
        if (empty($cm->availability) || !availability_json::has_root_condition($cm->availability)) {
            return false;
        }
        if (!availability_json::condition_enabled()) {
            return false;
        }
        $context = \context_module::instance((int)$cm->id);
        if (has_capability('moodle/course:ignoreavailabilityrestrictions', $context, $userid)) {
            return false;
        }
        $courseid = (int)$cm->course;
        if (grant::is_active($courseid, (int)$cm->id, $userid)) {
            return false;
        }
        return !schedule::for_course($courseid)->is_open_for_user($userid);
    }

    private static function ai_table_exists(): bool {
        global $DB;
        if (self::$aitable === null) {
            self::$aitable = $DB->get_manager()->table_exists('assignfeedback_ai_grade');
        }
        return self::$aitable;
    }
}
