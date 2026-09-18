<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php'); // pour la classe curl

/**
 * Appel HTTP partagé vers un backend compatible OpenAI Chat Completions
 * (LM Studio, vLLM, Ollama, OpenAI, etc.).
 *
 * - Supporte le mode JSON Schema strict (response_format)
 * - Supporte les messages multimodaux (image_url) si le modèle est vision
 * - Gère l'authentification optionnelle via Bearer token
 * - Accepte des overrides ponctuels (URL/modèle/apikey) sans toucher la config
 * - Supporte le STREAMING (SSE) via stream() pour les usages interactifs
 */
class api {

    /**
     * Effectue un appel chat/completions (bloquant).
     *
     * @param array $messages messages OpenAI (role + content string ou array multimodal)
     * @param array $options  Options de l'appel :
     *   - 'apiurl'        (string|null) override de l'URL
     *   - 'model'         (string|null) override du modèle
     *   - 'apikey'        (string|null) override de la clé (en clair)
     *   - 'response_format' (array|null) ex: ['type'=>'json_schema','json_schema'=>[...]]
     *   - 'temperature'   (float, défaut 0.2)
     *   - 'max_tokens'    (int, défaut 2048)
     *   - 'extra_body'    (array|null) champs additionnels (ex: enable_thinking=false)
     *   - 'timeout'       (int, défaut 180)
     *
     * @return array Le tableau parsé depuis choices[0].message.content
     *               (en JSON si response_format=json_schema/json_object,
     *                ou ['__text__' => string] si réponse texte brute)
     * @throws \moodle_exception en cas d'erreur HTTP ou de parsing
     *         (server_unavailable_exception si le serveur est en cause et
     *         qu'aucun autre serveur du pool n'a pu prendre le relais)
     */
    public static function call(array $messages, array $options = array()) {
        while (true) {
            try {
                return self::call_once($messages, $options);
            } catch (server_unavailable_exception $e) {
                // Le pool bascule le processus sur un autre serveur : on rejoue
                // l'appel, qui prendra ce nouveau serveur dans prepare().
                if (!self::can_failover($options) || !pool::failover($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Un seul essai de call(), sur le serveur désigné par prepare().
     */
    private static function call_once(array $messages, array $options) {
        $req = self::prepare($messages, $options);

        $curl = new \curl();
        $curl->setopt(array(
            'CURLOPT_TIMEOUT'        => isset($options['timeout']) ? (int)$options['timeout'] : 180,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER'     => $req['headers'],
        ) + self::heartbeat_options());

        $raw = $curl->post($req['url'], json_encode($req['payload'], JSON_UNESCAPED_UNICODE));

        $info = is_array($curl->info) ? $curl->info : array();
        if ($curl->get_errno()) {
            // Erreur réseau / TLS / DNS / timeout — typiquement backend injoignable.
            $detail = 'curl error (' . $curl->get_errno() . '): ' . $curl->error
                . ' [url=' . $req['url'] . ']';
            $kind = self::classify($curl->get_errno(), $info, 0);
            if ($kind !== null) {
                throw new server_unavailable_exception($kind, $detail);
            }
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null, $detail);
        }

        // Code HTTP de la réponse (présent dans curl->info après la requête).
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            // Réponse inexploitable : on remonte le code HTTP + le corps brut.
            // Les API compatibles OpenAI renvoient en cas d'erreur un objet
            // {"error":{"message":"...","type":"...","code":"..."}} : ce message
            // est la clé pour diagnostiquer (modèle inconnu, paramètre non
            // supporté, clé invalide, quota dépassé, etc.).
            $detail = 'HTTP ' . $httpcode . ' — ';
            if (is_array($data) && isset($data['error'])) {
                $detail .= self::format_api_error($data['error']);
            } else {
                $detail .= 'bad response: ' . substr((string)$raw, 0, 500);
            }
            $detail .= ' [model=' . $req['model'] . ', url=' . $req['url'] . ']';
            $kind = self::classify(0, $info, $httpcode);
            if ($kind !== null) {
                throw new server_unavailable_exception($kind, $detail);
            }
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null, $detail);
        }

        $content = trim((string)$data['choices'][0]['message']['content']);
        // Retire les balises markdown si le modèle en ajoute.
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/i', '', $content);
        $content = trim($content);

        // Si on attendait du JSON, on parse. Sinon on retourne le texte brut wrappé.
        $expectjson = !empty($options['response_format'])
                       && isset($options['response_format']['type'])
                       && in_array($options['response_format']['type'],
                                   array('json_object', 'json_schema'));

        if (!$expectjson) {
            return array('__text__' => $content);
        }

        // Cherche le premier { au cas où le modèle ajoute du texte avant.
        $start = strpos($content, '{');
        if ($start !== false && $start > 0) {
            $content = substr($content, $start);
        }

        $result = json_decode($content, true);
        if (!is_array($result)) {
            // Diagnostic enrichi : la cause la plus fréquente avec les modèles
            // « raisonneurs » (gpt-5, o1…) est un `content` VIDE ou TRONQUÉ
            // parce que les reasoning tokens ont épuisé max_completion_tokens
            // (finish_reason='length'). On remonte donc finish_reason, la
            // longueur du contenu, l'usage des tokens et un extrait.
            $finish = isset($data['choices'][0]['finish_reason'])
                ? $data['choices'][0]['finish_reason'] : '?';
            $usage = '';
            if (isset($data['usage']) && is_array($data['usage'])) {
                $u = $data['usage'];
                $usage = ' tokens(prompt=' . (isset($u['prompt_tokens']) ? $u['prompt_tokens'] : '?')
                    . ', completion=' . (isset($u['completion_tokens']) ? $u['completion_tokens'] : '?');
                if (isset($u['completion_tokens_details']['reasoning_tokens'])) {
                    $usage .= ', reasoning=' . $u['completion_tokens_details']['reasoning_tokens'];
                }
                $usage .= ')';
            }
            $detail = 'json parse error: ' . json_last_error_msg()
                . ' [finish_reason=' . $finish
                . ', content_len=' . strlen($content) . $usage;
            if ($content === '') {
                $detail .= ', contenu VIDE → probablement max_tokens trop bas '
                        .  'pour un modèle raisonneur (reasoning tokens). '
                        .  'Augmentez max_tokens ou réduisez reasoning_effort';
            } else {
                $detail .= ', extrait=' . substr($content, 0, 200);
            }
            $detail .= ']';
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null, $detail);
        }
        return $result;
    }

    /**
     * Effectue un appel chat/completions en STREAMING (SSE).
     *
     * Chaque fragment de texte produit par le modèle est passé à $ondelta au fil
     * de l'eau : c'est ce qui permet au tuteur (local_aichat) d'afficher la
     * réponse pendant sa génération. Le callback peut retourner false pour
     * INTERROMPRE la génération (élève qui ferme l'onglet ou clique « Arrêter ») :
     * on rend alors 0 depuis le write callback de curl, ce qui coupe la connexion
     * et libère immédiatement le serveur LLM.
     *
     * @param array    $messages messages OpenAI
     * @param array    $options  mêmes options que call() ('timeout' défaut 600)
     * @param callable $ondelta  function(string $delta): bool|null — false = arrêter
     * @return array ['content' => string, 'finish_reason' => string|null,
     *                'usage' => array|null, 'aborted' => bool]
     * @throws \moodle_exception en cas d'erreur réseau ou HTTP
     */
    public static function stream(array $messages, array $options, callable $ondelta) {
        while (true) {
            // On ne peut basculer que tant que RIEN n'a été transmis à l'élève :
            // un texte déjà affiché ne peut pas être réécrit par un autre modèle.
            $received = false;
            $tracked  = function($delta) use ($ondelta, &$received) {
                $received = true;
                return $ondelta($delta);
            };
            try {
                return self::stream_once($messages, $options, $tracked);
            } catch (server_unavailable_exception $e) {
                if ($received || !self::can_failover($options) || !pool::failover($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Un seul essai de stream(), sur le serveur désigné par prepare().
     */
    private static function stream_once(array $messages, array $options, callable $ondelta) {
        $req = self::prepare($messages, $options);

        $payload = $req['payload'];
        $payload['stream'] = true;
        // Décompte exact des tokens en fin de flux. Tous les backends ne
        // l'acceptent pas (certains répondent HTTP 400) : activable par réglage
        // pour les serveurs génériques, toujours actif chez OpenAI.
        if ($req['flavor'] === 'openai' || !empty(get_config('local_aifeedback', 'stream_usage'))) {
            $payload['stream_options'] = array('include_usage' => true);
        }

        $headers = $req['headers'];
        foreach ($headers as $i => $h) {
            if (stripos($h, 'Accept:') === 0) {
                $headers[$i] = 'Accept: text/event-stream';
            }
        }

        // État partagé avec le write callback.
        $state = (object)array(
            'buffer'   => '',   // fragment de ligne SSE incomplet
            'content'  => '',   // texte complet accumulé
            'finish'   => null,
            'usage'    => null,
            'aborted'  => false,
            'errbody'  => '',   // corps de réponse quand le HTTP n'est pas 200
            'httpcode' => 0,
        );

        $curl = new \curl();
        $curl->setopt(array(
            'CURLOPT_TIMEOUT'         => isset($options['timeout']) ? (int)$options['timeout'] : 600,
            'CURLOPT_CONNECTTIMEOUT'  => 15,
            'CURLOPT_RETURNTRANSFER'  => true,
            'CURLOPT_HTTPHEADER'      => $headers,
            // Détection de blocage : moins de 1 octet/s pendant 90 s → on coupe.
            'CURLOPT_LOW_SPEED_LIMIT' => 1,
            'CURLOPT_LOW_SPEED_TIME'  => 90,
            'CURLOPT_WRITEFUNCTION'   => function($ch, $data) use ($state, $ondelta) {
                $len = strlen($data);
                if ($state->httpcode === 0) {
                    $state->httpcode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                }
                // Réponse d'erreur : ce n'est pas du SSE mais un corps JSON.
                if ($state->httpcode !== 0 && $state->httpcode !== 200) {
                    if (strlen($state->errbody) < 2000) {
                        $state->errbody .= $data;
                    }
                    return $len;
                }
                if (!self::feed_sse($state, $data, $ondelta)) {
                    $state->aborted = true;
                    return 0; // coupe la connexion (curl renverra un errno)
                }
                return $len;
            },
        ) + self::heartbeat_options());

        $curl->post($req['url'], json_encode($payload, JSON_UNESCAPED_UNICODE));

        $errno    = $curl->get_errno();
        $info     = is_array($curl->info) ? $curl->info : array();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : $state->httpcode;

        if ($state->aborted) {
            // Interruption volontaire : ce n'est pas une erreur.
            return array(
                'content'       => $state->content,
                'finish_reason' => 'aborted',
                'usage'         => $state->usage,
                'aborted'       => true,
            );
        }

        if ($errno) {
            $detail = 'curl error (' . $errno . '): ' . $curl->error . ' [url=' . $req['url'] . ']';
            $kind   = self::classify($errno, $info, 0);
            if ($kind !== null) {
                throw new server_unavailable_exception($kind, $detail);
            }
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null, $detail);
        }

        if ($httpcode !== 200) {
            $detail = 'HTTP ' . $httpcode . ' — ';
            $data   = json_decode($state->errbody, true);
            if (is_array($data) && isset($data['error'])) {
                $detail .= self::format_api_error($data['error']);
            } else {
                $detail .= 'bad response: ' . substr(trim($state->errbody), 0, 500);
            }
            $detail .= ' [model=' . $req['model'] . ', url=' . $req['url'] . ']';
            $kind = self::classify(0, $info, $httpcode);
            if ($kind !== null) {
                throw new server_unavailable_exception($kind, $detail);
            }
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null, $detail);
        }

        if (trim($state->content) === '') {
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                'flux vide (aucun delta reçu) [model=' . $req['model'] . ', url=' . $req['url'] . ']');
        }

        return array(
            'content'       => $state->content,
            'finish_reason' => $state->finish,
            'usage'         => $state->usage,
            'aborted'       => false,
        );
    }

    /**
     * Absorbe un fragment brut reçu du réseau et traite les lignes complètes
     * qu'il contient.
     *
     * Le découpage TCP ne respecte aucune frontière de ligne : un même « data: »
     * peut arriver en deux morceaux, et deux événements peuvent arriver
     * ensemble. Tout ce qui reste incomplet est conservé dans $state->buffer
     * jusqu'au fragment suivant.
     *
     * @param \stdClass $state
     * @param string    $data fragment brut
     * @param callable  $ondelta
     * @return bool false = interrompre la génération
     */
    private static function feed_sse(\stdClass $state, $data, callable $ondelta) {
        $state->buffer .= $data;
        while (($pos = strpos($state->buffer, "\n")) !== false) {
            $line = rtrim(substr($state->buffer, 0, $pos), "\r");
            $state->buffer = substr($state->buffer, $pos + 1);
            if (!self::consume_sse_line($line, $state, $ondelta)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Traite une ligne du flux SSE. Retourne false si l'appelant demande l'arrêt.
     *
     * Format : des lignes « data: {json} », une ligne vide entre les événements,
     * et un « data: [DONE] » final. Tout le reste (commentaires « : ping »,
     * champs event:/id:) est ignoré.
     *
     * @param string    $line
     * @param \stdClass $state
     * @param callable  $ondelta
     * @return bool false = interrompre
     */
    private static function consume_sse_line($line, \stdClass $state, callable $ondelta) {
        if (strpos($line, 'data:') !== 0) {
            return true; // ligne vide, commentaire, ou champ non géré
        }
        $json = trim(substr($line, 5));
        if ($json === '' || $json === '[DONE]') {
            return true;
        }
        $chunk = json_decode($json, true);
        if (!is_array($chunk)) {
            return true; // fragment illisible : on l'ignore plutôt que de tout casser
        }
        // Le chunk final (include_usage) porte l'usage et un tableau choices vide.
        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $state->usage = $chunk['usage'];
        }
        if (isset($chunk['choices'][0]['finish_reason'])
                && $chunk['choices'][0]['finish_reason'] !== null) {
            $state->finish = (string)$chunk['choices'][0]['finish_reason'];
        }
        $delta = null;
        if (isset($chunk['choices'][0]['delta']['content'])) {
            $delta = (string)$chunk['choices'][0]['delta']['content'];
        }
        if ($delta === null || $delta === '') {
            return true;
        }
        $state->content .= $delta;
        return ($ondelta($delta) !== false);
    }

    /**
     * Classe un échec : imputable au SERVEUR (on peut retenter ailleurs) ou à
     * la requête (inutile de retenter ailleurs).
     *
     *   - UNREACHABLE : DNS (6), connexion refusée (7), délai dépassé AVANT
     *     d'avoir pu se connecter (28 avec connect_time nul) → serveur en panne.
     *   - SERVER_ERROR : serveur joint mais défaillant — HTTP 5xx, réponse vide
     *     (52), réception interrompue (56), transfert incomplet (18).
     *   - null : le reste, notamment un délai dépassé APRÈS connexion (le
     *     serveur fonctionne mais la génération est trop longue : la rejouer
     *     ailleurs doublerait l'attente), les HTTP 4xx (requête refusée) et les
     *     réponses illisibles.
     *
     * @param int   $errno    code d'erreur curl (0 si aucun)
     * @param array $info     curl_getinfo() de la requête
     * @param int   $httpcode code HTTP (0 si non pertinent)
     * @return string|null server_unavailable_exception::* ou null
     */
    public static function classify($errno, array $info, $httpcode) {
        $errno = (int)$errno;
        if ($errno === 6 || $errno === 7) {
            return server_unavailable_exception::UNREACHABLE;
        }
        if ($errno === 28) {
            $connected = isset($info['connect_time']) && (float)$info['connect_time'] > 0;
            return $connected ? null : server_unavailable_exception::UNREACHABLE;
        }
        if ($errno === 18 || $errno === 52 || $errno === 56) {
            return server_unavailable_exception::SERVER_ERROR;
        }
        if ($errno === 0 && (int)$httpcode >= 500 && (int)$httpcode <= 599) {
            return server_unavailable_exception::SERVER_ERROR;
        }
        return null;
    }

    /**
     * Basculement autorisé pour cet appel ? Seulement quand le serveur vient du
     * pool (un ticket est tenu) et que l'appelant n'a pas imposé sa propre URL :
     * une activité configurée sur une API externe ne doit JAMAIS être envoyée à
     * un modèle local (tous les élèves d'un devoir sont corrigés par le même).
     */
    private static function can_failover(array $options) {
        if (pool::current_slot() <= 0) {
            return false;
        }
        return !isset($options['apiurl']) || $options['apiurl'] === null || $options['apiurl'] === '';
    }

    /**
     * Options curl qui entretiennent le battement de cœur du ticket courant
     * pendant l'appel. libcurl invoque la fonction de progression environ une
     * fois par seconde, y compris pendant que le serveur calcule sans rien
     * envoyer (lecture du prompt, images). Sans ticket, aucun surcoût.
     */
    private static function heartbeat_options() {
        if (pool::current_slot() <= 0) {
            return array();
        }
        return array(
            'CURLOPT_NOPROGRESS'       => false,
            'CURLOPT_PROGRESSFUNCTION' => function() {
                pool::heartbeat_current();
                return 0; // 0 = continuer le transfert
            },
        );
    }

    /**
     * Met en forme l'objet d'erreur standard des API compatibles OpenAI.
     */
    private static function format_api_error($err) {
        if (!is_array($err)) {
            return 'API error: ' . (string)$err;
        }
        return 'API error: '
            . (isset($err['message']) ? $err['message'] : json_encode($err))
            . (isset($err['type']) ? ' (type=' . $err['type'] . ')' : '')
            . (isset($err['code']) && $err['code'] !== null ? ' (code=' . $err['code'] . ')' : '');
    }

    /**
     * Prépare l'URL, les en-têtes et le payload d'un appel (partie commune à
     * call() et stream()).
     *
     * @return array ['url'=>string, 'model'=>string, 'headers'=>array,
     *                'payload'=>array, 'flavor'=>string]
     */
    private static function prepare(array $messages, array $options) {
        // Serveur tenu par ce processus (pool::set_current() — file de jobs,
        // tuteur, appels synchrones), quand l'appelant n'a rien surchargé.
        $server = pool::current();
        if ($server !== null) {
            foreach (array('apiurl', 'model', 'apikey') as $key) {
                if (!isset($options[$key]) || $options[$key] === null || $options[$key] === '') {
                    if (isset($server[$key]) && $server[$key] !== '') {
                        $options[$key] = $server[$key];
                    }
                }
            }
        }

        $url   = self::resolve($options, 'apiurl',
            (string)get_config('local_aifeedback', 'apiurl'),
            'http://localhost:1234/v1/chat/completions');
        $model = self::resolve($options, 'model',
            (string)get_config('local_aifeedback', 'model'),
            'qwen/qwen3-8b');

        // Clé API : override en clair > config chiffrée globale > vide.
        $apikey = '';
        if (isset($options['apikey']) && $options['apikey'] !== null && $options['apikey'] !== '') {
            $apikey = (string)$options['apikey'];
        } else {
            $apikey = secret::decrypt((string)get_config('local_aifeedback', 'apikey'));
        }

        $payload = array(
            'model'       => $model,
            'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : 0.2,
            'max_tokens'  => isset($options['max_tokens'])  ? (int)$options['max_tokens']    : 2048,
            'messages'    => $messages,
        );
        if (!empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }
        if (!empty($options['extra_body']) && is_array($options['extra_body'])) {
            $payload['extra_body'] = $options['extra_body'];
        }

        // -------------------------------------------------------------
        //  Adaptation au « dialecte » de l'API cible.
        //
        //  Beaucoup de backends se disent « compatibles OpenAI » mais
        //  divergent sur quelques champs. L'API REST officielle d'OpenAI,
        //  en particulier, diffère de LM Studio / vLLM sur 3 points qui
        //  provoquent des HTTP 400 :
        //    1. `extra_body` n'existe pas (c'est une notion du SDK Python).
        //    2. `max_tokens` est remplacé par `max_completion_tokens` et est
        //       carrément refusé par les modèles récents (gpt-5, o1, o3…).
        //    3. les modèles « raisonneurs » (gpt-5*, o1*, o3*, o4*) n'acceptent
        //       QUE la température par défaut (1) : tout autre valeur → 400.
        //
        //  $options['apiflavor'] : 'auto' (défaut), 'openai' ou 'generic'.
        //  En 'auto', on devine d'après l'URL (présence de « openai.com »).
        //  LM Studio et les autres backends restent en 'generic' → payload
        //  inchangé, aucune régression.
        // -------------------------------------------------------------
        $flavor = isset($options['apiflavor']) ? (string)$options['apiflavor'] : 'auto';
        if ($flavor === 'auto') {
            $flavor = (stripos($url, 'openai.com') !== false) ? 'openai' : 'generic';
        }
        if ($flavor === 'openai') {
            // 1. extra_body inexistant côté OpenAI.
            unset($payload['extra_body']);
            // 2. max_tokens → max_completion_tokens.
            if (isset($payload['max_tokens'])) {
                $payload['max_completion_tokens'] = (int)$payload['max_tokens'];
                unset($payload['max_tokens']);
            }
            // 3. température non personnalisable sur les modèles raisonneurs :
            //    on retire le paramètre pour laisser le défaut (1).
            if (preg_match('/^(gpt-5|o[1-9])/i', $model)) {
                unset($payload['temperature']);

                // 4. Modèles raisonneurs : les « reasoning tokens » sont
                //    décomptés DE max_completion_tokens. Avec un budget serré,
                //    le raisonnement consomme tout et le contenu revient vide
                //    ou tronqué (finish_reason='length') → JSON invalide.
                //    On limite donc l'effort de raisonnement (tâche de sortie
                //    structurée, peu de raisonnement nécessaire) ET on garantit
                //    un plancher de budget généreux pour laisser de la place à
                //    la réponse JSON elle-même.
                if (!isset($options['reasoning_effort'])) {
                    $payload['reasoning_effort'] = 'low';
                } else {
                    $payload['reasoning_effort'] = (string)$options['reasoning_effort'];
                }
                $floor = 8192;
                if (!isset($payload['max_completion_tokens'])
                        || (int)$payload['max_completion_tokens'] < $floor) {
                    $payload['max_completion_tokens'] = $floor;
                }
            }
        }

        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
        );
        if ($apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apikey;
        }

        return array(
            'url'     => $url,
            'model'   => $model,
            'headers' => $headers,
            'payload' => $payload,
            'flavor'  => $flavor,
        );
    }

    /**
     * Résout une valeur : override > global > défaut.
     */
    private static function resolve(array $options, $key, $globalvalue, $default) {
        if (isset($options[$key]) && $options[$key] !== null && $options[$key] !== '') {
            return (string)$options[$key];
        }
        if ($globalvalue !== '') {
            return $globalvalue;
        }
        return $default;
    }
}
