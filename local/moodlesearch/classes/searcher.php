<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

use local_aichat\websearch\budget;
use local_aichat\websearch\manager as aichat;
use local_aichat\websearch\result;
use local_aichat\websearch\searchcache;
use local_aichat\websearch\tavily_provider;
use local_aichat\websearch\userkeys;

/**
 * Une recherche MoodleSearch : des résultats, et rien d'autre (jamais de
 * réponse rédigée par une IA).
 *
 * Tout ce qui touche au moteur est celui du Tuteur IA, pour que le compte
 * Tavily de l'élève soit géré d'un seul tenant :
 *   - sa clé personnelle (userkeys) et l'état de cette clé (refusée, épuisée) ;
 *   - le plafond de la clé sur 31 jours, sous verrou (budget) ;
 *   - le cache des recherches (searchcache) : une recherche déjà faite, par
 *     n'importe qui, ne reconsomme aucun crédit.
 */
class searcher {

    const TAB_WEB  = 'web';
    const TAB_NEWS = 'news';
    const TABS     = array(self::TAB_WEB, self::TAB_NEWS);
    const PERIODS  = array('any', 'day', 'week', 'month', 'year');

    /** Résultats pour « Plus de résultats » (maximum de Tavily). */
    const MORE_COUNT = 20;

    /** Fraîcheur maximale en cache des actualités et des recherches datées. */
    const FRESH_MAXAGE = 3600;

    /** Longueur maximale d'une requête. */
    const MAXQUERY = 400;

    const TABLE = 'local_moodlesearch_search';

    /**
     * Cherche, journalise, renvoie les résultats.
     *
     * @param int    $userid
     * @param string $query
     * @param string $tab    web|news
     * @param string $period any|day|week|month|year
     * @param bool   $more   20 résultats au lieu du nombre réglé
     * @return \stdClass {ok, reason, items[], searchid, cached, count}
     */
    public static function search(int $userid, string $query, string $tab = self::TAB_WEB,
            string $period = 'any', bool $more = false): \stdClass {
        $out = (object)array('ok' => false, 'reason' => '', 'items' => array(), 'searchid' => 0,
            'cached' => false, 'count' => 0);

        $query  = self::clean_query($query);
        $tab    = in_array($tab, self::TABS, true) ? $tab : self::TAB_WEB;
        $period = in_array($period, self::PERIODS, true) ? $period : 'any';
        if ($query === '') {
            $out->reason = 'emptyquery';
            return $out;
        }
        $count = $more ? self::MORE_COUNT : self::perpage();
        $out->count = $count;

        $reason = access::reason($userid);
        if ($reason !== '') {
            return self::refuse($out, $userid, $query, $tab, $period, $reason);
        }

        // 1. Cache commun avec le Tuteur IA : gratuit, hors limite horaire.
        $options = self::options($tab, $period);
        $maxage  = ($tab === self::TAB_NEWS || $period !== 'any') ? self::FRESH_MAXAGE : null;
        $cached  = searchcache::get($query, $count, $options, $maxage);
        if ($cached !== null) {
            budget::record_cached(0);
            $out->ok     = true;
            $out->cached = true;
            $out->items  = $cached['items'];
            $out->searchid = self::log($userid, $query, $tab, $period, $out->items, 'cached');
            return $out;
        }

        // 2. Limite horaire par personne (recherches réellement envoyées).
        if (self::recent_count($userid) >= self::maxperhour()) {
            return self::refuse($out, $userid, $query, $tab, $period, 'ratelimit');
        }

        // 3. État de la clé et du moteur.
        $key = userkeys::get($userid, 'tavily');
        if ($key === '') {
            return self::refuse($out, $userid, $query, $tab, $period, access::NOKEY);
        }
        $state = userkeys::state($userid, 'tavily');
        if ($state !== null) {
            return self::refuse($out, $userid, $query, $tab, $period, (string)$state->status);
        }
        if (budget::blocked('tavily') !== null) {
            return self::refuse($out, $userid, $query, $tab, $period, 'provider_unavailable');
        }

        // 4. Réservation dans le plafond de la clé (partagé avec le tuteur).
        $keyhash = userkeys::keyhash('tavily', $key);
        $reservation = budget::reserve('tavily', $keyhash, aichat::keycap('tavily'), 0);
        if (!is_int($reservation)) {
            return self::refuse($out, $userid, $query, $tab, $period, (string)$reservation);
        }

        // 5. Appel au moteur.
        $provider = new tavily_provider($key, aichat::timeout());
        $result = $provider->search($query, $count, $options);
        budget::settle($reservation, $result->billable);

        if (!$result->ok) {
            if ($result->reason === 'auth_error' || $result->reason === 'provider_quota') {
                // Même mémoire que le tuteur : la clé est suspendue pour les deux.
                userkeys::set_state($userid, 'tavily', $result->reason, $result->blockfor, $result->detail);
            } else if ($result->blockfor !== 0) {
                budget::block('tavily', $result->reason, $result->blockfor, $result->detail);
            }
            budget::record_error('tavily', $result->reason, $result->detail);
            $out->reason = $result->reason;
            $out->searchid = self::log($userid, $query, $tab, $period, array(), 'error', $result->reason);
            return $out;
        }

        searchcache::set($query, $count, $result->items, 'Tavily', $options);
        $out->ok       = true;
        $out->items    = $result->items;
        $out->searchid = self::log($userid, $query, $tab, $period, $out->items, 'ok');
        return $out;
    }

