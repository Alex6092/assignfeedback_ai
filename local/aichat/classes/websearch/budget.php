<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Budget de la recherche Web : plafond PAR CLÉ, plafond par élève et
 * coupe-circuit des moteurs.
 *
 * Chaque élève cherche avec ses propres clés (userkeys) : on protège donc
 * chaque clé, sur une fenêtre GLISSANTE de 31 jours. Comme un cycle de
 * facturation dure au plus 31 jours, aucun cycle ne peut dépasser le plafond,
 * quelle que soit sa date de début. C'est vital pour Brave, qui débite la
 * carte de l'élève au-delà de ses crédits gratuits ; pour Tavily (sans
 * carte), le plafond évite seulement des appels voués à l'échec.
 *
 * Le registre ({local_aichat_wsledger}) ne contient ni la clé ni l'élève :
 * seulement le moteur, une empreinte de la clé, l'activité (statistiques) et
 * la date. Aucune suppression RGPD ni réinitialisation de cours ne le touche :
 * effacer des lignes en cours de fenêtre ferait baisser le compteur.
 *
 * Concurrence : la lecture du compteur et l'inscription de la réservation se
 * font sous un verrou Moodle. Deux réponses simultanées avec la même clé à
 * 899/900 sont sérialisées : la seconde lit 900 et renonce.
 */
class budget {

    const LEDGER = 'local_aichat_wsledger';

    /** Fenêtre des plafonds : 31 jours (le plus long cycle mensuel). */
    const WINDOW = 2678400;

    /** Les lignes du registre sont conservées un peu au-delà de la fenêtre. */
    const PURGE_AFTER = 3456000; // 40 jours

    /** Attente maximale du verrou avant de renoncer à la recherche. */
    const LOCK_TIMEOUT = 3;

    /**
     * Recherches décomptées sur la fenêtre : réservées ou faites. Les
     * remboursées (erreur non facturée) et celles servies par le cache ne
     * comptent pas.
     *
     * @param string|null $provider limiter à un moteur
     * @param string|null $keyhash  limiter à une clé
     * @return int
     */
    public static function used($provider = null, $keyhash = null) {
        global $DB;
        $where  = 'timecreated > ? AND status IN (?, ?)';
        $params = array(time() - self::WINDOW, 'reserved', 'done');
        if ($provider !== null) {
            $where   .= ' AND provider = ?';
            $params[] = (string)$provider;
        }
        if ($keyhash !== null) {
            $where   .= ' AND keyhash = ?';
            $params[] = (string)$keyhash;
        }
        return (int)$DB->count_records_select(self::LEDGER, $where, $params);
    }

