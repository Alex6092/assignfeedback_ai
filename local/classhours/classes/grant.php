<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Accès ponctuel d'un élève à une activité fermée : demandé par l'élève et
 * accordé (ou refusé) par un enseignant, ou accordé directement par
 * l'enseignant.
 *
 * Pendant la fenêtre accordée, l'activité est disponible pour cet élève comme
 * pendant un créneau : la condition d'accès, le verrou de remise, le Tuteur IA
 * et la règle d'accès des tests en tiennent compte. La fenêtre se referme
 * d'elle-même, puisque la disponibilité est évaluée à chaque requête.
 */
class grant {

    /** Table des demandes et des accès. */
    const TABLE = 'local_classhours_grant';

    const PENDING   = 'pending';
    const ACCEPTED  = 'accepted';
    const REFUSED   = 'refused';
    const CANCELLED = 'cancelled';

    /** « Jusqu'à ce soir » dans la liste des durées. */
    const UNTIL_TONIGHT = 0;

    /** Durées proposées à l'enseignant (secondes). */
    const DURATIONS = array(300, 900, 1800, 3600, 7200, self::UNTIL_TONIGHT);

    /** Durée proposée par défaut : juste le temps de remettre un devoir. */
    const DEFAULT_DURATION = 300;

    /** Longueur maximale du message de l'élève et du commentaire de l'enseignant. */
    const MAXTEXT = 1000;

    /** @var array "courseid|userid" => ['active' => cmid => fin, 'pending' => cmid => id] */
    private static $cache = array();

    /** Oublie l'état mémorisé pour la requête (après une décision, dans les tests). */
    public static function reset_cache(): void {
        self::$cache = array();
    }

    // -------------------------------------------------------------------------
    //  Lecture
    // -------------------------------------------------------------------------

    /**
     * Accès en cours et demandes en attente d'un élève dans un cours, lus en
     * une requête et mémorisés : la page du cours évalue une condition par
     * activité restreinte.
     *
     * @return array ['active' => [cmid => fin de fenêtre], 'pending' => [cmid => id]]
     */
    public static function state_for(int $courseid, int $userid): array {
        global $DB;
        $key = $courseid . '|' . $userid;
        if (!isset(self::$cache[$key])) {
            $now = schedule::now();
            $rows = $DB->get_records_select(self::TABLE,
                'courseid = :courseid AND userid = :userid AND (status = :pending'
                    . ' OR (status = :accepted AND timestart <= :now1 AND timeend > :now2))',
                array('courseid' => $courseid, 'userid' => $userid, 'pending' => self::PENDING,
                    'accepted' => self::ACCEPTED, 'now1' => $now, 'now2' => $now),
                'id', 'id, cmid, status, timeend');
            $state = array('active' => array(), 'pending' => array());
            foreach ($rows as $row) {
                $cmid = (int)$row->cmid;
                if ($row->status === self::PENDING) {
                    $state['pending'][$cmid] = (int)$row->id;
                } else {
                    $state['active'][$cmid] = max($state['active'][$cmid] ?? 0, (int)$row->timeend);
                }
            }
            self::$cache[$key] = $state;
        }
        return self::$cache[$key];
    }

    /** L'élève a-t-il un accès en cours sur cette activité ? */
    public static function is_active(int $courseid, int $cmid, int $userid): bool {
        return isset(self::state_for($courseid, $userid)['active'][$cmid]);
    }

    /** Identifiant de la demande en attente de l'élève sur cette activité, ou null. */
    public static function pending_id(int $courseid, int $cmid, int $userid): ?int {
        return self::state_for($courseid, $userid)['pending'][$cmid] ?? null;
    }

    /**
     * Fenêtre accordée qui contient $t (fin d'une tentative de test commencée
     * pendant un accès ponctuel).
     *
     * @return int[]|null [début, fin]
     */
    public static function window_at(int $cmid, int $userid, int $t): ?array {
        global $DB;
        $rows = $DB->get_records_select(self::TABLE,
            'cmid = :cmid AND userid = :userid AND status = :accepted AND timestart <= :t1 AND timeend > :t2',
            array('cmid' => $cmid, 'userid' => $userid, 'accepted' => self::ACCEPTED, 't1' => $t, 't2' => $t),
            'timeend DESC', 'id, timestart, timeend', 0, 1);
        $row = reset($rows);
        return $row ? array((int)$row->timestart, (int)$row->timeend) : null;
    }

    // -------------------------------------------------------------------------
    //  Élève
    // -------------------------------------------------------------------------

    /**
     * L'élève demande un accès. Une demande déjà en attente est renvoyée telle
     * quelle : pas de doublon, pas de nouvelle notification.
     *
     * @param \cm_info $cm
     * @param int      $userid
     * @param string   $reason message facultatif de l'élève
     * @return \stdClass la demande
     */
    public static function request(\cm_info $cm, int $userid, string $reason = ''): \stdClass {
        global $DB;
        $existing = $DB->get_record(self::TABLE,
            array('cmid' => (int)$cm->id, 'userid' => $userid, 'status' => self::PENDING));
        if ($existing) {
            return $existing;
        }
        $now = schedule::now();
        $row = (object)array(
            'courseid'        => (int)$cm->course,
            'cmid'            => (int)$cm->id,
            'userid'          => $userid,
            'status'          => self::PENDING,
            'reason'          => \core_text::substr(trim($reason), 0, self::MAXTEXT),
            'requestedat'     => $now,
            'decidedby'       => 0,
            'decidedat'       => 0,
            'decisioncomment' => '',
            'timestart'       => 0,
            'timeend'         => 0,
        );
        $row->id = $DB->insert_record(self::TABLE, $row);
        self::reset_cache();
        notifier::request_sent($cm, $row);
        return $row;
    }

