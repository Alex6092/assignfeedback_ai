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
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                'curl error: ' . $curl->error);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                'bad response: ' . substr((string)$raw, 0, 300));
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
            throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                'json parse error: ' . json_last_error_msg());
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
