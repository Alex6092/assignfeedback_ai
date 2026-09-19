<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php'); // classe curl

/**
 * Recherche Web via l'API Brave Search (endpoint « web »).
 *
 * Documentation : https://api-dashboard.search.brave.com/documentation
 *
 * Points qui comptent pour le budget :
 *   - Brave FACTURE au-delà des crédits mensuels, il ne refuse pas : le seul
 *     plafond fiable est celui de Moodle (budget). Les en-têtes X-RateLimit-*
 *     décrivent la limite du PLAN (« par seconde, par mois »), pas les crédits
 *     restants ; ils sont conservés pour le diagnostic ;
 *   - seules les réponses réussies sont facturées (doc Brave) : une erreur
 *     HTTP permet de rembourser la réservation ; un délai dépassé, non (on ne
 *     sait pas si Brave a répondu).
 *
 * Tous les paramètres de la requête sont fixés ici : le modèle ne fournit que
 * le texte de la requête.
 */
class brave_provider implements provider {

    const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    /** Brave n'accepte pas plus de 20 résultats par page. */
    const MAX_COUNT = 20;

    /** @var string */
    private $apikey;

    /** @var int délai total de la requête, en secondes */
    private $timeout;

    /**
     * @param string|null $apikey  clé en clair (null = réglage du plugin)
     * @param int|null    $timeout secondes (null = réglage du plugin)
     */
    public function __construct($apikey = null, $timeout = null) {
        $this->apikey  = ($apikey !== null) ? trim((string)$apikey) : manager::apikey();
        $this->timeout = ($timeout !== null) ? (int)$timeout : manager::timeout();
    }

    public function name(): string {
        return 'Brave Search';
    }

    public function is_configured(): bool {
        return $this->apikey !== '';
    }

    public function search(string $query, int $count): result {
        if (!$this->is_configured()) {
            return result::failure('not_configured', false);
        }

        $params = array(
            'q'                => $query,
            'count'            => max(1, min(self::MAX_COUNT, $count)),
            // Public scolaire : filtrage le plus strict, non négociable.
            'safesearch'       => 'strict',
            // Pas de balises <strong> dans les extraits.
            'text_decorations' => 'false',
            // Seulement les pages Web (ni vidéos, ni actualités, ni lieux).
            'result_filter'    => 'web',
            // Jusqu'à 5 extraits de plus par page : bien plus de matière pour
            // le modèle, pour le même prix (une requête).
            'extra_snippets'   => 'true',
        );

        $response = $this->request($params);
        if ($response->errno === 0 && ($response->httpcode === 400 || $response->httpcode === 422)) {
            // Paramètre refusé par l'offre du compte, par exemple : on retente
            // sans les extraits supplémentaires. Une erreur n'est pas facturée.
            unset($params['extra_snippets']);
            $response = $this->request($params);
        }
        $raw       = $response->raw;
        $errno     = $response->errno;
        $info      = $response->info;
        $httpcode  = $response->httpcode;
        $ratelimit = $response->ratelimit;

        // Une fois connecté, Brave a pu traiter (et facturer) la requête même si
        // nous n'avons pas eu la réponse : dans le doute, elle est comptée.
        $connected = isset($info['connect_time']) && (float)$info['connect_time'] > 0;
        if ($errno === 28) {
            return result::failure('timeout', $connected, 60,
                'délai dépassé (' . $this->timeout . ' s)', $ratelimit);
        }
        if ($errno !== 0 || $httpcode === 0) {
            return result::failure('provider_error', $connected && $errno !== 0, 60,
                $this->hide_key('réseau : curl ' . $errno . ' ' . $response->error), $ratelimit);
        }

        if ($httpcode === 200) {
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                return result::failure('provider_error', true, 0, 'réponse illisible', $ratelimit);
            }
            return result::success(self::extract_items($data), $ratelimit);
        }

        $detail = $this->hide_key('HTTP ' . $httpcode . self::error_detail((string)$raw));