    /**
     * Options Tavily de la recherche. Elles font partie de la clé de cache :
     * seules celles qui changent les résultats y figurent.
     */
    public static function options(string $tab, string $period): array {
        // Pas d'icônes de site : les afficher ferait charger à l'élève une image
        // de chaque site (souvent bloqué par le pare-feu, et signalé au site).
        $options = array();
        if ($tab === self::TAB_NEWS) {
            $options['topic'] = 'news';
        }
        if ($period !== 'any') {
            $options['time_range'] = $period;
        }
        $country = trim((string)get_config('local_moodlesearch', 'country'));
        if ($country !== '') {
            $options['country'] = \core_text::strtolower($country);
        }
        $exclude = self::excluded_domains();
        if (!empty($exclude)) {
            $options['exclude_domains'] = $exclude;
        }
        return $options;
    }

    /** Domaines exclus des résultats (réglage, un par ligne, 150 au plus). */
    public static function excluded_domains(): array {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string)get_config('local_moodlesearch', 'excludedomains')) as $d) {
            // « https://www.site.fr/ » exclut tout le site, pas seulement www.
            $d = strtolower(trim(preg_replace('#^(https?://)?(www\.)?#i', '', trim($d)), " /"));
            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d)) {
                $out[$d] = $d;
            }
        }
        return array_slice(array_values($out), 0, 150);
    }

    /** Requête nettoyée : espaces, longueur. */
    public static function clean_query(string $query): string {
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if (\core_text::strlen($query) > self::MAXQUERY) {
            $query = \core_text::substr($query, 0, self::MAXQUERY);
        }
        return $query;
    }

    /** Recherches réellement envoyées par l'utilisateur dans la dernière heure. */
    public static function recent_count(int $userid): int {
        global $DB;
        return (int)$DB->count_records_select(self::TABLE,
            'userid = ? AND timecreated > ? AND status = ?', array($userid, time() - HOURSECS, 'ok'));
    }

    public static function perpage(): int {
        $value = (int)get_config('local_moodlesearch', 'perpage');
        return ($value >= 5 && $value <= self::MORE_COUNT) ? $value : 10;
    }

    public static function maxperhour(): int {
        $value = (int)get_config('local_moodlesearch', 'maxperhour');
        return ($value > 0) ? $value : 30;
    }

    /**
     * Recherches de l'élève sur 31 jours avec sa clé Tavily (tuteur compris),
     * et le plafond de la clé.
     *
     * @return array{used:int, cap:int}|null null sans clé
     */
    public static function key_usage(int $userid): ?array {
        $key = userkeys::get($userid, 'tavily');
        if ($key === '') {
            return null;
        }
        return array('used' => budget::used('tavily', userkeys::keyhash('tavily', $key)),
            'cap' => aichat::keycap('tavily'));
    }

    /**
     * Dernières recherches de l'utilisateur (encadré « Mes dernières recherches »).
     *
     * @return \stdClass[]
     */
    public static function recent(int $userid, int $limit = 10): array {
        global $DB;
        return array_values($DB->get_records_select(self::TABLE, 'userid = ? AND status <> ?',
            array($userid, 'refused'), 'timecreated DESC, id DESC', 'id, query, tab, period, timecreated',
            0, $limit));
    }

    private static function refuse(\stdClass $out, int $userid, string $query, string $tab,
            string $period, string $reason): \stdClass {
        $out->reason = $reason;
        // Même un refus est tracé : l'enseignant voit aussi les tentatives.
        $out->searchid = self::log($userid, $query, $tab, $period, array(), 'refused', $reason);
        return $out;
    }

    /**
     * Journal : la requête et les résultats affichés (url, titre, domaine),
     * pour les clics et le rapport des enseignants.
     */
    private static function log(int $userid, string $query, string $tab, string $period, array $items,
            string $status, string $reason = ''): int {
        global $DB;
        $results = array();
        foreach ($items as $item) {
            $results[] = array(
                'url'    => (string)$item['url'],
                'title'  => \core_text::substr((string)($item['title'] ?? ''), 0, 200),
                'domain' => self::domain((string)$item['url']),
            );
        }
        return (int)$DB->insert_record(self::TABLE, (object)array(
            'userid'      => $userid,
            'query'       => $query,
            'tab'         => $tab,
            'period'      => $period,
            'resultcount' => count($results),
            'results'     => empty($results) ? null : json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'      => $status,
            'reason'      => ($reason !== '') ? substr($reason, 0, 30) : null,
            'timecreated' => time(),
        ));
    }

    /** Domaine affiché d'une URL (sans « www. »). */
    public static function domain(string $url): string {
        $host = (string)parse_url($url, PHP_URL_HOST);
        return preg_replace('/^www\./i', '', strtolower($host));
    }
}
