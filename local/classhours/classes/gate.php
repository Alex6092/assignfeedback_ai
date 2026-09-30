<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Activités surveillées : l'enseignant les ouvre et les ferme lui-même, en
 * classe (condition d'accès availability_supervised).
 *
 * Une ligne de local_classhours_gate par ouverture, pour tout le cours, un
 * groupe ou un élève, avec une fin facultative. Les lignes fermées restent
 * comme historique.
 *
 * Fin d'une ouverture, élève par élève :
 *   - closeat est la FIN DE BASE (0 : jusqu'à ce que l'enseignant ferme ;
 *     une fermeture à la main la pose à « maintenant ») ;
 *   - un élève au tiers-temps (voir extratime) a une fin propre, prolongée
 *     d'un pourcentage du temps écoulé depuis l'ouverture ;
 *   - timeclosed est la fermeture DÉFINITIVE, une fois le dernier tiers-temps
 *     écoulé (ou quand l'enseignant ferme aussi pour le tiers-temps).
 * L'activité se referme donc pour chacun à son heure, même avant le passage
 * de la tâche close_supervised, qui ne fait que ramasser le travail en cours.
 *
 * Code de séance (option de l'activité, voir plan) : chaque ouverture a son
 * code ; l'élève est « dans la fenêtre » dès l'ouverture, mais ne peut
 * travailler qu'après avoir saisi le code (local_classhours_present).
 *
 * La même condition sert aussi à fermer des activités LIÉES pendant la
 * séance : {"type":"supervised","lock":<cmid>} (voir is_supervised_json()).
 */
class gate {

    /** Table des ouvertures. */
    const TABLE = 'local_classhours_gate';

    /** Table des codes de séance saisis (présence). */
    const PRESENT = 'local_classhours_present';

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

    /** Code de séance : caractères sans ambiguïté à l'écran (ni 0/O, ni 1/I/L, ni 5/S, ni 2/Z, ni 8/B). */
    const CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';
    const CODE_LENGTH = 4;

    /** @var array courseid => ouvertures non définitivement fermées du cours */
    private static $cache = array();

    /** @var array "courseid|userid" => [gateid => true] codes saisis par l'élève */
    private static $present = array();

    /** Oublie l'état mémorisé pour la requête (après un changement, dans les tests). */
    public static function reset_cache(): void {
        self::$cache = array();
        self::$present = array();
        extratime::reset_cache();
        plan::reset_cache();
        catchup::reset_cache();
    }

    // -------------------------------------------------------------------------
    //  Lecture
    // -------------------------------------------------------------------------

    /**
     * Ouvertures non définitivement fermées d'un cours. Une requête par cours,
     * mémorisée : la page du cours évalue la condition pour chaque activité.
     *
     * @return \stdClass[] id => ouverture
     */
    private static function pending_for_course(int $courseid): array {
        global $DB;
        if (!isset(self::$cache[$courseid])) {
            self::$cache[$courseid] = $DB->get_records(self::TABLE,
                array('courseid' => $courseid, 'timeclosed' => 0), 'timeopened, id',
                'id, courseid, cmid, scope, scopeid, openedby, timeopened, closeat, code, collected');
        }
        return self::$cache[$courseid];
    }

    /**
     * Ouvertures en cours d'un cours : pour quelqu'un au moins (le tiers-temps
     * peut prolonger une ouverture dont la fin de base est passée).
     *
     * @return \stdClass[] id => ouverture
     */
    public static function active_for_course(int $courseid, ?int $now = null): array {
        $now = $now ?? schedule::now();
        return array_filter(self::pending_for_course($courseid), function($o) use ($now) {
            return self::is_active($o, $now);
        });
    }

    /**
     * Ouvertures en cours d'une activité.
     *
     * @return \stdClass[] id => ouverture
     */
    public static function active_for_cm(int $courseid, int $cmid, ?int $now = null): array {
        return array_filter(self::active_for_course($courseid, $now), function($o) use ($cmid) {
            return (int)$o->cmid === $cmid;
        });
    }

    /** Une ouverture est-elle en cours à $now, pour quelqu'un au moins ? */
    public static function is_active(\stdClass $opening, int $now): bool {
        if (!empty($opening->timeclosed)) {
            return false;
        }
        return (int)$opening->closeat === 0 || $now < self::max_end($opening);
    }

    /**
     * La fin de base est-elle passée (ouverture prolongée pour le tiers-temps
     * seulement) ?
     */
    public static function in_extratime(\stdClass $opening, int $now): bool {
        return (int)$opening->closeat > 0 && (int)$opening->closeat <= $now && self::is_active($opening, $now);
    }

    /**
     * Fin d'une ouverture pour un élève dont le temps est majoré de $percent :
     * la durée écoulée entre l'ouverture et la fin de base est prolongée
     * d'autant. 0 si l'ouverture n'a pas de fin.
     */
    public static function user_end(\stdClass $opening, int $percent): int {
        $closeat = (int)$opening->closeat;
        if ($closeat === 0) {
            return 0;
        }
        if ($percent <= 0) {
            return $closeat;
        }
        $length = max(0, $closeat - (int)$opening->timeopened);
        return $closeat + (int)ceil($length * $percent / 100);
    }

    /** Fin la plus lointaine d'une ouverture, tiers-temps des élèves concernés compris. */
    public static function max_end(\stdClass $opening): int {
        $max = 0;
        foreach (extratime::for_course((int)$opening->courseid) as $userid => $percent) {
            if ($percent <= $max) {
                continue;
            }
            $groupids = $opening->scope === self::SCOPE_GROUP
                ? self::user_groupids((int)$opening->courseid, (int)$userid) : array();
            if (self::applies($opening, (int)$userid, $groupids)) {
                $max = $percent;
            }
        }
        return self::user_end($opening, $max);
    }

    /**
     * Ouvertures dans lesquelles l'élève se trouve en ce moment (fenêtre en
     * cours pour lui, tiers-temps compris).
     *
     * @return \stdClass[]
     */
    public static function windows_for(int $courseid, int $cmid, int $userid, ?int $now = null): array {
        $now = $now ?? schedule::now();
        $percent = extratime::percent($courseid, $userid);
        $groupids = null;
        $out = array();
        foreach (self::pending_for_course($courseid) as $opening) {
            if ((int)$opening->cmid !== $cmid) {
                continue;
            }
            if ($opening->scope === self::SCOPE_GROUP && $groupids === null) {
                $groupids = self::user_groupids($courseid, $userid);
            }
            if (!self::applies($opening, $userid, $groupids ?? array())) {
                continue;
            }
            $end = self::user_end($opening, $percent);
            if ($end === 0 || $now < $end) {
                $out[] = $opening;
            }
        }
        return $out;
    }

    /**
     * Fin de la fenêtre de l'élève : null s'il n'est pas dans une fenêtre, 0
     * si une de ses ouvertures n'a pas de fin, sinon la plus lointaine de ses
     * fins propres (tiers-temps compris).
     */
    public static function end_for(int $courseid, int $cmid, int $userid, ?int $now = null): ?int {
        $windows = self::windows_for($courseid, $cmid, $userid, $now);
        if (!$windows) {
            return null;
        }
        $percent = extratime::percent($courseid, $userid);
        $end = 0;
        foreach ($windows as $opening) {
            $userend = self::user_end($opening, $percent);
            if ($userend === 0) {
                return 0;
            }
            $end = max($end, $userend);
        }
        return $end;
    }

    /**
     * L'élève est-il dans une fenêtre de l'activité ? C'est ce qui ferme les
     * activités liées et déclenche le mode examen, code saisi ou non.
     */
    public static function in_window(int $courseid, int $cmid, int $userid, ?int $now = null): bool {
        return (bool)self::windows_for($courseid, $cmid, $userid, $now);
    }

    /**
     * L'élève peut-il travailler sur l'activité : dans une fenêtre, et code
     * de séance saisi si l'ouverture en demande un ?
     */
    public static function is_open_for(int $courseid, int $cmid, int $userid, ?int $now = null): bool {
        foreach (self::windows_for($courseid, $cmid, $userid, $now) as $opening) {
            if ((string)$opening->code === '' || self::has_entered_code($courseid, (int)$opening->id, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * L'élève est dans une fenêtre mais doit encore saisir le code de séance.
     */
    public static function needs_code(int $courseid, int $cmid, int $userid, ?int $now = null): bool {
        $windows = self::windows_for($courseid, $cmid, $userid, $now);
        return $windows && !self::is_open_for($courseid, $cmid, $userid, $now);
    }

    /** L'élève a-t-il saisi le code de cette ouverture ? */
    public static function has_entered_code(int $courseid, int $gateid, int $userid): bool {
        global $DB;
        $key = $courseid . '|' . $userid;
        if (!isset(self::$present[$key])) {
            $ids = $DB->get_fieldset_sql('SELECT p.gateid
                                            FROM {' . self::PRESENT . '} p
                                            JOIN {' . self::TABLE . '} g ON g.id = p.gateid
                                           WHERE g.courseid = :courseid AND g.timeclosed = 0 AND p.userid = :userid',
                array('courseid' => $courseid, 'userid' => $userid));
            self::$present[$key] = array_fill_keys(array_map('intval', $ids), true);
        }
        return isset(self::$present[$key][$gateid]);
    }

    /**
     * L'élève saisit un code de séance : s'il correspond à l'une de ses
     * fenêtres, sa présence est enregistrée.
     *
     * @return bool code accepté
     */
    public static function submit_code(int $courseid, int $cmid, int $userid, string $code): bool {
        global $DB;
        $code = strtoupper(preg_replace('/\s+/', '', $code));
        if ($code === '') {
            return false;
        }
        foreach (self::windows_for($courseid, $cmid, $userid) as $opening) {
            if ((string)$opening->code !== '' && hash_equals((string)$opening->code, $code)) {
                if (!$DB->record_exists(self::PRESENT, array('gateid' => $opening->id, 'userid' => $userid))) {
                    $DB->insert_record(self::PRESENT, (object)array('gateid' => (int)$opening->id, 'userid' => $userid,
                        'timecreated' => schedule::now()));
                }
                self::$present = array();
                return true;
            }
        }
        return false;
    }

    /** Nombre d'élèves qui ont saisi le code des ouvertures en cours d'une activité. */
    public static function present_count(int $courseid, int $cmid): int {
        global $DB;
        $ids = array_keys(self::active_for_cm($courseid, $cmid));
        if (!$ids) {
            return 0;
        }
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        return (int)$DB->count_records_sql('SELECT COUNT(DISTINCT userid) FROM {' . self::PRESENT . '} WHERE gateid ' . $insql,
            $params);
    }

    /**
     * Mode examen : l'activité surveillée (en mode examen) dans la fenêtre de
     * laquelle se trouve l'élève, ou null.
     */
    public static function exam_cmid(int $courseid, int $userid, ?int $now = null): ?int {
        foreach (plan::options_for_course($courseid) as $cmid => $options) {
            if (!empty($options->exammode) && self::in_window($courseid, (int)$cmid, $userid, $now)) {
                return (int)$cmid;
            }
        }
        return null;
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
    //  JSON de restriction : activité surveillée ou activité liée (verrou)
    // -------------------------------------------------------------------------

    /** Le JSON porte-t-il, à la racine, la condition d'une activité surveillée ? */
    public static function is_supervised_json(?string $json): bool {
        foreach (availability_json::root_children($json) as $child) {
            if (($child->type ?? '') === self::TYPE && empty($child->lock)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Activités surveillées pendant lesquelles cette activité est fermée
     * (conditions {"type":"supervised","lock":cmid} de la racine).
     *
     * @return int[]
     */
    public static function lock_cmids(?string $json): array {
        $out = array();
        foreach (availability_json::root_children($json) as $child) {
            if (($child->type ?? '') === self::TYPE && !empty($child->lock)) {
                $out[] = (int)$child->lock;
            }
        }
        return $out;
    }

    /** Pose la condition d'activité surveillée à la racine. */
    public static function add_supervised(?string $json): string {
        return availability_json::add_child($json, (object)array('type' => self::TYPE));
    }

    /** Retire la condition d'activité surveillée (pas les verrous). */
    public static function remove_supervised(?string $json): ?string {
        return availability_json::remove_where($json, function($child) {
            return ($child->type ?? '') === self::TYPE && empty($child->lock);
        });
    }

    /** Ferme l'activité pendant l'activité surveillée $cmid. */
    public static function add_lock(?string $json, int $cmid): string {
        if (in_array($cmid, self::lock_cmids($json), true)) {
            return (string)$json;
        }
        return availability_json::add_child($json, (object)array('type' => self::TYPE, 'lock' => $cmid));
    }

    /** Retire le verrou lié à l'activité surveillée $cmid. */
    public static function remove_lock(?string $json, int $cmid): ?string {
        return availability_json::remove_where($json, function($child) use ($cmid) {
            return ($child->type ?? '') === self::TYPE && (int)($child->lock ?? 0) === $cmid;
        });
    }

    // -------------------------------------------------------------------------
    //  Ouvrir, fermer
    // -------------------------------------------------------------------------

    /**
     * Ouvre une activité. Une ouverture en cours pour la même cible est
     * prolongée (ou rendue manuelle) au lieu d'être doublée. Si l'activité
     * demande un code de séance, un code est tiré pour l'ouverture.
     *
     * Ouvrir pour un élève marque sa demande de rattrapage comme traitée.
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

        if ($scope === self::SCOPE_USER) {
            catchup::mark_done((int)$cm->id, $scopeid);
        }

        foreach (self::active_for_cm($courseid, (int)$cm->id, $now) as $opening) {
            if ($opening->scope === $scope && (int)$opening->scopeid === $scopeid) {
                $DB->update_record(self::TABLE, (object)array('id' => $opening->id, 'closeat' => $closeat,
                    'collected' => 0));
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
            'code'       => plan::options($courseid, (int)$cm->id)->sessioncode ? self::generate_code() : '',
            'collected'  => 0,
        );
        $opening->id = $DB->insert_record(self::TABLE, $opening);
        self::reset_cache();
        return $opening;
    }

    /** Tire un code de séance. */
    public static function generate_code(): string {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * Heure de fin de base pour une durée choisie (0 = jusqu'à fermeture).
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
     * Ferme une ouverture, ou toutes celles de l'activité.
     *
     * La fin de base passe à maintenant (une fin prévue déjà passée est
     * gardée). Les élèves au tiers-temps concernés gardent leur temps majoré,
     * calculé sur le temps réellement écoulé : l'ouverture ne se ferme
     * définitivement qu'ensuite, sauf si $force (« Fermer aussi pour le
     * tiers-temps »). Le travail en cours des élèves qui perdent l'accès est
     * ramassé (tâche ad hoc : remettre le devoir d'un élève demande des droits
     * que l'enseignant n'a pas).
     *
     * @param int      $courseid
     * @param int      $cmid
     * @param int|null $openingid null : toutes les ouvertures de l'activité
     * @param int      $teacherid
     * @param bool     $collect   ramasser le travail en cours (non quand la
     *                            condition est retirée : l'activité redevient libre)
     * @param bool     $force     fermer aussi pour le tiers-temps
     * @return int nombre d'ouvertures fermées (ou passées en tiers-temps)
     */
    public static function close(int $courseid, int $cmid, ?int $openingid, int $teacherid, bool $collect = true,
            bool $force = false): int {
        global $DB;
        $now = schedule::now();
        $closed = 0;
        foreach (self::pending_for_course($courseid) as $row) {
            if ((int)$row->cmid !== $cmid || ($openingid !== null && (int)$row->id !== $openingid)) {
                continue;
            }
            $closeat = (int)$row->closeat;
            $base = ($closeat > 0 && $closeat <= $now) ? $closeat : $now;
            $update = (object)array('id' => $row->id, 'closeat' => $base, 'closedby' => $teacherid,
                'collected' => $collect ? 1 : (int)$row->collected);
            $row->closeat = $base;
            if ($force || !$collect || self::max_end($row) <= $now) {
                $update->timeclosed = $force ? $now : max($base, min($now, self::max_end($row)));
            }
            $DB->update_record(self::TABLE, $update);
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
    public static function close_course(int $courseid, int $teacherid, bool $force = false): int {
        $cmids = array_unique(array_map(function($o) {
            return (int)$o->cmid;
        }, self::pending_for_course($courseid)));
        $count = 0;
        foreach ($cmids as $cmid) {
            if (self::close($courseid, $cmid, null, $teacherid, true, $force) > 0) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Tâche close_supervised : ouvertures dont la fin de base est passée.
     *   - premier passage : ramassage des élèves sans tiers-temps ;
     *   - fin du dernier tiers-temps : fermeture définitive et dernier ramassage.
     *
     * @return array[] ramassages à faire : [cmid, heure de fermeture]
     */
    public static function process_expired(int $now): array {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE, 'timeclosed = 0 AND closeat > 0 AND closeat <= :now',
            array('now' => $now), 'closeat, id', 'id, courseid, cmid, scope, scopeid, timeopened, closeat, collected');
        $jobs = array();
        foreach ($rows as $row) {
            $update = (object)array('id' => $row->id);
            if (empty($row->collected)) {
                $jobs[] = array((int)$row->cmid, (int)$row->closeat);
                $update->collected = 1;
            }
            $final = self::max_end($row);
            if ($final <= $now) {
                $update->timeclosed = $final;
                if ($final > (int)$row->closeat) {
                    $jobs[] = array((int)$row->cmid, $final);
                }
            }
            if (count((array)$update) > 1) {
                $DB->update_record(self::TABLE, $update);
            }
        }
        self::reset_cache();
        return $jobs;
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