        if ($httpcode === 429) {
            // 429 de la fenêtre mensuelle du plan (restant 0) : inutile
            // d'insister avant sa réinitialisation. Sinon, simple limite par
            // seconde : seule cette recherche est perdue.
            $monthly = isset($ratelimit['remaining'][1]) ? (int)$ratelimit['remaining'][1] : null;
            if ($monthly === 0) {
                $reset = isset($ratelimit['reset'][1]) ? (int)$ratelimit['reset'][1] : 0;
                return result::failure('provider_quota', false, max(3600, $reset), $detail, $ratelimit);
            }
            return result::failure('rate_limited', false, 0, $detail, $ratelimit);
        }
        if ($httpcode === 401 || $httpcode === 403) {
            return result::failure('auth_error', false, result::BLOCK_MANUAL, $detail, $ratelimit);
        }
        if ($httpcode === 400 || $httpcode === 422) {
            return result::failure('invalid_query', false, 0, $detail, $ratelimit);
        }
        return result::failure('provider_error', false, 60, $detail, $ratelimit);
    }

    /**
     * Une requête HTTP à l'API.
     *
     * @param array $params paramètres de la requête
     * @return \stdClass {raw, errno, error, info, httpcode, ratelimit}
     */
    private function request(array $params) {
        $curl = new \curl();
        $curl->setHeader(array(
            'Accept: application/json',
            'X-Subscription-Token: ' . $this->apikey,
        ));
        $raw  = $curl->get(self::ENDPOINT, $params, array(
            'CURLOPT_TIMEOUT'        => max(2, $this->timeout),
            'CURLOPT_CONNECTTIMEOUT' => 3,
        ));
        $info = is_array($curl->info) ? $curl->info : array();
        return (object)array(
            'raw'       => (string)$raw,
            'errno'     => (int)$curl->get_errno(),
            'error'     => (string)$curl->error,
            'info'      => $info,
            'httpcode'  => isset($info['http_code']) ? (int)$info['http_code'] : 0,
            'ratelimit' => self::parse_ratelimit((array)$curl->getResponse()),
        );
    }

    /**
     * Résultats Web de la réponse Brave, nettoyés (texte brut, URL http/https).
     *
     * @param array $data réponse décodée
     * @return array[] {title, url, snippet, age, extra (extraits supplémentaires)}
     */
    public static function extract_items(array $data) {
        $items = array();
        if (empty($data['web']['results']) || !is_array($data['web']['results'])) {
            return $items;
        }
        foreach ($data['web']['results'] as $row) {
            if (!is_array($row) || !isset($row['url']) || !is_string($row['url'])) {
                continue;
            }
            $url = trim($row['url']);
            if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
                continue;
            }
            $snippet = self::plain(isset($row['description']) ? $row['description'] : '');
            $extra   = array();
            if (!empty($row['extra_snippets']) && is_array($row['extra_snippets'])) {
                foreach ($row['extra_snippets'] as $piece) {
                    $piece = self::plain($piece);
                    // Doublons de l'extrait principal ou entre eux : inutiles.
                    if ($piece !== '' && $piece !== $snippet && !in_array($piece, $extra, true)) {
                        $extra[] = $piece;
                    }
                    if (count($extra) >= 5) {
                        break;
                    }
                }
            }
            $items[] = array(
                'title'   => self::plain(isset($row['title']) ? $row['title'] : ''),
                'url'     => $url,
                'snippet' => $snippet,
                'age'     => self::plain(isset($row['age']) ? $row['age'] : ''),
                'extra'   => $extra,
            );
        }
        return $items;
    }

    /**
     * En-têtes X-RateLimit-* : listes « par seconde, par mois » ; Reset en
     * secondes relatives.
     *
     * @param array $headers en-têtes de réponse (curl::getResponse())
     * @return array|null {limit[], remaining[], reset[], policy, time}
     */
    public static function parse_ratelimit(array $headers) {
        $find = function($name) use ($headers) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string)$key, $name) === 0) {
                    return is_array($value) ? (string)end($value) : (string)$value;
                }
            }
            return null;
        };
        $out = array();
        foreach (array('limit' => 'X-RateLimit-Limit', 'remaining' => 'X-RateLimit-Remaining',
                       'reset' => 'X-RateLimit-Reset') as $key => $header) {
            $value = $find($header);
            if ($value !== null && trim($value) !== '') {
                $out[$key] = array_map('intval', array_map('trim', explode(',', $value)));
            }
        }
        $policy = $find('X-RateLimit-Policy');
        if ($policy !== null) {
            $out['policy'] = substr(trim($policy), 0, 200);
        }
        if (empty($out)) {
            return null;
        }
        $out['time'] = time();
        return $out;
    }

    /** Détail lisible d'une réponse d'erreur Brave ({"error": {code, detail}}). */
    private static function error_detail($raw) {
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['error']) || !is_array($data['error'])) {
            return '';
        }
        $err = $data['error'];
        $out = isset($err['code']) ? ' ' . (string)$err['code'] : '';
        if (isset($err['detail']) && is_string($err['detail'])) {
            $out .= ' : ' . $err['detail'];
        }
        return substr($out, 0, 300);
    }

    /** Texte brut d'un champ Brave (balises retirées, entités décodées). */
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
