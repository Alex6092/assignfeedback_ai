<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php'); // pour la classe curl

use local_aifeedback\lb\client;
use local_aifeedback\lb\lb_exception;

/**
 * Appel HTTP partagé vers un backend compatible OpenAI Chat Completions.
 *
 * Deux cibles possibles :
 *   - le LOAD BALANCER du lycée (cas général) : il met la demande en file et
 *     la confie à un serveur IA disponible. Le modèle est obligatoire (il
 *     désigne une entrée du catalogue du load balancer) ;
 *   - une API EXTERNE imposée par une activité (surcharge d'URL : OpenAI,
 *     Mistral…), appelée directement, hors load balancer.
 *
 * - Supporte le mode JSON Schema strict (response_format)
 * - Supporte les messages multimodaux (image_url) si le modèle est vision
 * - Supporte le STREAMING (SSE) via stream() pour les usages interactifs
 * - Les traitements différés passent par \local_aifeedback\jobs (jobs
 *   asynchrones du load balancer) ; build_request() et parse_completion()
 *   leur fournissent le corps de la demande et l'analyse de la réponse.
 */
class api {

    /**
     * Balises de réflexion des modèles à raisonnement, quand le serveur la
     * laisse dans le texte de la réponse : <think> (Qwen3, QwQ, DeepSeek-R1)
     * et [THINK] (Magistral).
     */
    const THINK_TAGS = array('<think>' => '</think>', '[THINK]' => '[/THINK]');

    /** Cibles possibles d'un appel. */
    const TARGET_LB     = 'lb';
    const TARGET_DIRECT = 'direct';

    /**
     * Effectue un appel chat/completions (bloquant).
     *
     * Vers le load balancer, l'appel passe par son mode synchrone : il attend
     * son tour dans la file (10 minutes au plus) puis la génération. Les
     * traitements de fond utilisent plutôt les jobs asynchrones (jobs::run).
     *
     * @param array $messages messages OpenAI (role + content string ou array multimodal)
     * @param array $options  Options de l'appel :
     *   - 'apiurl'        (string|null) URL d'une API externe : appel direct, hors load balancer
     *   - 'model'         (string) modèle (catalogue du load balancer, ou modèle de l'API externe)
     *   - 'apikey'        (string|null) clé de l'API externe (en clair)
     *   - 'userid'        (int) utilisateur pour qui l'appel est fait (quotas du load balancer)
     *   - 'response_format' (array|null) ex: ['type'=>'json_schema','json_schema'=>[...]]
     *   - 'temperature'   (float, défaut 0.2)
     *   - 'max_tokens'    (int, défaut 2048)
     *   - 'extra_body'    (array|null) champs additionnels (ex: enable_thinking=false)
     *   - 'timeout'       (int, défaut 180 ; 600 avec un budget de réflexion ; 900 via le load balancer)
     *
     * @return array Le tableau parsé depuis choices[0].message.content
     *               (en JSON si response_format=json_schema/json_object,
     *                ou ['__text__' => string] si réponse texte brute)
     * @throws \moodle_exception en cas d'erreur HTTP ou de parsing
     *         (lb_exception si le load balancer refuse la demande,
     *          server_unavailable_exception si le serveur est injoignable)
     */
    public static function call(array $messages, array $options = array()) {
        $req = self::build_request($messages, $options);

        // Un modèle qui réfléchit avant de répondre met bien plus longtemps ;
        // via le load balancer, s'y ajoute l'attente dans la file.
        $timeout = (self::reasoning_budget() > 0) ? 600 : 180;
        if ($req['target'] === self::TARGET_LB) {
            $timeout += 720;
        }

        $curl = self::new_curl($req);
        $curl->setopt(array(
            'CURLOPT_TIMEOUT'        => isset($options['timeout']) ? (int)$options['timeout'] : $timeout,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER'     => $req['headers'],
        ));

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
        $data     = json_decode($raw, true);

        if ($httpcode >= 400) {
            if ($req['target'] === self::TARGET_LB) {
                throw client::error_from_response($httpcode, $data, (string)$raw);
            }
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

        return self::parse_completion(is_array($data) ? $data : array(), $options,
            '[model=' . $req['model'] . ', url=' . $req['url'] . ']', (string)$raw);
    }

    /**
     * Analyse une réponse Chat Completions (non diffusée) : celle d'un appel
     * synchrone, ou le « result » d'un job asynchrone du load balancer.
     *
     * @param array  $data    réponse OpenAI décodée
     * @param array  $options options de l'appel (seul response_format est lu)
     * @param string $where   contexte ajouté aux diagnostics (modèle, URL, job)
     * @param string $raw     corps brut, pour le diagnostic d'une réponse illisible
     * @return array JSON décodé, ou ['__text__' => string]
     * @throws \moodle_exception apicallfailed (réponse vide, tronquée, JSON invalide)
     */
    public static function parse_completion(array $data, array $options, $where = '', $raw = '') {
        $message = (isset($data['choices'][0]['message'])
                && is_array($data['choices'][0]['message'])) ? $data['choices'][0]['message'] : array();
        // Réflexion rendue à part (LM Studio, vLLM, Ollama récents) : ce n'est
        // pas la réponse, mais elle explique une réponse vide.
        $reasoning = self::reasoning_length($message);
        if (!isset($message['content']) && $reasoning === 0) {
            $detail = (isset($data['error']))
                ? self::format_api_error($data['error'])
                : 'bad response: ' . substr($raw !== '' ? (string)$raw : json_encode($data), 0, 500);
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                trim($detail . ' ' . $where));
        }

        $finish = isset($data['choices'][0]['finish_reason'])
            ? $data['choices'][0]['finish_reason'] : '?';

        // Réflexion laissée dans le texte (<think>…</think>) : retirée.
        list($content, $inline) = self::split_reasoning(isset($message['content']) ? $message['content'] : '');
        $reasoning += $inline;
        if ($content === '' && $reasoning > 0) {
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                trim(self::reasoning_only_detail($finish, $reasoning) . ' ' . $where));
        }