    /**
     * Réserve une recherche dans le plafond d'une clé, AVANT l'appel au
     * moteur.
     *
     * @param string $provider tavily|brave
     * @param string $keyhash  empreinte de la clé (userkeys::keyhash())
     * @param int    $cap      plafond de la clé sur la fenêtre (0 = aucune recherche)
     * @param int    $cmid     activité (statistiques)
     * @return int|string id de la réservation, ou raison du refus
     *                    ('quota_exhausted' | 'budget_busy')
     */
    public static function reserve($provider, $keyhash, $cap, $cmid = 0) {
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
            if (self::used($provider, $keyhash) >= (int)$cap) {
                return 'quota_exhausted';
            }
            return (int)$DB->insert_record(self::LEDGER, (object)array(
                'status'      => 'reserved',
                'provider'    => (string)$provider,
                'keyhash'     => (string)$keyhash,
                'cmid'        => (int)$cmid,
                'timecreated' => time(),
            ));
        } finally {
            $lock->release();
        }
    }

    /**
     * Solde une réservation : « done » si le moteur a pu décompter la requête,
     * « refunded » sinon (erreur HTTP, jamais décomptée).
     *
     * @param int  $id
     * @param bool $billable
     */
    public static function settle($id, $billable) {
        global $DB;
        $DB->set_field(self::LEDGER, 'status', $billable ? 'done' : 'refunded', array('id' => (int)$id));
    }

    /**
     * Inscrit une recherche servie par le cache (suivi des économies ; hors
     * de tout compteur de budget).
     *
     * @param int $cmid
     */
    public static function record_cached($cmid = 0) {
        global $DB;
        $DB->insert_record(self::LEDGER, (object)array('status' => 'cached', 'provider' => '', 'keyhash' => '',
            'cmid' => (int)$cmid, 'timecreated' => time()));
    }

    /** Recherches servies par le cache sur la fenêtre de 31 jours. */
    public static function cached_count() {
        global $DB;
        return (int)$DB->count_records_select(self::LEDGER,
            'timecreated > ? AND status = ?', array(time() - self::WINDOW, 'cached'));
    }

    /**
     * Activités qui font le plus de recherches sur la fenêtre (diagnostic).
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
     * Recherches faites par un élève sur la fenêtre de son quota (4 h par
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
    //  Coupe-circuit d'un moteur (panne : réseau, erreurs serveur)
    //  Les refus propres à une clé (clé refusée, crédits épuisés) sont
    //  mémorisés sur la clé de l'élève : voir userkeys::state().
    // ------------------------------------------------------------------

    /**
     * Suspension en cours d'un moteur, ou null.
     *
     * @param string $provider
     * @return \stdClass|null {until (timestamp, ou -1 = jusqu'à remise en
     *                        service), reason, detail}
     */
    public static function blocked($provider) {
        $until = (int)get_config('local_aichat', 'ws_blockeduntil_' . $provider);
        if ($until === 0 || ($until > 0 && $until <= time())) {
            return null;
        }
        return (object)array(
            'until'  => $until,
            'reason' => (string)get_config('local_aichat', 'ws_blockedreason_' . $provider),
            'detail' => (string)get_config('local_aichat', 'ws_blockeddetail_' . $provider),
        );
    }

    /**
     * Suspend un moteur. Une suspension plus longue déjà en place est
     * conservée.
     *
     * @param string $provider
     * @param string $reason
     * @param int    $seconds durée, ou result::BLOCK_MANUAL
     * @param string $detail
     */
    public static function block($provider, $reason, $seconds, $detail = '') {
        $until   = ((int)$seconds === result::BLOCK_MANUAL) ? -1 : time() + max(1, (int)$seconds);
        $current = self::blocked($provider);
        if ($current !== null && ($current->until === -1 || ($until !== -1 && $current->until >= $until))) {
            return;
        }
        set_config('ws_blockeduntil_' . $provider, $until, 'local_aichat');
        set_config('ws_blockedreason_' . $provider, (string)$reason, 'local_aichat');
        set_config('ws_blockeddetail_' . $provider, substr((string)$detail, 0, 300), 'local_aichat');
    }

    /** Remise en service d'un moteur par l'administrateur. */
    public static function unblock($provider) {
        set_config('ws_blockeduntil_' . $provider, 0, 'local_aichat');
        set_config('ws_blockedreason_' . $provider, '', 'local_aichat');
        set_config('ws_blockeddetail_' . $provider, '', 'local_aichat');
    }

    // ------------------------------------------------------------------
    //  Diagnostic
    // ------------------------------------------------------------------

    /** Mémorise les dernières limites annoncées par un moteur (en-têtes). */
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
    public static function record_error($provider, $reason, $detail) {
        set_config('ws_lasterror', json_encode(array(
            'time'     => time(),
            'provider' => (string)$provider,
            'reason'   => (string)$reason,
            'detail'   => substr((string)$detail, 0, 300),
        ), JSON_UNESCAPED_UNICODE), 'local_aichat');
    }

    /** @return array|null {time, provider, reason, detail} */
    public static function last_error() {
        $data = json_decode((string)get_config('local_aichat', 'ws_lasterror'), true);
        return is_array($data) ? $data : null;
    }
}
