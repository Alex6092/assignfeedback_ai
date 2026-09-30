<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Ramassage du travail en cours quand une activité surveillée se ferme,
 * comme on ramasse les copies :
 *   - test : les tentatives en cours sont envoyées, datées de la fermeture ;
 *   - devoir avec brouillons exigés : les brouillons sont remis, par l'API du
 *     cœur (événements, notifications, achèvement, ponctualité EFE).
 *
 * Seuls les élèves qui ont PERDU l'accès sont concernés : fermer un groupe ne
 * ramasse rien chez un élève encore ouvert individuellement.
 *
 * S'exécute en tâche (comme administrateur) : remettre le devoir d'un élève
 * demande mod/assign:editothersubmission, que les enseignants n'ont pas par
 * défaut.
 */
class gate_collector {

    /**
     * @param int $cmid
     * @param int $closedat heure de la fermeture (fin des tentatives ramassées)
     * @return int[] ['quiz' => tentatives envoyées, 'assign' => brouillons remis]
     */
    public static function collect(int $cmid, int $closedat): array {
        $done = array('quiz' => 0, 'assign' => 0);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !in_array($cm->modname, gate::COLLECT_MODS, true)) {
            return $done;
        }
        gate::reset_cache();
        if ($cm->modname === 'quiz') {
            $done['quiz'] = self::collect_quiz($cm, $closedat);
        } else {
            $done['assign'] = self::collect_assign($cm);
        }
        return $done;
    }

    /** L'élève a-t-il perdu l'accès à l'activité ? */
    private static function lost_access(\stdClass $cm, int $userid): bool {
        // La fenêtre, pas le code de séance : un élève au tiers-temps garde sa copie.
        return !gate::in_window((int)$cm->course, (int)$cm->id, $userid);
    }

    /** Envoie les tentatives en cours des élèves qui n'ont plus accès. */
    private static function collect_quiz(\stdClass $cm, int $closedat): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $attempts = $DB->get_records_select('quiz_attempts',
            'quiz = :quiz AND preview = 0 AND state IN (:inprogress, :overdue)',
            array('quiz' => (int)$cm->instance, 'inprogress' => 'inprogress', 'overdue' => 'overdue'),
            'id', 'id, userid, timestart');
        $now = schedule::now();
        $count = 0;
        foreach ($attempts as $attempt) {
            if (!self::lost_access($cm, (int)$attempt->userid)) {
                continue;
            }
            try {
                $attemptobj = \mod_quiz\quiz_attempt::create((int)$attempt->id);
                $finish = max($closedat, (int)$attempt->timestart);
                if (method_exists($attemptobj, 'process_submit')) {
                    $attemptobj->process_submit($now, false, $finish, false);
                    $attemptobj->process_grade_submission($now);
                } else {
                    $attemptobj->process_finish($now, false, $finish, false);
                }
                $count++;
            } catch (\Throwable $e) {
                debugging('local_classhours : tentative ' . $attempt->id . ' non ramassée — ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }
        return $count;
    }

    /**
     * Remet les brouillons des élèves qui n'ont plus accès. Sans brouillons
     * exigés, une remise enregistrée est déjà remise : rien à faire. Un
     * brouillon vide, ou que Moodle refuse (date limite de coupure passée),
     * est laissé tel quel.
     */
    private static function collect_assign(\stdClass $cm): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $context = \context_module::instance((int)$cm->id);
        $assign = new \assign($context, $cm, null);
        $instance = $assign->get_instance();
        if (empty($instance->submissiondrafts)) {
            return 0;
        }
        $drafts = $DB->get_records('assign_submission',
            array('assignment' => (int)$instance->id, 'status' => 'draft', 'latest' => 1),
            'id', 'id, userid, groupid');

        $count = 0;
        foreach ($drafts as $draft) {
            // Remise de groupe (userid = 0) : un membre qui a perdu l'accès remet pour le groupe.
            $userid = (int)$draft->userid;
            if ($userid === 0) {
                $userid = self::group_member_without_access($cm, (int)$draft->groupid);
                if ($userid === 0) {
                    continue;
                }
            } else if (!self::lost_access($cm, $userid)) {
                continue;
            }
            try {
                $submission = $DB->get_record('assign_submission', array('id' => $draft->id));
                if (method_exists($assign, 'submission_empty') && $assign->submission_empty($submission)) {
                    continue;
                }
                $notices = array();
                if ($assign->submit_for_grading((object)array('userid' => $userid), $notices)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                debugging('local_classhours : brouillon ' . $draft->id . ' non remis — ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }
        return $count;
    }

    /** Un membre du groupe qui a perdu l'accès, ou 0 (aucun, ou groupe par défaut). */
    private static function group_member_without_access(\stdClass $cm, int $groupid): int {
        if ($groupid <= 0) {
            return 0;
        }
        foreach (groups_get_members($groupid, 'u.id', 'u.id') as $member) {
            if (self::lost_access($cm, (int)$member->id)) {
                return (int)$member->id;
            }
        }
        return 0;
    }
}
