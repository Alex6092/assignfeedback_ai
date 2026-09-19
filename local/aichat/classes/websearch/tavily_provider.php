<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php'); // classe curl

/**
 * Recherche Web via l'API Tavily.
 *
 * Documentation : https://docs.tavily.com/documentation/api-reference/endpoint/search
 *
 * Offre gratuite : 1 000 crédits par mois, SANS carte bancaire (une recherche
 * « basic » = 1 crédit) : aucune facture possible, d'où sa priorité sur Brave.
 * Crédits épuisés = HTTP 432/433, jamais un débit.
 *
 * Tous les paramètres sont fixés ici ; le modèle ne fournit que le texte de
 * la requête. Tavily ne documentant pas les opérateurs de recherche, les
 * « site:domaine » de la requête sont convertis en include_domains.
 */
class tavily_provider implements provider {

    const ENDPOINT = 'https://api.tavily.com/search';
    const USAGE    = 'https://api.tavily.com/usage';

    /** Tavily n'accepte pas plus de 20 résultats. */
    const MAX_COUNT = 20;

    /** Crédits épuisés : on réessaie la clé le lendemain (le cycle exact n'est pas publié). */
    const QUOTA_RETRY = 86400;

    /** @var string */
    private $apikey;

    /** @var int */
    private $timeout;

    /**
     * @param string   $apikey  clé en clair
     * @param int|null $timeout secondes (null = réglage du plugin)
     */
    public function __construct($apikey, $timeout = null) {
        $this->apikey  = trim((string)$apikey);
        $this->timeout = ($timeout !== null) ? (int)$timeout : manager::timeout();
    }

    public function name(): string {
        return 'Tavily';
    }

    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    public function search(string $query, int $count): result {
        if (!$this->is_configured()) {
            return result::failure('not_configured', false);
        }
        list($text, $domains) = self::split_sites($query);
        $body = array(
            'query'        => ($text !== '') ? $text : $query,
            'search_depth' => 'basic',   // 1 crédit
            'topic'        => 'general',
            'max_results'  => max(1, min(self::MAX_COUNT, $count)),
            // Public scolaire : filtrage des contenus pour adultes, non négociable.
            'safe_search'  => true,
        );
        if (!empty($domains)) {
            $body['include_domains'] = $domains;
        }

        $response  = $this->request('post', self::ENDPOINT, json_encode($body, JSON_UNESCAPED_UNICODE));
        $connected = isset($response->info['connect_time']) && (float)$response->info['connect_time'] > 0;

        if ($response->errno === 28) {
            return result::failure('timeout', $connected, 60, 'délai dépassé (' . $this->timeout . ' s)');
        }
        if ($response->errno !== 0 || $response->httpcode === 0) {
            return result::failure('provider_error', false, 60,
                $this->hide_key('réseau : curl ' . $response->errno . ' ' . $response->error));
        }
        if ($response->httpcode === 200) {
            $data = json_decode($response->raw, true);
            if (!is_array($data)) {
                return result::failure('provider_error', true, 0, 'réponse illisible');
            }
            return result::success(self::extract_items($data));
        }

        $detail = $this->hide_key('HTTP ' . $response->httpcode . self::error_detail($response->raw));
        switch ($response->httpcode) {
            case 401:
            case 403:
                return result::failure('auth_error', false, result::BLOCK_MANUAL, $detail);
            case 432:
            case 433:
                return result::failure('provider_quota', false, self::QUOTA_RETRY, $detail);
            case 429:
                return result::failure('rate_limited', false, 0, $detail);
            case 400:
            case 422:
                return result::failure('invalid_query', false, 0, $detail);
            default:
                return result::failure('provider_error', false, 60, $detail);
        }
    }

