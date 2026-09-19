<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

use local_aifeedback\api;
use local_aichat\tutor;

/**
 * Tests lancés depuis la page d'administration de la recherche Web.
 *
 * Le test d'appel d'outil vérifie, sur UN serveur LLM et avec SON modèle, ce
 * que la documentation ne garantit pas : que LM Studio reconnaît l'appel
 * d'outil du modèle en streaming (au lieu de le laisser passer en texte brut
 * à l'élève), et que le modèle sait ensuite répondre à partir d'un résultat.
 */
class diagnostic {

    /**
     * Marqueurs d'un appel d'outil qui n'a pas été reconnu par le serveur et
     * qui fuit dans le texte (formats Gemma, LM Studio par défaut, JSON brut).
     */
    const LEAK_MARKERS = array('<|tool_call', '<tool_call', 'tool_call|>', '[TOOL_REQUEST]',
        '[END_TOOL_REQUEST]', 'call:web_search', '"name": "web_search"', '"name":"web_search"',
        '```tool_code', '<start_function_call>', '<|tool_response');

    /** URL du résultat factice renvoyé au modèle. */
    const FAKE_URL = 'https://www.python.org/downloads/';

    /**
     * Aller-retour complet avec l'outil web_search sur un serveur, sans
     * appeler le fournisseur de recherche (résultat factice) : rien n'est
     * décompté du budget.
     *
     * Le test passe hors de la file du pool : à lancer de préférence quand
     * les élèves ne travaillent pas.
     *
     * @param array $server descripteur de \local_aifeedback\pool::servers()
     * @return array {checks: [{label, ok (bool|null = information), detail}],
     *                answer1, answer2, seconds}
     */
    public static function test_tool_calling(array $server) {
        $options = tutor::generation_options();
        $options['apiurl']  = $server['apiurl'];
        $options['apikey']  = $server['apikey'];
        $options['timeout'] = 180;
        if ($server['model'] !== '') {
            $options['model'] = $server['model'];
        }

        $messages = array(
            array('role' => 'system', 'content' => "Tu es un assistant de test. Date du jour : "
                . userdate(time(), get_string('strftimedaydate', 'langconfig')) . ".\n"
                . "Tu disposes d'un outil web_search. Pour toute question sur une version logicielle "
                . "récente, tu DOIS l'appeler avant de répondre."),
            array('role' => 'user', 'content' => "Quelle est la dernière version stable de Python ? "
                . "Vérifie en ligne avant de répondre."),
        );

        $out   = array('checks' => array(), 'answer1' => '', 'answer2' => '', 'seconds' => 0);
        $start = microtime(true);
        $quiet = function($delta) {
            return true;
        };

        // Tour 1 : l'outil est proposé, le modèle doit l'appeler.
        try {
            $r1 = api::stream($messages, $options + array('tools' => array(tool::definition())), $quiet);
        } catch (\Throwable $e) {
            $out['checks'][] = self::check('diag_step_request', false, self::error_text($e));
            $out['seconds']  = round(microtime(true) - $start, 1);
            return $out;
        }
        $out['answer1'] = \core_text::substr((string)$r1['content'], 0, 1500);
        $calls  = isset($r1['tool_calls']) ? $r1['tool_calls'] : array();
        $call   = null;
        foreach ($calls as $candidate) {
            if ($candidate['name'] === tool::NAME) {
                $call = $candidate;
                break;
            }
        }
        $out['checks'][] = self::check('diag_step_toolcall', $call !== null,
            'finish_reason=' . (string)$r1['finish_reason'] . ', tool_calls=' . count($calls));
        $leak = self::find_leak((string)$r1['content']);
        $out['checks'][] = self::check('diag_step_noleak', $leak === '', $leak);
        if ($call === null) {
            $out['seconds'] = round(microtime(true) - $start, 1);
            return $out;
        }
        $query = tool::parse_query($call['arguments']);
        $out['checks'][] = self::check('diag_step_arguments', $query !== null && trim($query) !== '',
            \core_text::substr((string)$call['arguments'], 0, 300));

        // Tour 2 : résultat factice, outil retiré (comme le dernier tour du
        // tuteur) ; le modèle doit rédiger sa réponse.
        $messages[] = array(
            'role'       => 'assistant',
            'content'    => ($r1['content'] !== '') ? $r1['content'] : null,
            'tool_calls' => array(array('id' => $call['id'], 'type' => 'function',
                'function' => array('name' => $call['name'], 'arguments' => $call['arguments']))),
        );
        $messages[] = array(
            'role'         => 'tool',
            'tool_call_id' => $call['id'],
            'content'      => tool::format_results((string)$query, array(array(
                'title'   => 'Download Python | Python.org',
                'url'     => self::FAKE_URL,
                'snippet' => 'Python 3.99.0 is the latest stable release (résultat factice de test).',
                'age'     => '',
            )), 1, 'test'),
        );
        try {
            $r2 = api::stream($messages, $options, $quiet);
        } catch (\Throwable $e) {
            $out['checks'][] = self::check('diag_step_final', false, self::error_text($e));
            $out['seconds']  = round(microtime(true) - $start, 1);
            return $out;
        }
        $out['answer2'] = \core_text::substr((string)$r2['content'], 0, 1500);
        $out['checks'][] = self::check('diag_step_final',
            trim((string)$r2['content']) !== '' && empty($r2['tool_calls']),
            'finish_reason=' . (string)$r2['finish_reason']);
        $leak = self::find_leak((string)$r2['content']);
        $out['checks'][] = self::check('diag_step_noleak2', $leak === '', $leak);

        $out['seconds'] = round(microtime(true) - $start, 1);
        return $out;
    }

    /**
     * Une vraie recherche, par le chemin normal (réservation du budget,
     * suspension en cas d'échec). Ignore une suspension en cours : c'est le
     * moyen de vérifier une clé corrigée. Un succès lève la suspension.
     *
     * @param string $query
     * @return result
     */
    public static function test_search($query = 'Moodle LMS') {
        $provider = manager::provider();
        if (!$provider->is_configured()) {
            return result::failure('not_configured', false);
        }
        $ticket = budget::reserve(manager::cap());
        if (!is_int($ticket)) {
            return result::failure($ticket, false);
        }
        $result = $provider->search($query, manager::maxresults());
        budget::settle($ticket, $result->billable);
        budget::record_ratelimit($result->ratelimit);
        if ($result->ok) {
            budget::unblock();
        } else {
            budget::record_error($result->reason, $result->detail);
            if ($result->blockfor !== 0) {
                budget::block($result->reason, $result->blockfor, $result->detail);
            }
        }
        return $result;
    }

    /** Premier marqueur d'appel d'outil trouvé dans un texte, ou ''. */
    public static function find_leak($text) {
        foreach (self::LEAK_MARKERS as $marker) {
            if (stripos($text, $marker) !== false) {
                return $marker;
            }
        }
        return '';
    }

    private static function check($label, $ok, $detail) {
        return array('label' => $label, 'ok' => $ok, 'detail' => (string)$detail);
    }

    private static function error_text(\Throwable $e) {
        return ($e instanceof \moodle_exception && !empty($e->debuginfo))
            ? (string)$e->debuginfo : $e->getMessage();
    }
}
