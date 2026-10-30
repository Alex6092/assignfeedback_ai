<?php
namespace local_aifeedback\lb;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php'); // classe curl

use local_aifeedback\secret;

/**
 * Client HTTP du load balancer d'inférence (« Providence IA »).
 *
 * Le load balancer met en file les générations et les répartit entre les
 * serveurs IA : Moodle ne fait plus aucune répartition lui-même. Deux modes :
 *   - asynchrone : POST /v1/jobs avec une callbackURL ; le résultat revient
 *     par un callback signé (voir callback.php et \local_aifeedback\jobs) ;
 *   - synchrone / streaming : POST /v1/chat/completions (tuteur), appelé par
 *     \local_aifeedback\api::stream().
 *
 * Toutes les requêtes portent la clé de l'application (Authorization) et un
 * identifiant d'utilisateur opaque (X-End-User-ID) servant aux quotas.
 */
class client {

    /** Délais réseau des appels de contrôle (secondes). */
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT         = 20;

    /** Fraîcheur du catalogue des modèles (secondes) ; échec mémorisé moins longtemps. */
    const MODELS_TTL       = 300;
    const MODELS_ERROR_TTL = 60;

    /** @var array|null erreur de catalogue déjà rencontrée pendant cette requête */
    private static $modelserror = null;

    // =====================================================================
    //  CONFIGURATION
    // =====================================================================

    /**
     * URL de base du service, sans « / » final ni « /v1 ».
     */
    public static function base_url() {
        $url = rtrim(trim((string)get_config('local_aifeedback', 'lb_url')), '/');
        if (substr($url, -3) === '/v1') {
            $url = substr($url, 0, -3);
        }
        return $url;
    }

    /** Clé d'API de l'application (en clair). */
    public static function apikey() {
        return secret::decrypt((string)get_config('local_aifeedback', 'lb_apikey'));
    }

    /** Secret de signature des callbacks (en clair). */
    public static function callback_secret() {
        return secret::decrypt((string)get_config('local_aifeedback', 'lb_callbacksecret'));
    }

    /** Le load balancer est-il configuré (URL + clé) ? */
    public static function configured() {
        return self::base_url() !== '' && self::apikey() !== '';
    }

    /** Point d'accès du chat synchrone / streaming. */
    public static function chat_url() {
        return self::base_url() . '/v1/chat/completions';
    }

    /** Lien vers l'administration du load balancer. */
    public static function admin_url() {
        return self::base_url() . '/admin/';
    }

    /**
     * URL appelée par le load balancer à la fin d'un job. Elle doit appartenir
     * aux « URL de base de callback » déclarées pour l'application.
     *
     * @param int $rowid ligne {local_aifeedback_job}
     */
    public static function callback_url($rowid = 0) {
        $base = trim((string)get_config('local_aifeedback', 'lb_callbackurl'));
        if ($base === '') {
            $base = self::default_callback_url();
        }
        if ((int)$rowid <= 0) {
            return $base;
        }
        return $base . (strpos($base, '?') === false ? '?' : '&') . 'id=' . (int)$rowid;
    }

    /** URL de callback par défaut (racine du site). */
    public static function default_callback_url() {
        global $CFG;
        return $CFG->wwwroot . '/local/aifeedback/callback.php';
    }

    /**
     * Identifiant opaque et stable d'un utilisateur Moodle pour le load
     * balancer (quotas et statistiques par utilisateur).
     *
     * @param int $userid 0 = traitement technique sans utilisateur
     */
    public static function end_user_id($userid) {
        return ((int)$userid > 0) ? 'moodle-' . (int)$userid : 'moodle-system';
    }

    /**
     * Clé d'idempotence d'une soumission : renvoyer la même demande (réponse
     * perdue, nouvel essai du cron) rend le job déjà créé au lieu d'en créer
     * un second. Le préfixe de site évite les collisions entre plusieurs
     * Moodle partageant une clé (préproduction / production).
     *
     * @param int $rowid   ligne {local_aifeedback_job}
     * @param int $attempt numéro de soumission de cette ligne
     */
    public static function idempotency_key($rowid, $attempt = 1) {
        global $CFG;
        $site = substr(sha1((string)$CFG->siteidentifier), 0, 10);
        return 'm' . $site . '-' . (int)$rowid . '-' . max(1, (int)$attempt);
    }