    /**
     * Crédits de la clé (page « mes clés » : test sans consommer de recherche).
     *
     * @return \stdClass {ok, reason, detail, usage, limit, plan}
     */
    public function usage() {
        $out = (object)array('ok' => false, 'reason' => '', 'detail' => '', 'usage' => null,
            'limit' => null, 'plan' => '');
        if (!$this->is_configured()) {
            $out->reason = 'not_configured';
            return $out;
        }
        $response = $this->request('get', self::USAGE, null);
        if ($response->errno !== 0 || $response->httpcode === 0) {
            $out->reason = ($response->errno === 28) ? 'timeout' : 'provider_error';
            $out->detail = $this->hide_key('réseau : curl ' . $response->errno . ' ' . $response->error);
            return $out;
        }
        if ($response->httpcode === 401 || $response->httpcode === 403) {
            $out->reason = 'auth_error';
            $out->detail = $this->hide_key('HTTP ' . $response->httpcode . self::error_detail($response->raw));
            return $out;
        }
        $data = json_decode($response->raw, true);
        if ($response->httpcode !== 200 || !is_array($data)) {
            $out->reason = 'provider_error';
            $out->detail = 'HTTP ' . $response->httpcode;
            return $out;
        }
        $out->ok = true;
        // Crédits du compte (offre gratuite) de préférence, sinon ceux de la clé.
        if (isset($data['account']['plan_usage'])) {
            $out->usage = (int)$data['account']['plan_usage'];
            $out->limit = isset($data['account']['plan_limit']) ? (int)$data['account']['plan_limit'] : null;
            $out->plan  = isset($data['account']['current_plan']) ? (string)$data['account']['current_plan'] : '';
        } else if (isset($data['key']['usage'])) {
            $out->usage = (int)$data['key']['usage'];
            $out->limit = isset($data['key']['limit']) ? (int)$data['key']['limit'] : null;
        }
        return $out;
    }

    /**
     * Sépare les opérateurs « site:domaine » du reste de la requête.
     *
     * @param string $query
     * @return array [requête sans les opérateurs, domaines]
     */
    public static function split_sites($query) {
        $domains = array();
        $text = preg_replace_callback('/(?<!\S)site:([a-z0-9.-]+\.[a-z]{2,})(?:\/\S*)?/i',
            function($m) use (&$domains) {
                $domains[strtolower($m[1])] = strtolower($m[1]);
                return ' ';
            }, (string)$query);
        return array(trim(preg_replace('/\s+/u', ' ', $text)), array_values($domains));
    }

    /**
     * Résultats Tavily au format commun des moteurs.
     *
     * @param array $data réponse décodée
     * @return array[] {title, url, snippet, age, extra}
     */
    public static function extract_items(array $data) {
        $items = array();
        if (empty($data['results']) || !is_array($data['results'])) {
            return $items;
        }
        foreach ($data['results'] as $row) {
            if (!is_array($row) || !isset($row['url']) || !is_string($row['url'])) {
                continue;
            }
            $url = trim($row['url']);
            if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
                continue;
            }
            $items[] = array(
                'title'   => self::plain(isset($row['title']) ? $row['title'] : ''),
                'url'     => $url,
                'snippet' => self::plain(isset($row['content']) ? $row['content'] : ''),
                'age'     => self::plain(isset($row['published_date']) ? $row['published_date'] : ''),
                'extra'   => array(),
            );
        }
        return $items;
    }

    /**
     * Une requête HTTP à l'API.
     *
     * @param string      $method get|post
     * @param string      $url
     * @param string|null $body   JSON (post)
     * @return \stdClass {raw, errno, error, info, httpcode}
     */
    private function request($method, $url, $body) {
        $curl = new \curl();
        $curl->setHeader(array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ));
        $options = array(
            'CURLOPT_TIMEOUT'        => max(2, $this->timeout),
            'CURLOPT_CONNECTTIMEOUT' => 3,
        );
        $raw  = ($method === 'post') ? $curl->post($url, $body, $options) : $curl->get($url, array(), $options);
        $info = is_array($curl->info) ? $curl->info : array();
        return (object)array(
            'raw'      => (string)$raw,
            'errno'    => (int)$curl->get_errno(),
            'error'    => (string)$curl->error,
            'info'     => $info,
            'httpcode' => isset($info['http_code']) ? (int)$info['http_code'] : 0,
        );
    }

    /** Détail lisible d'une réponse d'erreur Tavily ({"detail": {"error": …}}). */
    private static function error_detail($raw) {
        $data = json_decode((string)$raw, true);
        if (is_array($data) && isset($data['detail']['error']) && is_string($data['detail']['error'])) {
            return ' : ' . substr($data['detail']['error'], 0, 300);
        }
        return '';
    }

    /** Texte brut d'un champ (balises retirées, entités décodées). */
    private static function plain($value) {
        if (!is_string($value)) {
            return '';
        }
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** La clé ne doit jamais apparaître dans un message, même par accident. */
    private function hide_key($text) {
        return ($this->apikey === '') ? $text : str_replace($this->apikey, '[clé]', $text);
    }
}