    // -------------------------------------------------------------------------
    //  Enseignant
    // -------------------------------------------------------------------------

    /**
     * Accepte une demande. La première décision l'emporte : la mise à jour ne
     * porte que sur une demande encore en attente, donc deux enseignants qui
     * répondent en même temps ne se contredisent pas.
     *
     * @param int    $id
     * @param int    $duration secondes, ou UNTIL_TONIGHT
     * @param int    $teacherid
     * @param string $comment
     * @return bool vrai si c'est cette décision qui a été retenue
     */
    public static function accept(int $id, int $duration, int $teacherid, string $comment = ''): bool {
        $now = schedule::now();
        return self::decide($id, self::ACCEPTED, $teacherid, $comment, $now, self::end_of($duration, $now));
    }

    /**
     * Refuse une demande (même règle : la première décision l'emporte).
     *
     * @return bool vrai si c'est cette décision qui a été retenue
     */
    public static function refuse(int $id, int $teacherid, string $comment = ''): bool {
        return self::decide($id, self::REFUSED, $teacherid, $comment, 0, 0);
    }

    /**
     * L'enseignant accorde un accès sans demande. S'il y en a une en attente
     * pour cette activité, c'est elle qui est acceptée.
     *
     * @return \stdClass l'accès accordé
     */
    public static function grant_direct(\cm_info $cm, int $userid, int $duration, int $teacherid): \stdClass {
        global $DB;
        $pending = $DB->get_record(self::TABLE,
            array('cmid' => (int)$cm->id, 'userid' => $userid, 'status' => self::PENDING));
        if ($pending && self::accept((int)$pending->id, $duration, $teacherid)) {
            return $DB->get_record(self::TABLE, array('id' => $pending->id));
        }
        $now = schedule::now();
        $row = (object)array(
            'courseid'        => (int)$cm->course,
            'cmid'            => (int)$cm->id,
            'userid'          => $userid,
            'status'          => self::ACCEPTED,
            'reason'          => '',
            'requestedat'     => $now,
            'decidedby'       => $teacherid,
            'decidedat'       => $now,
            'decisioncomment' => '',
            'timestart'       => $now,
            'timeend'         => self::end_of($duration, $now),
        );
        $row->id = $DB->insert_record(self::TABLE, $row);
        self::reset_cache();
        notifier::decision_sent($row);
        return $row;
    }

    /**
     * Retire un accès en cours : la fenêtre se ferme maintenant, la ligne reste
     * dans l'historique.
     *
     * @return bool vrai si un accès en cours a bien été retiré
     */
    public static function revoke(int $id, int $teacherid): bool {
        global $DB;
        $now = schedule::now();
        $DB->execute('UPDATE {' . self::TABLE . '}
                         SET status = :cancelled, timeend = :now, decidedby = :teacherid, decidedat = :now2
                       WHERE id = :id AND status = :accepted AND timeend > :now3',
            array('cancelled' => self::CANCELLED, 'now' => $now, 'teacherid' => $teacherid, 'now2' => $now,
                'id' => $id, 'accepted' => self::ACCEPTED, 'now3' => $now));
        self::reset_cache();
        return $DB->get_field(self::TABLE, 'status', array('id' => $id)) === self::CANCELLED;
    }

    /**
     * Fin de fenêtre pour une durée choisie : maintenant + durée, ou 23:59:59
     * le jour même (heure du serveur, comme les créneaux).
     */
    public static function end_of(int $duration, int $now): int {
        if ($duration === self::UNTIL_TONIGHT || $duration <= 0) {
            $tz = \core_date::get_server_timezone_object();
            return (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(23, 59, 59)->getTimestamp() + 1;
        }
        return $now + $duration;
    }

    /**
     * Libellés des durées pour la liste de l'enseignant.
     *
     * @return string[] secondes => libellé
     */
    public static function duration_options(): array {
        $options = array();
        foreach (self::DURATIONS as $seconds) {
            $options[$seconds] = ($seconds === self::UNTIL_TONIGHT)
                ? get_string('duration_tonight', 'local_classhours')
                : format_time($seconds);
        }
        return $options;
    }

    // -------------------------------------------------------------------------
    //  Interne
    // -------------------------------------------------------------------------

    private static function decide(int $id, string $status, int $teacherid, string $comment,
            int $timestart, int $timeend): bool {
        global $DB;
        $now = schedule::now();
        $DB->execute('UPDATE {' . self::TABLE . '}
                         SET status = :status, decidedby = :teacherid, decidedat = :now,
                             decisioncomment = :comment, timestart = :timestart, timeend = :timeend
                       WHERE id = :id AND status = :pending',
            array('status' => $status, 'teacherid' => $teacherid, 'now' => $now,
                'comment' => \core_text::substr(trim($comment), 0, self::MAXTEXT),
                'timestart' => $timestart, 'timeend' => $timeend, 'id' => $id, 'pending' => self::PENDING));
        self::reset_cache();

        // Moodle ne renvoie pas le nombre de lignes modifiées : on relit. La
        // décision est la nôtre si la ligne porte maintenant notre statut et
        // notre signature.
        $row = $DB->get_record(self::TABLE, array('id' => $id));
        if (!$row || $row->status !== $status || (int)$row->decidedby !== $teacherid || (int)$row->decidedat !== $now) {
            return false;
        }
        notifier::decision_sent($row);
        return true;
    }
}
