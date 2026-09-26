<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Emploi du temps d'un cours et calcul des créneaux ouverts.
 *
 * Toutes les heures sont celles du FUSEAU DU SERVEUR (l'heure de
 * l'établissement), jamais celui du profil de l'élève : un cours de 8h est à
 * 8h pour tout le monde. Les calculs passent par DateTimeImmutable, ce qui
 * gère les changements d'heure.
 *
 * Un élève a accès pendant l'union de :
 *   - les occurrences des créneaux hebdomadaires de ses groupes (et de « tout
 *     le cours »), sauf pendant les périodes fermées de ses groupes ;
 *   - les ouvertures exceptionnelles de ses groupes (elles l'emportent sur
 *     une période fermée : rattrapage pendant les vacances).
 * Les intervalles qui se chevauchent ou se touchent sont fusionnés : deux
 * heures consécutives (8h-10h puis 10h-12h) forment un seul créneau, et un
 * test commencé à 9h n'est pas coupé à 10h.
 *
 * Les méthodes de calcul (intervals, interval_at, next_opening, merge,
 * subtract) n'utilisent ni la base ni Moodle : elles reçoivent des groupes et
 * des instants, et se testent seules.
 */
class schedule {

    /** Jours examinés autour d'un instant pour reconstituer un créneau fusionné. */
    const LOOKAROUND_DAYS = 8;

    /** Horizon de recherche du prochain créneau. */
    const NEXT_OPENING_DAYS = 60;

    /** Tolérance par défaut après la fin d'un créneau (minutes). */
    const DEFAULT_GRACE_MINUTES = 5;

    /** @var schedule[] cache de requête, par cours */
    private static $cache = array();

    /** @var int */
    private $courseid;

    /** @var \stdClass[] créneaux hebdomadaires (weekday, starttime, endtime, groupid) */
    private $slots;

    /** @var \stdClass[] périodes (type open|closed, timestart, timeend, groupid) */
    private $periods;

    /** @var \DateTimeZone */
    private $tz;

    /** @var array mémo des recherches d'intervalle de la requête */
    private $memo = array();

    /**
     * @param int                $courseid
     * @param \stdClass[]        $slots
     * @param \stdClass[]        $periods
     * @param \DateTimeZone|null $tz fuseau des créneaux (défaut : celui du serveur)
     */
    public function __construct(int $courseid, array $slots, array $periods, ?\DateTimeZone $tz = null) {
        $this->courseid = $courseid;
        $this->slots    = array_values($slots);
        $this->periods  = array_values($periods);
        $this->tz       = $tz ?? \core_date::get_server_timezone_object();
    }

    /**
     * Emploi du temps d'un cours, chargé une fois par requête : la condition
     * d'accès est évaluée pour chaque activité de la page du cours.
     */
    public static function for_course(int $courseid): self {
        global $DB;
        if (!isset(self::$cache[$courseid])) {
            self::$cache[$courseid] = new self($courseid,
                $DB->get_records('local_classhours_slot', array('courseid' => $courseid),
                    'weekday, starttime, endtime, id'),
                $DB->get_records('local_classhours_period', array('courseid' => $courseid),
                    'timestart, timeend, id'));
        }
        return self::$cache[$courseid];
    }

    /**
     * Oublie l'emploi du temps en cache (après une modification).
     *
     * @param int|null $courseid null = tous les cours
     */
    public static function reset_cache(?int $courseid = null): void {
        if ($courseid === null) {
            self::$cache = array();
        } else {
            unset(self::$cache[$courseid]);
        }
    }

    /** Heure courante (horloge Moodle, simulable dans les tests). */
    public static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }

    /**
     * Tolérance après la fin d'un créneau, en secondes : elle laisse passer
     * l'envoi automatique d'un test et le dernier enregistrement d'un devoir.
     */
    public static function grace(): int {
        $minutes = get_config('local_classhours', 'graceminutes');
        if ($minutes === false || $minutes === '') {
            $minutes = self::DEFAULT_GRACE_MINUTES;
        }
        return max(0, (int)$minutes) * MINSECS;
    }

    public function get_courseid(): int {
        return $this->courseid;
    }

    public function get_timezone(): \DateTimeZone {
        return $this->tz;
    }

    /** @return \stdClass[] créneaux hebdomadaires, triés par jour puis heure */
    public function get_slots(): array {
        return $this->slots;
    }

    /**
     * @param string $type open|closed
     * @return \stdClass[] périodes de ce type, triées par début
     */
    public function get_periods(string $type): array {
        return array_values(array_filter($this->periods, function($p) use ($type) {
            return $p->type === $type;
        }));
    }

    /**
     * Le cours a-t-il au moins un créneau : hebdomadaire, ou ouverture
     * exceptionnelle pas encore terminée ? (sans créneau, l'option EFE n'a
     * aucun effet)
     */
    public function has_slots(?int $now = null): bool {
        if (!empty($this->slots)) {
            return true;
        }
        $now = $now ?? self::now();
        foreach ($this->periods as $p) {
            if ($p->type === 'open' && (int)$p->timeend > $now) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    //  Élève
    // -------------------------------------------------------------------------

    /**
     * Groupes de l'utilisateur dans le cours (tous groupements confondus).
     *
     * @return int[]
     */
    public function user_groupids(int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/grouplib.php');
        $groups = groups_get_user_groups($this->courseid, $userid);
        return array_map('intval', array_values($groups[0] ?? array()));
    }

    /** L'utilisateur est-il dans un créneau (tolérance comprise) ? */
    public function is_open_for_user(int $userid, ?int $t = null): bool {
        return $this->interval_at($this->user_groupids($userid), $t ?? self::now(), self::grace()) !== null;
    }

    /**
     * Créneau (fusionné) de l'utilisateur qui contient $t.
     *
     * @return int[]|null [début, fin]
     */
    public function interval_for_user(int $userid, int $t, int $grace = 0): ?array {
        return $this->interval_at($this->user_groupids($userid), $t, $grace);
    }

    /** Début du prochain créneau de l'utilisateur après $t (null : aucun dans les 60 jours). */
    public function next_opening_for_user(int $userid, int $t): ?int {
        return $this->next_opening($this->user_groupids($userid), $t);
    }

    // -------------------------------------------------------------------------
    //  Calcul (pur)
    // -------------------------------------------------------------------------

    /**
     * Créneaux ouverts qui recouvrent [$from, $to[, fusionnés et triés.
     *
     * @param int[]|null $groupids groupes de l'élève ; null = tous les groupes
     *                             (vue d'ensemble de l'enseignant)
     * @param int        $from
     * @param int        $to
     * @return int[][] liste de [début, fin[
     */
    public function intervals(?array $groupids, int $from, int $to): array {
        if ($to <= $from) {
            return array();
        }

        // Occurrences des créneaux hebdomadaires, jour par jour (la veille
        // comprise, par prudence), à l'heure murale du fuseau du serveur.
        $weekly = array();
        foreach ($this->slots as $slot) {
            if ($this->applies($slot, $groupids)) {
                $weekly[(int)$slot->weekday][] = $slot;
            }
        }
        $raw = array();
        if ($weekly) {
            $day  = (new \DateTimeImmutable('@' . $from))->setTimezone($this->tz)->setTime(0, 0)->modify('-1 day');
            $last = (new \DateTimeImmutable('@' . $to))->setTimezone($this->tz)->setTime(0, 0);
            while ($day <= $last) {
                foreach ($weekly[(int)$day->format('N')] ?? array() as $slot) {
                    $start = $day->setTime(intdiv((int)$slot->starttime, 60), (int)$slot->starttime % 60)->getTimestamp();
                    $end   = $day->setTime(intdiv((int)$slot->endtime, 60), (int)$slot->endtime % 60)->getTimestamp();
                    if ($end > $start) {
                        $raw[] = array($start, $end);
                    }
                }
                $day = $day->modify('+1 day');
            }
        }

        // Périodes fermées (retirées des créneaux hebdomadaires seulement),
        // puis ouvertures exceptionnelles.
        $closed = array();
        $open = array();
        foreach ($this->periods as $p) {
            if (!$this->applies($p, $groupids) || (int)$p->timeend <= (int)$p->timestart) {
                continue;
            }
            if ($p->type === 'closed') {
                $closed[] = array((int)$p->timestart, (int)$p->timeend);
            } else if ($p->type === 'open') {
                $open[] = array((int)$p->timestart, (int)$p->timeend);
            }
        }
        $all = self::merge(array_merge(self::subtract($raw, $closed), $open));

        return array_values(array_filter($all, function($i) use ($from, $to) {
            return $i[1] > $from && $i[0] < $to;
        }));
    }

    /**
     * Créneau qui contient $t : début ≤ $t < fin + tolérance.
     *
     * @param int[]|null $groupids
     * @param int        $t
     * @param int        $grace tolérance après la fin, en secondes
     * @return int[]|null [début, fin]
     */
    public function interval_at(?array $groupids, int $t, int $grace = 0): ?array {
        $key = ($groupids === null ? '*' : implode(',', $groupids)) . '|' . $t . '|' . $grace;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $found = null;
        $around = self::LOOKAROUND_DAYS * DAYSECS;
        foreach ($this->intervals($groupids, $t - $around, $t + $around) as $i) {
            if ($i[0] <= $t && $t < $i[1] + $grace) {
                $found = $i;
                break;
            }
        }
        return $this->memo[$key] = $found;
    }

    /**
     * Début du prochain créneau strictement après $t.
     *
     * @param int[]|null $groupids
     * @param int        $t
     * @return int|null null : aucun créneau dans les 60 jours
     */
    public function next_opening(?array $groupids, int $t): ?int {
        foreach ($this->intervals($groupids, $t, $t + self::NEXT_OPENING_DAYS * DAYSECS) as $i) {
            if ($i[0] > $t) {
                return $i[0];
            }
        }
        return null;
    }

    /**
     * Fusionne les intervalles qui se chevauchent ou se touchent.
     *
     * @param int[][] $intervals
     * @return int[][] triés par début
     */
    public static function merge(array $intervals): array {
        usort($intervals, function($a, $b) {
            return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]);
        });
        $out = array();
        foreach ($intervals as $i) {
            $n = count($out);
            if ($n && $i[0] <= $out[$n - 1][1]) {
                $out[$n - 1][1] = max($out[$n - 1][1], $i[1]);
            } else {
                $out[] = array($i[0], $i[1]);
            }
        }
        return $out;
    }

    /**
     * Retire des intervalles les « trous » (périodes fermées).
     *
     * @param int[][] $intervals
     * @param int[][] $holes
     * @return int[][]
     */
    public static function subtract(array $intervals, array $holes): array {
        if (!$holes) {
            return $intervals;
        }
        $out = array();
        foreach ($intervals as $i) {
            $pieces = array($i);
            foreach ($holes as $h) {
                $next = array();
                foreach ($pieces as $p) {
                    if ($h[1] <= $p[0] || $h[0] >= $p[1]) {
                        $next[] = $p;
                        continue;
                    }
                    if ($h[0] > $p[0]) {
                        $next[] = array($p[0], $h[0]);
                    }
                    if ($h[1] < $p[1]) {
                        $next[] = array($h[1], $p[1]);
                    }
                }
                $pieces = $next;
            }
            foreach ($pieces as $p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Un créneau / une période concerne-t-il ces groupes ? (0 = tout le cours) */
    private function applies(\stdClass $row, ?array $groupids): bool {
        return (int)$row->groupid === 0 || $groupids === null || in_array((int)$row->groupid, $groupids, true);
    }

    // -------------------------------------------------------------------------
    //  Affichage
    // -------------------------------------------------------------------------

    /** « 08:00 » à partir de minutes depuis minuit. */
    public static function format_minutes(int $minutes): string {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Nom court du jour ISO (1 = lundi). */
    public static function weekday_short(int $weekday): string {
        $keys = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
        return get_string($keys[$weekday] ?? 'mon', 'calendar');
    }

    /** Nom complet du jour ISO (1 = lundi). */
    public static function weekday_name(int $weekday): string {
        $keys = array(1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
            5 => 'friday', 6 => 'saturday', 7 => 'sunday');
        return get_string($keys[$weekday] ?? 'monday', 'calendar');
    }

    /**
     * Noms des groupes du cours (déjà passés par format_string).
     *
     * @return string[] groupid => nom
     */
    public function group_names(): array {
        global $CFG;
        require_once($CFG->libdir . '/grouplib.php');
        $names = array();
        foreach (groups_get_all_groups($this->courseid) as $group) {
            $names[(int)$group->id] = format_string($group->name, true,
                array('context' => \context_course::instance($this->courseid)));
        }
        return $names;
    }

    /**
     * Résumé des créneaux hebdomadaires : « lun. 08:00–10:00, jeu. 14:00–16:00 ».
     * Texte sûr pour le HTML (noms de groupes passés par format_string).
     *
     * @param int[]|null $groupids créneaux de ces groupes seulement ; null = tous
     * @param bool       $withgroups ajouter le groupe de chaque créneau
     * @return string '' si aucun créneau
     */
    public function describe_weekly(?array $groupids, bool $withgroups): string {
        $names = $withgroups ? $this->group_names() : array();
        $parts = array();
        foreach ($this->slots as $slot) {
            if (!$this->applies($slot, $groupids)) {
                continue;
            }
            $text = self::weekday_short((int)$slot->weekday) . ' '
                . self::format_minutes((int)$slot->starttime) . '–' . self::format_minutes((int)$slot->endtime);
            if ($withgroups && (int)$slot->groupid > 0) {
                $text .= ' (' . ($names[(int)$slot->groupid] ?? get_string('unknowngroup', 'local_classhours')) . ')';
            }
            $parts[] = $text;
        }
        return implode(', ', $parts);
    }

    /** Date et heure dans le fuseau des créneaux : « jeudi 26 septembre à 14:00 ». */
    public function format_time(int $t): string {
        return userdate($t, get_string('strftimeslot', 'local_classhours'), $this->tz->getName());
    }

    /** Date seule dans le fuseau des créneaux. */
    public function format_date(int $t): string {
        return userdate($t, get_string('strftimedate', 'langconfig'), $this->tz->getName());
    }
}
