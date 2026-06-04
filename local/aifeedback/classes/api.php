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
 */
class api {

    /**
     * Effectue un appel chat/completions.
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
     */
    public static function call(array $messages, array $options = array()) {
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

        $curl = new \curl();
        $curl->setopt(array(
            'CURLOPT_TIMEOUT'        => isset($options['timeout']) ? (int)$options['timeout'] : 180,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER'     => $headers,
        ));

        $raw = $curl->post($url, json_encode($payload, JSON_UNESCAPED_UNICODE));

        if ($curl->get_errno()) {
            // Erreur réseau / TLS / DNS / timeout — typiquement backend injoignable.
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                'curl error (' . $curl->get_errno() . '): ' . $curl->error
                . ' [url=' . $url . ']');
        }

        // Code HTTP de la réponse (présent dans curl->info après la requête).
        $httpcode = isset($curl->info['http_code']) ? (int)$curl->info['http_code'] : 0;

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            // Réponse inexploitable : on remonte le code HTTP + le corps brut.
            // Les API compatibles OpenAI renvoient en cas d'erreur un objet
            // {"error":{"message":"...","type":"...","code":"..."}} : ce message
            // est la clé pour diagnostiquer (modèle inconnu, paramètre non
            // supporté, clé invalide, quota dépassé, etc.).
            $detail = 'HTTP ' . $httpcode . ' — ';
            if (is_array($data) && isset($data['error'])) {
                $err = $data['error'];
                $detail .= 'API error: '
                    . (isset($err['message']) ? $err['message'] : json_encode($err))
                    . (isset($err['type']) ? ' (type=' . $err['type'] . ')' : '')
                    . (isset($err['code']) && $err['code'] !== null ? ' (code=' . $err['code'] . ')' : '');
            } else {
                $detail .= 'bad response: ' . substr((string)$raw, 0, 500);
            }
            $detail .= ' [model=' . $model . ', url=' . $url . ']';
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