    /**
     * En-têtes communs aux appels de l'API applicative.
     *
     * @param int|null    $userid  null = pas d'en-tête utilisateur
     * @param string|null $idemkey
     */
    public static function headers($userid = null, $idemkey = null) {
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . self::apikey(),
        );
        if ($userid !== null) {
            $headers[] = 'X-End-User-ID: ' . self::end_user_id((int)$userid);
        }
        if ($idemkey !== null && $idemkey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idemkey;
        }
        return $headers;
    }

    /**
     * Instance curl pour le load balancer. Son URL est fixée par
     * l'administrateur : elle n'est pas soumise à la liste des hôtes bloqués
     * (le service est en général sur le réseau interne).
     */
    public static function new_curl() {
        return new \curl(array('ignoresecurity' => true));
    }

    // =====================================================================
    //  JOBS ASYNCHRONES
    // =====================================================================

    /**
     * Soumet un job asynchrone.
     *
     * @param array  $body    corps Chat Completions (+ callbackURL)
     * @param int    $userid  utilisateur pour qui le job est fait (0 = système)
     * @param string $idemkey voir idempotency_key()
     * @return array job public (id, status, queue…)
     * @throws lb_exception
     */
    public static function submit_job(array $body, $userid, $idemkey) {
        return self::request('POST', '/v1/jobs', $body, self::headers((int)$userid, $idemkey));
    }

    /**
     * État (et résultat) d'un job.
     *
     * @param string $id
     * @return array
     * @throws lb_exception
     */
    public static function get_job($id) {
        return self::request('GET', '/v1/jobs/' . rawurlencode((string)$id), null, self::headers());
    }

    /**
     * Annule un job (idempotent, y compris sur un job terminé).
     *
     * @param string $id
     * @return array
     * @throws lb_exception
     */
    public static function cancel_job($id) {
        return self::request('POST', '/v1/jobs/' . rawurlencode((string)$id) . '/cancel',
            new \stdClass(), self::headers());
    }

    /**
     * Annule un job SEULEMENT s'il attend encore son tour.
     *
     * Interrompre une génération déjà commencée rend la tentative
     * « incertaine » pour le load balancer : la place du serveur IA reste
     * occupée jusqu'à l'échéance de la tentative, puis le serveur est écarté
     * jusqu'à confirmation manuelle de l'arrêt. Une génération commencée est
     * donc laissée aller à son terme (son résultat sera ignoré).
     *
     * @param string $id
     * @return bool true si le job a été annulé
     */
    public static function cancel_if_queued($id) {
        try {
            $job = self::get_job($id);
            if (isset($job['status']) && $job['status'] === 'queued') {
                self::cancel_job($id);
                return true;
            }
        } catch (\Throwable $e) {
            debugging('local_aifeedback: annulation impossible — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return false;
    }

    // =====================================================================
    //  CATALOGUE, ÉTAT, CONSOMMATION
    // =====================================================================

    /**
     * Modèles autorisés pour la clé, indexés par identifiant. Mis en cache.
     *
     * @param bool $refresh ignorer le cache
     * @return array[] id => {id, vision, context_tokens, max_output_tokens}
     * @throws lb_exception si le catalogue est indisponible
     */
    public static function models($refresh = false) {
        if (!self::configured()) {
            throw new lb_exception(0, 'not_configured',
                get_string('lb_notconfigured', 'local_aifeedback'), false);
        }
        $cache = \cache::make('local_aifeedback', 'lbmodels');
        $key   = sha1(self::base_url() . '|' . self::apikey());
        $now   = time();

        if (!$refresh) {
            if (self::$modelserror !== null) {
                throw self::$modelserror;
            }
            $cached = $cache->get($key);
            if (is_array($cached) && isset($cached['time'])) {
                if (isset($cached['models']) && $now - (int)$cached['time'] < self::MODELS_TTL) {
                    return $cached['models'];
                }
                if (isset($cached['error']) && $now - (int)$cached['time'] < self::MODELS_ERROR_TTL) {
                    self::$modelserror = new lb_exception(0, 'unavailable', (string)$cached['error'], true);
                    throw self::$modelserror;
                }
            }
        }

        try {
            $data = self::request('GET', '/v1/models', null, self::headers(), 5);
        } catch (lb_exception $e) {
            $cache->set($key, array('time' => $now, 'error' => $e->a));
            self::$modelserror = $e;
            throw $e;
        }
        $models = array();
        foreach ((isset($data['data']) && is_array($data['data'])) ? $data['data'] : array() as $m) {
            if (!is_array($m) || !isset($m['id'])) {
                continue;
            }
            $models[(string)$m['id']] = array(
                'id'                => (string)$m['id'],
                'vision'            => !empty($m['vision']),
                'context_tokens'    => isset($m['context_tokens']) ? (int)$m['context_tokens'] : 0,
                'max_output_tokens' => isset($m['max_output_tokens']) ? (int)$m['max_output_tokens'] : 0,
            );
        }
        $cache->set($key, array('time' => $now, 'models' => $models));
        self::$modelserror = null;
        return $models;
    }

    /**
     * Description d'un modèle du catalogue, ou null (inconnu, ou catalogue
     * indisponible : l'appelant continue alors sans plafonner).
     *
     * @param string $id
     * @return array|null
     */
    public static function model($id) {
        try {
            $models = self::models();
        } catch (\Throwable $e) {
            return null;
        }
        return isset($models[(string)$id]) ? $models[(string)$id] : null;
    }

    /**
     * Disponibilité et capacité agrégées par modèle.
     *
     * @return array {data: [{model, workers, available_workers, capacity, occupied}], calculated_at}
     * @throws lb_exception
     */
    public static function workers_status() {
        return self::request('GET', '/v1/workers/status', null, self::headers(), 5);
    }

    /**
     * Consommation de l'application et budgets de quota applicables.
     *
     * @param int    $since horodatage de début
     * @param string $group application|key|user|model|day
     * @return array {since, until, group, data: [...], quotas: [...]}
     * @throws lb_exception
     */
    public static function usage($since, $group = 'model') {
        $query = '?group=' . rawurlencode((string)$group)
            . '&since=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', (int)$since));
        return self::request('GET', '/v1/usage' . $query, null, self::headers(), 10);
    }

    /**
     * Santé du service (sans authentification).
     *
     * @return array {ok: bool, release: string, error: string}
     */
    public static function health() {
        $curl = self::new_curl();
        $curl->setopt(array(
            'CURLOPT_CONNECTTIMEOUT' => 3,
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_RETURNTRANSFER' => true,
        ));
        $raw  = $curl->get(self::base_url() . '/health/ready');
        $info = is_array($curl->info) ? $curl->info : array();
        $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        if ($curl->get_errno()) {
            return array('ok' => false, 'release' => '',
                'error' => 'curl (' . $curl->get_errno() . ') ' . $curl->error);
        }
        $data = json_decode((string)$raw, true);
        return array(
            'ok'      => ($code === 200),
            'release' => (is_array($data) && isset($data['release'])) ? (string)$data['release'] : '',
            'error'   => ($code === 200) ? '' : 'HTTP ' . $code,
        );
    }

    // =====================================================================
    //  ERREURS
    // =====================================================================

    /**
     * Exception correspondant à une réponse d'erreur du load balancer
     * ({"error":{"code","message","details"}}).
     *
     * @param int        $httpstatus
     * @param mixed      $data corps décodé (ou null)
     * @param string     $raw  corps brut (diagnostic)
     * @return lb_exception
     */
    public static function error_from_response($httpstatus, $data, $raw = '') {
        $httpstatus = (int)$httpstatus;
        $code    = '';
        $message = '';
        $details = array();
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $code    = isset($data['error']['code']) ? (string)$data['error']['code'] : '';
            $message = isset($data['error']['message']) ? (string)$data['error']['message'] : '';
            $details = (isset($data['error']['details']) && is_array($data['error']['details']))
                ? $data['error']['details'] : array();
        }
        if ($message === '') {
            $message = trim(substr((string)$raw, 0, 300));
        }

        $retryafter = 0;
        if (!empty($details['retry_at'])) {
            $at = strtotime((string)$details['retry_at']);
            if ($at !== false) {
                $retryafter = max(0, $at - time());
            }
        }
        // Se résout en attendant : quota, file saturée, service indisponible,
        // génération synchrone échouée ou expirée.
        $transient = ($httpstatus === 0 || $httpstatus === 408 || $httpstatus === 429
            || $httpstatus >= 500);
        return new lb_exception($httpstatus, $code, $message, $transient, $retryafter,
            empty($details) ? '' : json_encode($details, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Requête JSON vers l'API applicative.
     *
     * @param string               $method  GET|POST
     * @param string               $path    chemin (avec /v1)
     * @param array|\stdClass|null $body
     * @param string[]             $headers
     * @param int                  $timeout
     * @return array corps décodé
     * @throws lb_exception
     */
    private static function request($method, $path, $body, array $headers, $timeout = self::TIMEOUT) {
        if (!self::configured()) {
            throw new lb_exception(0, 'not_configured',
                get_string('lb_notconfigured', 'local_aifeedback'), false);
        }
        $url  = self::base_url() . $path;
        $curl = self::new_curl();
        $curl->setopt(array(
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT'        => (int)$timeout,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER'     => $headers,
        ));
        if ($method === 'POST') {
            $raw = $curl->post($url, json_encode($body, JSON_UNESCAPED_UNICODE));
        } else {
            $raw = $curl->get($url);
        }

        if ($curl->get_errno()) {
            throw new lb_exception(0, 'unreachable',
                'curl (' . $curl->get_errno() . ') ' . $curl->error . ' [' . $url . ']', true);
        }
        $info = is_array($curl->info) ? $curl->info : array();
        $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $data = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) {
            throw self::error_from_response($code, $data, (string)$raw);
        }
        if (!is_array($data)) {
            throw new lb_exception($code, 'bad_response', 'Réponse illisible : '
                . substr((string)$raw, 0, 200), true);
        }
        return $data;
    }
}