        // Retire les balises markdown si le modèle en ajoute.
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/i', '', $content);
        $content = trim($content);

        // Si on attendait du JSON, on parse. Sinon on retourne le texte brut wrappé.
        if (!self::expects_json($options)) {
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
                        .  'Augmentez le « Budget de réflexion » (local_aifeedback) '
                        .  'ou réduisez reasoning_effort';
            } else {
                $detail .= ', extrait=' . substr($content, 0, 200);
            }
            $detail .= ']';
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                trim($detail . ' ' . $where));
        }
        return $result;
    }

    /**
     * La réponse attendue est-elle du JSON (response_format json_object / json_schema) ?
     *
     * @param array $options
     * @return bool
     */
    public static function expects_json(array $options) {
        return !empty($options['response_format'])
            && isset($options['response_format']['type'])
            && in_array($options['response_format']['type'], array('json_object', 'json_schema'), true);
    }

    /**
     * Effectue un appel chat/completions en STREAMING (SSE).
     *
     * Chaque fragment de texte produit par le modèle est passé à $ondelta au fil
     * de l'eau : c'est ce qui permet au tuteur (local_aichat) d'afficher la
     * réponse pendant sa génération. Le callback peut retourner false pour
     * INTERROMPRE la génération (élève qui ferme l'onglet ou clique « Arrêter ») :
     * on rend alors 0 depuis le write callback de curl, ce qui coupe la connexion ;
     * le load balancer annule alors la demande et libère le serveur IA.
     *
     * Via le load balancer, la demande attend d'abord son tour dans la file :
     *   - l'identifiant du job arrive aussitôt (en-tête X-Job-ID) et est passé à
     *     $options['onjobid'] (suivi du rang, annulation) ;
     *   - pendant l'attente, le service envoie des commentaires SSE (« : queued »,
     *     « : heartbeat ») : chacun appelle $options['onwait'], qui peut lui aussi
     *     retourner false pour abandonner.
     *
     * Appel d'outils : si $options['tools'] est fourni (définitions au format
     * OpenAI), le modèle peut répondre par des appels d'outils au lieu (ou en
     * plus) du texte. Ils arrivent en morceaux dans le flux et sont rendus
     * reconstitués dans 'tool_calls' ; ils ne passent jamais par $ondelta.
     *
     * @param array    $messages messages OpenAI
     * @param array    $options  mêmes options que call() ('timeout' défaut 600,
     *                           1200 via le load balancer), plus 'tools',
     *                           'tool_choice', 'onjobid' et 'onwait' (facultatifs)
     * @param callable $ondelta  function(string $delta): bool|null — false = arrêter
     * @return array ['content' => string, 'finish_reason' => string|null,
     *                'usage' => array|null, 'aborted' => bool,
     *                'tool_calls' => array[] {id, name, arguments (JSON brut)},
     *                'jobid' => string (load balancer, '' sinon)]
     * @throws \moodle_exception en cas d'erreur réseau ou HTTP
     *         (lb_exception : quota, file saturée, génération échouée)
     */
    public static function stream(array $messages, array $options, callable $ondelta) {
        $req = self::build_request($messages, $options);
        $islb = ($req['target'] === self::TARGET_LB);

        $payload = $req['payload'];
        $payload['stream'] = true;
        // Décompte exact des tokens en fin de flux : le load balancer le
        // demande lui-même au serveur ; OpenAI l'accepte toujours. Les autres
        // API externes ne l'acceptent pas toutes (HTTP 400) : on s'abstient.
        if (!$islb && $req['flavor'] === 'openai') {
            $payload['stream_options'] = array('include_usage' => true);
        }

        $headers = $req['headers'];
        foreach ($headers as $i => $h) {
            if (stripos($h, 'Accept:') === 0) {
                $headers[$i] = 'Accept: text/event-stream';
            }
        }

        // État partagé avec le write callback.
        $state = self::new_stream_state((isset($options['onwait']) && is_callable($options['onwait']))
            ? $options['onwait'] : null);
        $onjobid = (isset($options['onjobid']) && is_callable($options['onjobid']))
            ? $options['onjobid'] : null;

        $curl = self::new_curl($req);
        $curl->setopt(array(
            'CURLOPT_TIMEOUT'         => isset($options['timeout']) ? (int)$options['timeout'] : ($islb ? 1200 : 600),
            'CURLOPT_CONNECTTIMEOUT'  => 15,
            'CURLOPT_RETURNTRANSFER'  => true,
            'CURLOPT_HTTPHEADER'      => $headers,
            // Détection de blocage : moins de 1 octet/s pendant 90 s → on coupe.
            // (Pendant l'attente, le load balancer envoie un battement toutes les 15 s.)
            'CURLOPT_LOW_SPEED_LIMIT' => 1,
            'CURLOPT_LOW_SPEED_TIME'  => 90,
            'CURLOPT_HEADERFUNCTION'  => function($ch, $line) use ($state, $onjobid) {
                if (stripos($line, 'x-job-id:') === 0) {
                    $state->jobid = trim(substr($line, 9));
                    if ($onjobid !== null && $state->jobid !== '') {
                        try {
                            $onjobid($state->jobid);
                        } catch (\Throwable $e) {
                            // Jamais d'exception depuis un callback curl.
                            debugging('local_aifeedback: onjobid — ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                }
                return strlen($line);
            },
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
                    if ($state->lberror === null) {
                        $state->aborted = true;
                    }
                    return 0; // coupe la connexion (curl renverra un errno)
                }
                return $len;
            },
        ));

        $curl->post($req['url'], json_encode($payload, JSON_UNESCAPED_UNICODE));

        // Erreur annoncée par le load balancer après ouverture du flux
        // (génération échouée, expirée, annulée) : le HTTP valait déjà 200.
        if ($state->lberror !== null) {
            $err = $state->lberror;
            throw new lb_exception(200,
                isset($err['code']) ? (string)$err['code'] : 'generation_failed',
                isset($err['message']) ? (string)$err['message'] : 'Génération interrompue.',
                false, 0, 'job=' . $state->jobid);
        }

        // Réponse très courte encore retenue (elle ressemblait au début d'une
        // balise de réflexion) : on la rend.
        if (!$state->aborted && $state->think === 'detect' && trim($state->pending) !== '') {
            $tail = ltrim($state->pending);
            $state->pending = '';
            $state->content .= $tail;
            $ondelta($tail);
        }

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
                'tool_calls'    => self::finish_tool_calls($state),
                'jobid'         => $state->jobid,
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
            $data = json_decode($state->errbody, true);
            if ($islb) {
                throw client::error_from_response($httpcode, $data, $state->errbody);
            }
            $detail = 'HTTP ' . $httpcode . ' — ';
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

        // Une réponse faite uniquement d'un appel d'outil n'a pas de texte :
        // elle n'est vide que si l'on n'a reçu ni l'un ni l'autre.
        $toolcalls = self::finish_tool_calls($state);
        if (trim($state->content) === '' && empty($toolcalls)) {
            $detail = ($state->reasoning > 0)
                ? self::reasoning_only_detail($state->finish, $state->reasoning)
                : 'flux vide (aucun delta reçu)';
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                $detail . ' [model=' . $req['model'] . ', url=' . $req['url'] . ']');
        }

        // Réflexion passée malgré tout dans le texte (balise fermante seule :
        // la balise ouvrante était dans le prompt). Déjà affichée pendant le
        // flux, mais retirée de la réponse enregistrée.
        list($clean, $removed) = self::split_reasoning($state->content);
        if ($removed > 0 && $clean !== '') {
            $state->content = $clean;
        }

        return array(
            'content'       => $state->content,
            'finish_reason' => $state->finish,
            'usage'         => $state->usage,
            'aborted'       => false,
            'tool_calls'    => $toolcalls,
            'jobid'         => $state->jobid,
        );
    }

    /**
     * État de lecture d'un flux SSE, partagé avec le write callback de curl.
     *
     * @param callable|null $onwait appelé à chaque commentaire SSE (attente)
     * @return \stdClass
     */
    public static function new_stream_state($onwait = null) {
        return (object)array(
            'buffer'   => '',   // fragment de ligne SSE incomplet
            'content'  => '',   // texte complet accumulé
            'finish'   => null,
            'usage'    => null,
            'aborted'  => false,
            'errbody'  => '',   // corps de réponse quand le HTTP n'est pas 200
            'httpcode' => 0,
            'tools'    => array(), // appels d'outils en cours de reconstitution
            'think'    => 'detect', // detect | inside | outside (voir filter_think)
            'thinkend' => '',   // balise fermante attendue dans l'état « inside »
            'pending'  => '',   // texte retenu le temps de reconnaître une balise
            'reasoning' => 0,   // caractères de réflexion reçus (jamais montrés)
            'jobid'    => '',   // X-Job-ID (load balancer)
            'lberror'  => null, // événement d'erreur reçu après ouverture du flux
            'onwait'   => $onwait,
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
    public static function feed_sse(\stdClass $state, $data, callable $ondelta) {
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
     * et un « data: [DONE] » final. Les commentaires (« : queued »,
     * « : heartbeat » du load balancer pendant l'attente) appellent
     * $state->onwait. Un « data: {"error":…} » (erreur survenue après
     * l'ouverture du flux) est mémorisé dans $state->lberror et arrête la
     * lecture. Le reste (champs event:/id:) est ignoré.
     *
     * @param string    $line
     * @param \stdClass $state
     * @param callable  $ondelta
     * @return bool false = interrompre
     */
    private static function consume_sse_line($line, \stdClass $state, callable $ondelta) {
        if ($line !== '' && $line[0] === ':') {
            if (!empty($state->onwait)) {
                return (call_user_func($state->onwait, trim(substr($line, 1))) !== false);
            }
            return true;
        }
        if (strpos($line, 'data:') !== 0) {
            return true; // ligne vide, ou champ non géré
        }
        $json = trim(substr($line, 5));
        if ($json === '' || $json === '[DONE]') {
            return true;
        }
        $chunk = json_decode($json, true);
        if (!is_array($chunk)) {
            return true; // fragment illisible : on l'ignore plutôt que de tout casser
        }
        if (isset($chunk['error']) && !isset($chunk['choices'])) {
            $state->lberror = is_array($chunk['error'])
                ? $chunk['error'] : array('message' => (string)$chunk['error']);
            return false;
        }
        // Le chunk final (include_usage) porte l'usage et un tableau choices vide.
        if (isset($chunk['usage']) && is_array($chunk['usage'])) {
            $state->usage = $chunk['usage'];
        }
        if (isset($chunk['choices'][0]['finish_reason'])
                && $chunk['choices'][0]['finish_reason'] !== null) {
            $state->finish = (string)$chunk['choices'][0]['finish_reason'];
        }
        // Appel d'outil : jamais montré à l'utilisateur, seulement accumulé.
        if (isset($chunk['choices'][0]['delta']['tool_calls'])
                && is_array($chunk['choices'][0]['delta']['tool_calls'])) {
            self::accumulate_tool_calls($state, $chunk['choices'][0]['delta']['tool_calls']);
        }
        // Réflexion rendue à part : comptée, jamais montrée.
        if (isset($chunk['choices'][0]['delta']) && is_array($chunk['choices'][0]['delta'])) {
            $state->reasoning += self::reasoning_length($chunk['choices'][0]['delta']);
        }
        $delta = null;
        if (isset($chunk['choices'][0]['delta']['content'])) {
            $delta = (string)$chunk['choices'][0]['delta']['content'];
        }
        if ($delta === null || $delta === '') {
            return true;
        }
        $delta = self::filter_think($state, $delta);
        if ($delta === '') {
            return true;
        }
        $state->content .= $delta;
        return ($ondelta($delta) !== false);
    }

    /**
     * Retire du flux la réflexion laissée dans le texte : un bloc
     * <think>…</think> en tête de réponse n'est jamais transmis à $ondelta.
     *
     * États : « detect » (début de réponse, on regarde si elle ouvre une
     * balise), « inside » (dans la réflexion, jusqu'à la balise fermante),
     * « outside » (la réponse : tout passe). Les balises peuvent arriver
     * coupées entre deux fragments : le texte ambigu attend dans
     * $state->pending.
     *
     * @param \stdClass $state
     * @param string    $delta
     * @return string texte à transmettre (éventuellement vide)
     */
    private static function filter_think(\stdClass $state, $delta) {
        if ($state->think === 'outside') {
            return $delta;
        }
        $state->pending .= $delta;

        if ($state->think === 'detect') {
            $head = ltrim($state->pending);
            if ($head === '') {
                return '';
            }
            foreach (self::THINK_TAGS as $open => $close) {
                if (strpos($head, $open) === 0) {
                    $state->think    = 'inside';
                    $state->thinkend = $close;
                    $state->pending  = substr($head, strlen($open));
                    return self::filter_think($state, '');
                }
                if (strpos($open, $head) === 0) {
                    return ''; // début possible d'une balise : on attend la suite
                }
            }
            $state->think   = 'outside';
            $state->pending = '';
            return $head;
        }

        // « inside » : on cherche la fin de la réflexion.
        $end = strpos($state->pending, $state->thinkend);
        if ($end === false) {
            // On ne garde que de quoi reconnaître une balise coupée en deux.
            $keep = strlen($state->thinkend) - 1;
            if (strlen($state->pending) > $keep) {
                $state->reasoning += strlen($state->pending) - $keep;
                $state->pending = substr($state->pending, -$keep);
            }
            return '';
        }
        $state->reasoning += $end;
        $rest = substr($state->pending, $end + strlen($state->thinkend));
        // La réponse commence après la balise ; ses retours ligne de tête sont
        // retirés par l'état « detect ».
        $state->think   = 'detect';
        $state->pending = '';
        return ($rest === '') ? '' : self::filter_think($state, $rest);
    }

    /**
     * Sépare la réflexion d'un modèle à raisonnement de sa réponse, quand le
     * serveur la laisse dans le texte (LM Studio sans l'option « séparer
     * reasoning_content », llama.cpp, anciens Ollama…) :
     *   - bloc <think>…</think> : retiré ;
     *   - balise ouvrante jamais refermée (budget épuisé) : tout ce qui suit ;
     *   - balise fermante seule (le gabarit du modèle ouvre la balise dans le
     *     prompt) : tout ce qui précède.
     *
     * @param string $text
     * @return array [string réponse (trim), int caractères de réflexion retirés]
     */
    public static function split_reasoning($text) {
        $text    = (string)$text;
        $removed = 0;
        foreach (self::THINK_TAGS as $open => $close) {
            while (($start = strpos($text, $open)) !== false) {
                $end = strpos($text, $close, $start + strlen($open));
                if ($end === false) {
                    $removed += strlen($text) - $start;
                    $text = substr($text, 0, $start);
                    break;
                }
                $removed += $end + strlen($close) - $start;
                $text = substr($text, 0, $start) . substr($text, $end + strlen($close));
            }
            $end = strrpos($text, $close);
            if ($end !== false) {
                $removed += $end + strlen($close);
                $text = substr($text, $end + strlen($close));
            }
        }
        return array(trim($text), $removed);
    }

    /**
     * Longueur de la réflexion rendue à part dans un message ou un fragment
     * de flux : « reasoning_content » (vLLM, DeepSeek, LM Studio) ou
     * « reasoning » (LM Studio récent, Ollama, OpenRouter).
     *
     * @param array $message choices[0].message ou choices[0].delta
     * @return int caractères
     */
    private static function reasoning_length(array $message) {
        $len = 0;
        foreach (array('reasoning_content', 'reasoning') as $key) {
            if (isset($message[$key]) && is_string($message[$key])) {
                $len += strlen($message[$key]);
            }
        }
        return $len;
    }

    /**
     * Diagnostic d'une réponse faite uniquement de réflexion.
     *
     * @param string|null $finish    finish_reason
     * @param int         $reasoning caractères de réflexion reçus
     * @return string
     */
    private static function reasoning_only_detail($finish, $reasoning) {
        $detail = 'le modèle a réfléchi (' . (int)$reasoning . ' caractères) sans écrire de réponse'
            . ' [finish_reason=' . ($finish === null ? '?' : $finish) . ']';
        if ($finish === 'length') {
            $detail .= ' → la réflexion a épuisé max_tokens (' . self::reasoning_budget()
                . ' tokens de budget de réflexion actuellement) : augmentez le « Budget de réflexion » '
                . 'dans les réglages de local_aifeedback, ou désactivez le raisonnement du modèle';
        }
        return $detail;
    }

    /**
     * Tokens ajoutés à max_tokens de chaque appel pour laisser un modèle à
     * raisonnement réfléchir avant d'écrire sa réponse (réglage, 0 = aucun).
     *
     * @return int
     */
    private static function reasoning_budget() {
        return max(0, (int)get_config('local_aifeedback', 'reasoning_budget'));
    }

    /**
     * Ajoute un morceau d'appels d'outils au flux en cours de reconstitution.
     *
     * Format OpenAI : chaque morceau porte un « index » ; le premier donne l'id
     * et le nom de la fonction, les suivants des bouts de la chaîne JSON des
     * arguments. Les serveurs locaux s'en écartent parfois : appel complet en
     * un seul morceau, index absent, arguments déjà décodés en objet, objet
     * unique au lieu d'une liste. Tous ces cas sont acceptés.
     *
     * @param \stdClass $state
     * @param array     $calls contenu de delta.tool_calls
     */
    private static function accumulate_tool_calls(\stdClass $state, array $calls) {
        if (isset($calls['function']) || isset($calls['id']) || isset($calls['index'])) {
            $calls = array($calls); // objet unique au lieu d'une liste
        }
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $id   = (isset($call['id']) && is_string($call['id'])) ? $call['id'] : '';
            $fn   = (isset($call['function']) && is_array($call['function'])) ? $call['function'] : array();
            $name = (isset($fn['name']) && is_string($fn['name'])) ? $fn['name'] : '';

            if (isset($call['index']) && is_numeric($call['index'])) {
                $index = (int)$call['index'];
            } else {
                // Sans index : un id déjà vu désigne son appel ; un nouvel id,
                // ou un nom alors que le dernier appel est complet (nom +
                // arguments JSON entiers), en ouvre un nouveau ; sinon c'est
                // la suite du dernier (certains serveurs répètent le nom à
                // chaque morceau).
                $index = null;
                if ($id !== '') {
                    foreach ($state->tools as $i => $known) {
                        if ($known['id'] === $id) {
                            $index = $i;
                            break;
                        }
                    }
                }
                if ($index === null) {
                    $last  = empty($state->tools) ? null : max(array_keys($state->tools));
                    $opens = ($id !== '');
                    if (!$opens && $name !== '' && $last !== null && $state->tools[$last]['name'] !== '') {
                        $args  = $state->tools[$last]['arguments'];
                        $opens = ($args !== '' && json_decode($args) !== null);
                    }
                    $index = ($last === null) ? 0 : ($opens ? $last + 1 : $last);
                }
            }
            if (!isset($state->tools[$index])) {
                $state->tools[$index] = array('id' => '', 'name' => '', 'arguments' => '');
            }
            $entry = &$state->tools[$index];

            if ($id !== '' && $entry['id'] === '') {
                $entry['id'] = $id;
            }
            if ($name !== '' && $name !== $entry['name']) {
                // Le nom arrive normalement d'un bloc ; s'il est découpé, on le recolle.
                $entry['name'] = ($entry['name'] === '') ? $name : $entry['name'] . $name;
            }
            if (isset($fn['arguments'])) {
                if (is_string($fn['arguments'])) {
                    $entry['arguments'] .= $fn['arguments'];
                } else if (is_array($fn['arguments'])) {
                    $entry['arguments'] = json_encode($fn['arguments'], JSON_UNESCAPED_UNICODE);
                }
            }
            unset($entry);
        }
    }

    /**
     * Appels d'outils reconstitués, dans l'ordre, prêts à être exécutés puis
     * renvoyés au modèle (un id est inventé si le serveur n'en a pas fourni :
     * le message « tool » doit y faire référence).
     *
     * @param \stdClass $state
     * @return array[] {id, name, arguments}
     */
    private static function finish_tool_calls(\stdClass $state) {
        if (empty($state->tools)) {
            return array();
        }
        ksort($state->tools);
        $out = array();
        foreach ($state->tools as $index => $call) {
            $out[] = array(
                'id'        => ($call['id'] !== '') ? $call['id'] : 'call_' . $index . '_' . substr(md5(uniqid('', true)), 0, 8),
                'name'      => trim($call['name']),
                'arguments' => ($call['arguments'] !== '') ? $call['arguments'] : '{}',
            );
        }
        return $out;
    }

    /**
     * Classe un échec réseau ou HTTP : le serveur est-il en cause (inutile
     * d'insister tout de suite, la demande sera rejouée plus tard) ou la
     * requête (inutile de la rejouer telle quelle) ?
     *
     *   - UNREACHABLE : DNS (6), connexion refusée (7), délai dépassé AVANT
     *     d'avoir pu se connecter (28 avec connect_time nul) → serveur en panne.
     *   - SERVER_ERROR : serveur joint mais défaillant — HTTP 5xx, réponse vide
     *     (52), réception interrompue (56), transfert incomplet (18).
     *   - null : le reste, notamment un délai dépassé APRÈS connexion (le
     *     serveur fonctionne mais la génération est trop longue), les HTTP 4xx
     *     (requête refusée) et les réponses illisibles.
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
     * Instance curl adaptée à la cible : l'URL du load balancer est fixée par
     * l'administrateur (réseau interne autorisé) ; une URL d'API externe,
     * saisie au niveau d'une activité, reste soumise à la liste des hôtes
     * bloqués de Moodle.
     */
    private static function new_curl(array $req) {
        return ($req['target'] === self::TARGET_LB) ? client::new_curl() : new \curl();
    }

    /**
     * Prépare la cible, les en-têtes et le corps d'un appel (partie commune
     * à call(), stream() et aux jobs asynchrones).
     *
     * Cible : l'API externe si $options['apiurl'] est renseignée (surcharge
     * d'une activité), le load balancer sinon.
     *
     * @return array ['target'=>'lb'|'direct', 'url'=>string, 'model'=>string,
     *                'headers'=>array, 'payload'=>array, 'flavor'=>string]
     * @throws \moodle_exception load balancer non configuré, modèle absent
     */
    public static function build_request(array $messages, array $options) {
        $direct = isset($options['apiurl']) && trim((string)$options['apiurl']) !== '';

        if ($direct) {
            $target = self::TARGET_DIRECT;
            $url    = trim((string)$options['apiurl']);
            $model  = isset($options['model']) ? trim((string)$options['model']) : '';
            if ($model === '') {
                $model = models::feedback();
            }
            $apikey = (isset($options['apikey']) && $options['apikey'] !== null) ? (string)$options['apikey'] : '';
        } else {
            if (!client::configured()) {
                throw new \moodle_exception('lb_notconfigured', 'local_aifeedback');
            }
            $target = self::TARGET_LB;
            $url    = client::chat_url();
            $model  = isset($options['model']) ? trim((string)$options['model']) : '';
            if ($model === '') {
                $model = models::feedback();
            }
            if ($model === '') {
                throw new \moodle_exception('lb_nomodel', 'local_aifeedback');
            }
            $apikey = client::apikey();
        }

        // max_tokens est calibré pour la RÉPONSE ; un modèle à raisonnement
        // décompte sa réflexion du même budget, d'où le supplément réglable.
        $maxtokens = (isset($options['max_tokens']) ? (int)$options['max_tokens'] : 2048)
            + self::reasoning_budget();
        if ($target === self::TARGET_LB) {
            // Le load balancer refuse une sortie supérieure à celle déclarée
            // pour le modèle : on plafonne quand le catalogue la connaît.
            $info = client::model($model);
            if ($info !== null && (int)$info['max_output_tokens'] > 0) {
                $maxtokens = min($maxtokens, (int)$info['max_output_tokens']);
            }
        }
        $payload = array(
            'model'       => $model,
            'temperature' => isset($options['temperature']) ? (float)$options['temperature'] : 0.2,
            'max_tokens'  => $maxtokens,
            'messages'    => $messages,
        );
        if (!empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }
        // Outils proposés au modèle (appel de fonctions). Absents = payload
        // strictement identique à celui d'avant cette fonctionnalité.
        if (!empty($options['tools']) && is_array($options['tools'])) {
            $payload['tools'] = $options['tools'];
            if (isset($options['tool_choice'])) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
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
        //  Le load balancer relaie les champs tels quels à des serveurs
        //  « generic » (LM Studio, vLLM, llama.cpp).
        // -------------------------------------------------------------
        $flavor = isset($options['apiflavor']) ? (string)$options['apiflavor'] : 'auto';
        if ($flavor === 'auto') {
            $flavor = ($target === self::TARGET_DIRECT && stripos($url, 'openai.com') !== false)
                ? 'openai' : 'generic';
        }
        if ($flavor !== 'openai' && !empty($options['extra_body']) && is_array($options['extra_body'])) {
            // extra_body est une notion du SDK Python, qui en fusionne les
            // champs à la racine de la requête : envoyé tel quel, aucun serveur
            // ne le lit (enable_thinking=false restait sans effet). On fait
            // comme le SDK, sans écraser un champ déjà posé.
            foreach ($options['extra_body'] as $key => $value) {
                if (!array_key_exists($key, $payload)) {
                    $payload[$key] = $value;
                }
            }
            // vLLM, SGLang et llama.cpp passent le réglage au gabarit du modèle
            // par chat_template_kwargs.
            if (isset($options['extra_body']['enable_thinking']) && !isset($payload['chat_template_kwargs'])) {
                $payload['chat_template_kwargs'] = array(
                    'enable_thinking' => (bool)$options['extra_body']['enable_thinking'],
                );
            }
        }
        if ($flavor === 'openai') {
            // 1. extra_body inexistant côté OpenAI : il n'est pas transmis.
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
        if ($target === self::TARGET_LB) {
            // Identifiant opaque de l'utilisateur : quotas et statistiques.
            $headers[] = 'X-End-User-ID: ' . client::end_user_id(isset($options['userid']) ? (int)$options['userid'] : 0);
        }

        return array(
            'target'  => $target,
            'url'     => $url,
            'model'   => $model,
            'headers' => $headers,
            'payload' => $payload,
            'flavor'  => $flavor,
        );
    }
}
