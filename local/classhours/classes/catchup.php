<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Rattrapage des activités surveillées.
 *
 * Une activité est « à rattraper » pour un élève quand une séance a eu lieu
 * pour lui (une ouverture qui le concernait, terminée pour lui) et qu'il n'a
 * rien rendu :
 *   - test : aucune tentative terminée ;
 *   - devoir : pas de remise envoyée ni de note ;
 *   - autre activité : achèvement suivi et non atteint. Sans suivi
 *     d'achèvement, on ne sait pas : jamais « à rattraper » (pas de fausse
 *     alerte).
 *
 * L'élève peut demander un rattrapage ; les enseignants sont prévenus, et
 * ouvrir l'activité pour lui marque la demande comme traitée.
 */
class catchup {

    const TABLE = 'local_classhours_catchup';

    const PENDING   = 'pending';
    const DONE      = 'done';
    const CANCELLED = 'cancelled';

    /** @var array courseid => ouvertures du cours (toutes, historique compris) */
    private static $openings = array();

    /** @var array "courseid|userid" => travail rendu par l'élève */
    private static $work = array();

    public static function reset_cache(): void {
        self::$openings = array();
        self::$work = array();
    }

    // -------------------------------------------------------------------------
    //  Séances et travail rendu
    // -------------------------------------------------------------------------

    /** Toutes les ouvertures d'un cours (une requête par cours). */
    private static function openings(int $courseid): array {
        global $DB;
        if (!isset(self::$openings[$courseid])) {
            self::$openings[$courseid] = $DB->get_records(gate::TABLE, array('courseid' => $courseid), 'timeopened, id',
                'id, courseid, cmid, scope, scopeid, timeopened, closeat, timeclosed');
        }
        return self::$openings[$courseid];
    }

    /**
     * Séances terminées pour l'élève : cmid => heure de la dernière.
     *
     * @return int[]
     */
    public static function sessions_held(int $courseid, int $userid, ?int $now = null): array {
        $now = $now ?? schedule::now();
        $percent = extratime::percent($courseid, $userid);
        $groupids = null;
        $held = array();
        foreach (self::openings($courseid) as $o) {
            if ($o->scope === gate::SCOPE_GROUP && $groupids === null) {
                $groupids = gate::user_groupids($courseid, $userid);
            }
            if (!gate::applies($o, $userid, $groupids ?? array())) {
                continue;
            }
            if (!empty($o->timeclosed)) {
                $end = min((int)$o->timeclosed, gate::user_end($o, $percent) ?: (int)$o->timeclosed);
            } else {
                $end = gate::user_end($o, $percent);
                if ($end === 0 || $end > $now) {
                    continue; // Séance en cours pour lui.
                }
            }
            $held[(int)$o->cmid] = max($held[(int)$o->cmid] ?? 0, (int)$o->timeopened);
        }
        return $held;
    }

    /**
     * Travail rendu par l'élève dans le cours : tests terminés, devoirs remis
     * ou notés, activités achevées. Trois requêtes, mémorisées.
     *
     * @return array ['quiz' => [instance => true], 'assign' => [instance => true], 'complete' => [cmid => true]]
     */
    public static function work_done(int $courseid, int $userid): array {
        global $DB;
        $key = $courseid . '|' . $userid;
        if (isset(self::$work[$key])) {
            return self::$work[$key];
        }
        $quizzes = $DB->get_fieldset_sql(
            "SELECT DISTINCT q.id FROM {quiz} q JOIN {quiz_attempts} qa ON qa.quiz = q.id
              WHERE q.course = :courseid AND qa.userid = :userid AND qa.state = :finished AND qa.preview = 0",
            array('courseid' => $courseid, 'userid' => $userid, 'finished' => 'finished'));
        $assigns = $DB->get_fieldset_sql(
            "SELECT DISTINCT a.id FROM {assign} a
              WHERE a.course = :courseid
                AND (EXISTS (SELECT 1 FROM {assign_submission} s
                              WHERE s.assignment = a.id AND s.userid = :userid1 AND s.latest = 1 AND s.status = :submitted)
                     OR EXISTS (SELECT 1 FROM {assign_submission} s
                                  JOIN {groups_members} gm ON gm.groupid = s.groupid AND gm.userid = :userid2
                                 WHERE s.assignment = a.id AND s.userid = 0 AND s.latest = 1 AND s.status = :submitted2)
                     OR EXISTS (SELECT 1 FROM {assign_grades} g
                                 WHERE g.assignment = a.id AND g.userid = :userid3 AND g.grade >= 0))",
            array('courseid' => $courseid, 'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid,
                'submitted' => 'submitted', 'submitted2' => 'submitted'));
        $complete = $DB->get_fieldset_sql(
            "SELECT cmc.coursemoduleid FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.userid = :userid AND cmc.completionstate > 0",
            array('courseid' => $courseid, 'userid' => $userid));
        return self::$work[$key] = array(
            'quiz'     => array_fill_keys(array_map('intval', $quizzes), true),
            'assign'   => array_fill_keys(array_map('intval', $assigns), true),
            'complete' => array_fill_keys(array_map('intval', $complete), true),
        );
    }

