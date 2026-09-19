<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Budget de la recherche Web : plafond du site, plafond par élève et
 * coupe-circuit du fournisseur.
 *
 * Plafond du site — fenêtre GLISSANTE de 31 jours : jamais plus de N
 * recherches sur 31 jours consécutifs. Comme un cycle de facturation Brave
 * dure au plus 31 jours, aucun cycle ne peut dépasser N, quelle que soit la
 * date à laquelle il commence : il n'y a aucune date de réinitialisation à
 * caler sur le compte Brave. Chaque recherche est inscrite dans un registre
 * sans donnée personnelle ({local_aichat_wsledger}) qu'aucune suppression
 * RGPD ni réinitialisation de cours ne touche : effacer des lignes en cours de
 * fenêtre ferait baisser le compteur et permettrait de dépasser le plafond.
 *
 * Concurrence : la lecture du compteur et l'inscription de la réservation se
 * font sous un verrou Moodle. Deux élèves à 899/900 sont donc sérialisés : le
 * second lit 900 et renonce.
 */
class budget {

    const LEDGER = 'local_aichat_wsledger';

    /** Fenêtre du plafond du site : 31 jours (le plus long cycle mensuel). */
    const WINDOW = 2678400;

    /** Les lignes du registre sont conservées un peu au-delà de la fenêtre. */
    const PURGE_AFTER = 3456000; // 40 jours

    /** Attente maximale du verrou avant de renoncer à la recherche. */
    const LOCK_TIMEOUT = 3;

    /**
     * Nombre de recherches décomptées dans la fenêtre : réservées ou faites.
     * Les remboursées (erreur non facturée) et celles servies par le cache
     * ne comptent pas.
     *
     * @param int|null $now
     * @return int
     */
    public static function used($now = null) {
        global $DB;
        $now = ($now === null) ? time() : (int)$now;
        return (int)$DB->count_records_select(self::LEDGER,
            'timecreated > ? AND status IN (?, ?)', array($now - self::WINDOW, 'reserved', 'done'));
    }

    /**
     * Date à laquelle la plus ancienne recherche décomptée sort de la fenêtre
     * (c'est-à-dire quand une place se libère), ou 0 si la fenêtre est vide.
     *
     * @return int
     */
    public static function next_release() {
        global $DB;
        $oldest = $DB->get_field_sql(
            "SELECT MIN(timecreated) FROM {" . self::LEDGER . "} WHERE timecreated > ? AND status IN (?, ?)",
            array(time() - self::WINDOW, 'reserved', 'done'));
        return $oldest ? (int)$oldest + self::WINDOW : 0;
    }

    /**
     * Recherches décomptées pour une activité sur la fenêtre de 31 jours
     * (plafond propre à l'activité : un TP ne vide pas le budget du site).
     *
     * @param int $cmid
     * @return int
     */
    public static function activity_used($cmid) {
        global $DB;
        return (int)$DB->count_records_select(self::LEDGER,
            'cmid = ? AND timecreated > ? AND status IN (?, ?)',
            array((int)$cmid, time() - self::WINDOW, 'reserved', 'done'));
    }

