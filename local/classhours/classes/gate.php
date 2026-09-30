<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Activités surveillées : l'enseignant les ouvre et les ferme lui-même, en
 * classe (condition d'accès availability_supervised).
 *
 * Une ligne de local_classhours_gate par ouverture, pour tout le cours, un
 * groupe ou un élève, avec une fermeture automatique facultative. Les lignes
 * fermées restent comme historique.
 *
 * Une ouverture est active tant qu'elle n'est pas fermée ET que son heure de
 * fermeture automatique n'est pas passée : l'activité se referme donc à
 * l'heure prévue même avant le passage de la tâche close_supervised, qui ne
 * fait que la marquer fermée et ramasser le travail en cours.
 */
class gate {

    /** Table des ouvertures. */
    const TABLE = 'local_classhours_gate';

    /** Type de la condition d'accès (availability_supervised). */
    const TYPE = 'supervised';

    const SCOPE_COURSE = 'course';
    const SCOPE_GROUP  = 'group';
    const SCOPE_USER   = 'user';

    /** Durée « jusqu'à ce que je ferme ». */
    const MANUAL = 0;

    /** Durée « jusqu'à la fin du créneau » (Heures de cours). */
    const UNTIL_SLOT_END = -1;

    /** Durées proposées à l'ouverture (secondes). */
    const DURATIONS = array(self::MANUAL, 900, 1800, 3600, 7200, self::UNTIL_SLOT_END);

    /** Types d'activité dont le travail en cours est ramassé à la fermeture. */
    const COLLECT_MODS = array('assign', 'quiz');

    /** @var array courseid => ouvertures non fermées du cours (y compris expirées) */
    private static $cache = array();

    /** Oublie les ouvertures mémorisées pour la requête (après un changement, dans les tests). */
    public static function reset_cache(): void {
        self::$cache = array();
    }

    // -------------------------------------------------------------------------
    //  Lecture
    // -------------------------------------------------------------------------

    /**
     * Ouvertures actives d'un cours. Une requête par cours, mémorisée : la
     * page du cours évalue la condition pour chaque activité surveillée.
     *
     * @param int      $courseid
     * @param int|null $now
     * @return \stdClass[] id => ouverture
     */
    public static function active_for_course(int $courseid, ?int $now = null): array {
        global $DB;
        if (!isset(self::$cache[$courseid])) {
            self::$cache[$courseid] = $DB->get_records(self::TABLE,
                array('courseid' => $courseid, 'timeclosed' => 0), 'timeopened, id',
                'id, courseid, cmid, scope, scopeid, openedby, timeopened, closeat');
        }
        $now = $now ?? schedule::now();
        return array_filter(self::$cache[$courseid], function($o) use ($now) {
            return self::is_active($o, $now);
        });
    }

    /**
     * Ouvertures actives d'une activité.
     *
     * @return \stdClass[] id => ouverture
     */
    public static function active_for_cm(int $courseid, int $cmid, ?int $now = null): array {
        return array_filter(self::active_for_course($courseid, $now), function($o) use ($cmid) {
            return (int)$o->cmid === $cmid;
        });
    }

    /** Une ouverture est-elle active à $now ? */
    public static function is_active(\stdClass $opening, int $now): bool {
        return empty($opening->timeclosed) && ((int)$opening->closeat === 0 || (int)$opening->closeat > $now);
    }

    /** L'activité est-elle ouverte pour cet élève ? */
    public static function is_open_for(int $courseid, int $cmid, int $userid, ?int $now = null): bool {
        return self::end_for($courseid, $cmid, $userid, $now) !== null;
    }

    /**
     * Fin de l'accès de l'élève : null s'il n'est pas ouvert, 0 si une de ses
     * ouvertures n'a pas de fermeture automatique, sinon la plus lointaine.
     */
    public static function end_for(int $courseid, int $cmid, int $userid, ?int $now = null): ?int {
        $end = null;
        $groupids = null;
        foreach (self::active_for_cm($courseid, $cmid, $now) as $opening) {
            if ($opening->scope === self::SCOPE_GROUP && $groupids === null) {
                $groupids = self::user_groupids($courseid, $userid);
            }
            if (!self::applies($opening, $userid, $groupids ?? array())) {
                continue;
            }
            $closeat = (int)$opening->closeat;
            if ($closeat === 0) {
                return 0;
            }
            $end = max($end ?? 0, $closeat);
        }
        return $end;
    }

    /** L'ouverture concerne-t-elle cet élève ? */
    public static function applies(\stdClass $opening, int $userid, array $groupids): bool {
        switch ($opening->scope) {
            case self::SCOPE_COURSE:
                return true;
            case self::SCOPE_GROUP:
                return in_array((int)$opening->scopeid, $groupids, true);
            case self::SCOPE_USER:
                return (int)$opening->scopeid === $userid;
        }
        return false;
    }

    /**
     * Groupes de l'élève dans le cours (le cœur les met en cache).
     *
     * @return int[]
     */
    public static function user_groupids(int $courseid, int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/grouplib.php');
        $groups = groups_get_user_groups($courseid, $userid);
        return array_map('intval', array_values($groups[0] ?? array()));
    }

    /**
     * Dernières ouvertures d'un cours, pour l'historique.
     *
     * @return \stdClass[]
     */
    public static function history(int $courseid, int $limit = 20): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, array('courseid' => $courseid),
            'timeopened DESC, id DESC', '*', 0, $limit));
    }

    // -------------------------------------------------------------------------
    //  Ouvrir, fermer
    // -------------------------------------------------------------------------

    /**
     * Ouvre une activité. Une ouverture active pour la même cible est
     * prolongée (ou rendue manuelle) au lieu d'être doublée.
     *
     * @param \cm_info|\stdClass $cm
     * @param string             $scope    course|group|user
     * @param int                $scopeid  groupe ou élève (0 pour le cours)
     * @param int                $duration secondes, MANUAL ou UNTIL_SLOT_END
     * @param int                $teacherid
     * @return \stdClass l'ouverture
     * @throws \moodle_exception « fin du créneau » sans créneau en cours
     */
    public static function open($cm, string $scope, int $scopeid, int $duration, int $teacherid): \stdClass {
        global $DB;
        if (!in_array($scope, array(self::SCOPE_COURSE, self::SCOPE_GROUP, self::SCOPE_USER), true)) {
            throw new \coding_exception('scope inconnu : ' . $scope);
        }
        if ($scope === self::SCOPE_COURSE) {
            $scopeid = 0;
        }
        $courseid = (int)$cm->course;
        $now = schedule::now();
        $closeat = self::close_time($courseid, $scope, $scopeid, $duration, $now);

        foreach (self::active_for_cm($courseid, (int)$cm->id, $now) as $opening) {
            if ($opening->scope === $scope && (int)$opening->scopeid === $scopeid) {
                $DB->update_record(self::TABLE, (object)array('id' => $opening->id, 'closeat' => $closeat));
                self::reset_cache();
                $opening->closeat = $closeat;
                return $opening;
            }
        }

        $opening = (object)array(
            'courseid'   => $courseid,
            'cmid'       => (int)$cm->id,
            'scope'      => $scope,
            'scopeid'    => $scopeid,
            'openedby'   => $teacherid,
            'timeopened' => $now,
            'closeat'    => $closeat,
            'closedby'   => 0,
            'timeclosed' => 0,
        );
        $opening->id = $DB->insert_record(self::TABLE, $opening);
        self::reset_cache();
        return $opening;
    }

    /**
     * Heure de fermeture automatique pour une durée choisie (0 = manuelle).
     *
     * @throws \moodle_exception « fin du créneau » sans créneau en cours
     */
    public static function close_time(int $courseid, string $scope, int $scopeid, int $duration, int $now): int {
        if ($duration === self::MANUAL) {
            return 0;
        }
        if ($duration === self::UNTIL_SLOT_END) {
            $end = self::slot_end($courseid, $scope, $scopeid, $now);
            if ($end === null) {
                throw new \moodle_exception('supervised_noslot', 'local_classhours');
            }
            return $end;
        }
        if ($duration < 0) {
            throw new \coding_exception('durée invalide : ' . $duration);
        }
        return $now + $duration;
    }

    /**
     * Fin du créneau des Heures de cours en cours pour la cible, ou null.
     * Pour tout le cours : un créneau commun au cours, sinon celui de
     * n'importe quel groupe.
     */
    public static function slot_end(int $courseid, string $scope, int $scopeid, int $now): ?int {
        $sched = schedule::for_course($courseid);
        if ($scope === self::SCOPE_GROUP) {
            $interval = $sched->interval_at(array($scopeid), $now);
        } else if ($scope === self::SCOPE_USER) {
            $interval = $sched->interval_at($sched->user_groupids($scopeid), $now);
        } else {
            $interval = $sched->interval_at(array(), $now) ?? $sched->interval_at(null, $now);
        }
        return $interval !== null ? (int)$interval[1] : null;
    }

    /**
     * Ferme une ouverture, ou toutes celles de l'activité, puis demande le
     * ramassage du travail en cours (tâche ad hoc : remettre le devoir d'un
     * élève demande des droits que l'enseignant n'a pas).
     *
     * @param int      $courseid
     * @param int      $cmid
     * @param int|null $openingid null : toutes les ouvertures de l'activité
     * @param int      $teacherid
     * @param bool     $collect   ramasser le travail en cours (non quand la
     *                            condition est retirée : l'activité redevient libre)
     * @return int nombre d'ouvertures fermées
     */
    public static function close(int $courseid, int $cmid, ?int $openingid, int $teacherid, bool $collect = true): int {
        global $DB;
        $now = schedule::now();
        $conditions = array('courseid' => $courseid, 'cmid' => $cmid, 'timeclosed' => 0);
        if ($openingid !== null) {
            $conditions['id'] = $openingid;
        }
        $closed = 0;
        foreach ($DB->get_records(self::TABLE, $conditions, 'id', 'id, closeat') as $row) {
            // Une ouverture déjà expirée garde son heure de fin prévue.
            $closeat = (int)$row->closeat;
            $end = ($closeat > 0 && $closeat <= $now) ? $closeat : $now;
            $DB->update_record(self::TABLE, (object)array('id' => $row->id, 'timeclosed' => $end,
                'closedby' => $teacherid));
            $closed++;
        }
        self::reset_cache();
        if ($closed && $collect) {
            self::queue_collection($cmid, $now);
        }
        return $closed;
    }

    /**
     * « Tout fermer » : toutes les ouvertures du cours.
     *
     * @return int nombre d'activités fermées
     */
    public static function close_course(int $courseid, int $teacherid): int {
        global $DB;
        $cmids = $DB->get_fieldset_select(self::TABLE, 'DISTINCT cmid', 'courseid = :courseid AND timeclosed = 0',
            array('courseid' => $courseid));
        $count = 0;
        foreach ($cmids as $cmid) {
            if (self::close($courseid, (int)$cmid, null, $teacherid) > 0) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Marque fermées les ouvertures dont l'heure de fin est passée (tâche
     * close_supervised).
     *
     * @return int[] cmid => heure de fermeture, des activités concernées
     */
    public static function close_expired(int $now): array {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE, 'timeclosed = 0 AND closeat > 0 AND closeat <= :now',
            array('now' => $now), 'closeat, id', 'id, cmid, closeat');
        $cmids = array();
        foreach ($rows as $row) {
            $DB->update_record(self::TABLE, (object)array('id' => $row->id, 'timeclosed' => (int)$row->closeat,
                'closedby' => 0));
            $cmids[(int)$row->cmid] = max($cmids[(int)$row->cmid] ?? 0, (int)$row->closeat);
        }
        self::reset_cache();
        return $cmids;
    }

    /** Demande le ramassage du travail en cours d'une activité qui vient de fermer. */
    public static function queue_collection(int $cmid, int $closedat): void {
        $task = new task\collect_supervised();
        $task->set_custom_data((object)array('cmid' => $cmid, 'closedat' => $closedat));
        \core\task\manager::queue_adhoc_task($task);
    }

    // -------------------------------------------------------------------------
    //  Affichage
    // -------------------------------------------------------------------------

    /**
     * Durées proposées à l'ouverture.
     *
     * @param bool $withslot proposer « fin du créneau » (un créneau est en cours)
     * @return string[] durée => libellé
     */
    public static function duration_options(bool $withslot): array {
        $options = array();
        foreach (self::DURATIONS as $duration) {
            if ($duration === self::MANUAL) {
                $options[$duration] = get_string('supervised_duration_manual', 'local_classhours');
            } else if ($duration === self::UNTIL_SLOT_END) {
                if ($withslot) {
                    $options[$duration] = get_string('supervised_duration_slot', 'local_classhours');
                }
            } else {
                $options[$duration] = format_time($duration);
            }
        }
        return $options;
    }

    /** Heure de fermeture prévue : l'heure seule si c'est aujourd'hui, sinon date et heure. */
    public static function format_end(int $t): string {
        $today = userdate(schedule::now(), '%Y%m%d') === userdate($t, '%Y%m%d');
        return userdate($t, get_string($today ? 'strftimetime' : 'strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Pour qui l'ouverture vaut : « tout le cours », le nom du groupe ou de l'élève.
     *
     * @param \stdClass $opening
     * @param string[]  $groupnames groupid => nom
     * @param string[]  $usernames  userid => nom complet
     */
    public static function scope_label(\stdClass $opening, array $groupnames, array $usernames): string {
        switch ($opening->scope) {
            case self::SCOPE_GROUP:
                return $groupnames[(int)$opening->scopeid] ?? get_string('unknowngroup', 'local_classhours');
            case self::SCOPE_USER:
                return $usernames[(int)$opening->scopeid] ?? ('#' . (int)$opening->scopeid);
        }
        return get_string('allcourse', 'local_classhours');
    }
}