    /**
     * L'élève a-t-il rendu ce travail ? null si on ne peut pas le savoir
     * (activité sans suivi d'achèvement, autre qu'un test ou un devoir).
     *
     * @param \cm_info|\stdClass $cm (id, modname, instance, completion)
     */
    public static function is_done(int $courseid, $cm, int $userid): ?bool {
        $work = self::work_done($courseid, $userid);
        if ($cm->modname === 'quiz' || $cm->modname === 'assign') {
            return isset($work[$cm->modname][(int)$cm->instance]);
        }
        if (empty($cm->completion)) {
            return null;
        }
        return isset($work['complete'][(int)$cm->id]);
    }

    /** L'activité est-elle à rattraper pour l'élève ? */
    public static function is_due(int $courseid, $cm, int $userid, ?int $now = null): bool {
        if (!isset(self::sessions_held($courseid, $userid, $now)[(int)$cm->id])) {
            return false;
        }
        if (gate::in_window($courseid, (int)$cm->id, $userid, $now)) {
            return false;
        }
        return self::is_done($courseid, $cm, $userid) === false;
    }

    // -------------------------------------------------------------------------
    //  Demandes
    // -------------------------------------------------------------------------

    /**
     * Demandes en attente d'un élève dans un cours.
     *
     * @return int[] cmid => id de la demande
     */
    public static function pending_for(int $courseid, int $userid): array {
        global $DB;
        $out = array();
        foreach ($DB->get_records(self::TABLE, array('courseid' => $courseid, 'userid' => $userid,
                'status' => self::PENDING), 'id', 'id, cmid') as $row) {
            $out[(int)$row->cmid] = (int)$row->id;
        }
        return $out;
    }

    /**
     * Demandes en attente d'un cours (vue enseignant).
     *
     * @return array cmid => [userid => id]
     */
    public static function pending_for_course(int $courseid): array {
        global $DB;
        $out = array();
        foreach ($DB->get_records(self::TABLE, array('courseid' => $courseid, 'status' => self::PENDING), 'id',
                'id, cmid, userid') as $row) {
            $out[(int)$row->cmid][(int)$row->userid] = (int)$row->id;
        }
        return $out;
    }

    /**
     * L'élève demande à rattraper une activité. Une demande déjà en attente
     * est renvoyée telle quelle, sans nouvelle notification.
     *
     * @return \stdClass la demande
     */
    public static function request(\cm_info $cm, int $userid): \stdClass {
        global $DB;
        $existing = $DB->get_record(self::TABLE, array('cmid' => (int)$cm->id, 'userid' => $userid,
            'status' => self::PENDING));
        if ($existing) {
            return $existing;
        }
        $row = (object)array(
            'courseid'    => (int)$cm->course,
            'cmid'        => (int)$cm->id,
            'userid'      => $userid,
            'status'      => self::PENDING,
            'timecreated' => schedule::now(),
            'timedone'    => 0,
        );
        $row->id = $DB->insert_record(self::TABLE, $row);
        notifier::catchup_requested($cm, $userid);
        return $row;
    }

    /** L'enseignant a ouvert l'activité pour l'élève : sa demande est traitée. */
    public static function mark_done(int $cmid, int $userid): void {
        global $DB;
        $DB->execute('UPDATE {' . self::TABLE . '} SET status = :done, timedone = :now
                       WHERE cmid = :cmid AND userid = :userid AND status = :pending',
            array('done' => self::DONE, 'now' => schedule::now(), 'cmid' => $cmid, 'userid' => $userid,
                'pending' => self::PENDING));
    }

    /**
     * Élèves à rattraper sur une activité (vue enseignant).
     *
     * @param int      $courseid
     * @param \cm_info $cm
     * @param int[]    $userids élèves du cours
     * @return int[] ids des élèves à rattraper
     */
    public static function students_due(int $courseid, \cm_info $cm, array $userids): array {
        $out = array();
        foreach ($userids as $userid) {
            if (self::is_due($courseid, $cm, (int)$userid)) {
                $out[] = (int)$userid;
            }
        }
        return $out;
    }
}