    /**
     * Activités qui consomment le plus sur la fenêtre (page de diagnostic).
     *
     * @param int $limit
     * @return int[] cmid => nombre de recherches décomptées
     */
    public static function top_activities($limit = 5) {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT cmid, COUNT(1) AS used
               FROM {" . self::LEDGER . "}
              WHERE cmid > 0 AND timecreated > ? AND status IN (?, ?)
           GROUP BY cmid
           ORDER BY COUNT(1) DESC",
            array(time() - self::WINDOW, 'reserved', 'done'), 0, (int)$limit);
        $out = array();
        foreach ($rows as $row) {
            $out[(int)$row->cmid] = (int)$row->used;
        }
        return $out;
    }

    /**
     * Inscrit une recherche servie par le cache (suivi des économies ; hors
     * de tout compteur de budget).
     *
     * @param int $cmid
     */
    public static function record_cached($cmid = 0) {
        global $DB;
        $DB->insert_record(self::LEDGER, (object)array('status' => 'cached', 'cmid' => (int)$cmid,
            'timecreated' => time()));
    }

    /** Recherches servies par le cache sur la fenêtre de 31 jours. */
    public static function cached_count() {
        global $DB;
        return (int)$DB->count_records_select(self::LEDGER,
            'timecreated > ? AND status = ?', array(time() - self::WINDOW, 'cached'));
    }

    /**
     * Réserve une recherche dans le plafond du site, AVANT l'appel au
     * fournisseur.
     *
     * @param int $cap         plafond du site sur la fenêtre (0 = aucune recherche)
     * @param int $cmid        activité (0 = non suivie)
     * @param int $activitycap plafond de l'activité sur la fenêtre (0 = aucun)
     * @return int|string id de la réservation, ou raison du refus
     *                    ('quota_exhausted' | 'activity_quota_exhausted' | 'budget_busy')
     */
    public static function reserve($cap, $cmid = 0, $activitycap = 0) {
        global $DB;
        if ((int)$cap <= 0) {
            return 'quota_exhausted';
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_aichat');
        $lock    = $factory->get_lock('websearch_budget', self::LOCK_TIMEOUT);
        if (!$lock) {
            return 'budget_busy';
        }
        try {
            if (self::used() >= (int)$cap) {
                return 'quota_exhausted';
            }
            if ((int)$cmid > 0 && (int)$activitycap > 0 && self::activity_used($cmid) >= (int)$activitycap) {
                return 'activity_quota_exhausted';
            }
            return (int)$DB->insert_record(self::LEDGER, (object)array(
                'status'      => 'reserved',
                'cmid'        => (int)$cmid,
                'timecreated' => time(),
            ));
        } finally {
            $lock->release();
        }
    }

    /**
     * Solde une réservation : « done » si le fournisseur a pu facturer,
     * « refunded » sinon (erreur HTTP, jamais facturée par Brave).
     *
     * @param int  $id
     * @param bool $billable
     */
    public static function settle($id, $billable) {
        global $DB;
        $DB->set_field(self::LEDGER, 'status', $billable ? 'done' : 'refunded', array('id' => (int)$id));
    }

    /**
     * Recherches déjà faites par un élève sur la fenêtre de son quota (4 h par
     * défaut), toutes activités confondues. Exact : un élève n'a jamais qu'une
     * génération en cours à la fois (conversation::busy_message).
     *
     * @param int $userid
     * @return int
     */
    public static function user_used($userid) {
        return self::user_sum($userid, 'websearches');
    }

    /**
     * Pages lues pour un élève sur la fenêtre de son quota (mode « recherche
     * de matériel ») : gratuites, mais elles occupent le serveur.
     *
     * @param int $userid
     * @return int
     */
    public static function user_reads($userid) {
        return self::user_sum($userid, 'pagereads');
    }

    /** Somme d'un compteur des messages d'un élève sur la fenêtre de son quota. */
    private static function user_sum($userid, $field) {
        global $DB;
        $since = time() - \local_aichat\quota::window_hours() * HOURSECS;
        $sum = $DB->get_field_sql(
            "SELECT COALESCE(SUM($field), 0) FROM {local_aichat_message}
              WHERE userid = ? AND timecreated >= ?",
            array((int)$userid, $since));
        return (int)$sum;
    }

    /**
     * Purge des lignes du registre sorties de la fenêtre depuis longtemps.
     */
    public static function purge() {
        global $DB;
        $DB->delete_records_select(self::LEDGER, 'timecreated < ?', array(time() - self::PURGE_AFTER));
    }

    // ------------------------------------------------------------------
    //  Coupe-circuit du fournisseur
    // ------------------------------------------------------------------

    /**
     * Suspension en cours, ou null.
     *
     * @return \stdClass|null {until (timestamp, ou -1 = jusqu'à remise en
     *                        service), reason, detail}
     */
    public static function blocked() {
        $until = (int)get_config('local_aichat', 'ws_blockeduntil');
        if ($until === 0 || ($until > 0 && $until <= time())) {
            return null;
        }
        return (object)array(
            'until'  => $until,
            'reason' => (string)get_config('local_aichat', 'ws_blockedreason'),
            'detail' => (string)get_config('local_aichat', 'ws_blockeddetail'),
        );
    }

    /**
     * Suspend le fournisseur. Une suspension plus longue déjà en place est
     * conservée (une panne passagère n'écourte pas une clé refusée).
     *
     * @param string $reason
     * @param int    $seconds durée, ou result::BLOCK_MANUAL
     * @param string $detail
     */
    public static function block($reason, $seconds, $detail = '') {
        $until   = ((int)$seconds === result::BLOCK_MANUAL) ? -1 : time() + max(1, (int)$seconds);
        $current = self::blocked();
        if ($current !== null && ($current->until === -1 || ($until !== -1 && $current->until >= $until))) {
            return;
        }
        set_config('ws_blockeduntil', $until, 'local_aichat');
        set_config('ws_blockedreason', (string)$reason, 'local_aichat');
        set_config('ws_blockeddetail', substr((string)$detail, 0, 300), 'local_aichat');
    }

    /** Remise en service par l'administrateur. */
    public static function unblock() {
        set_config('ws_blockeduntil', 0, 'local_aichat');
        set_config('ws_blockedreason', '', 'local_aichat');
        set_config('ws_blockeddetail', '', 'local_aichat');
    }

    // ------------------------------------------------------------------
    //  Diagnostic
    // ------------------------------------------------------------------

    /** Mémorise les dernières limites annoncées par le fournisseur. */
    public static function record_ratelimit($ratelimit) {
        if (is_array($ratelimit) && !empty($ratelimit)) {
            set_config('ws_ratelimit', json_encode($ratelimit), 'local_aichat');
        }
    }

    /** @return array|null dernières limites annoncées */
    public static function last_ratelimit() {
        $data = json_decode((string)get_config('local_aichat', 'ws_ratelimit'), true);
        return is_array($data) ? $data : null;
    }

    /** Mémorise le dernier échec (affiché sur la page de diagnostic). */
    public static function record_error($reason, $detail) {
        set_config('ws_lasterror', json_encode(array(
            'time'   => time(),
            'reason' => (string)$reason,
            'detail' => substr((string)$detail, 0, 300),
        ), JSON_UNESCAPED_UNICODE), 'local_aichat');
    }

    /** @return array|null {time, reason, detail} */
    public static function last_error() {
        $data = json_decode((string)get_config('local_aichat', 'ws_lasterror'), true);
        return is_array($data) ? $data : null;
    }
}
