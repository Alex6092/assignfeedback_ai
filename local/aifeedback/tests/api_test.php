<?php
namespace local_aifeedback;

use local_aifeedback\lb\lb_exception;

/**
 * Analyse des réponses (jobs et appels synchrones), lecture des flux SSE du
 * load balancer et préparation des demandes.
 *
 * @package    local_aifeedback
 * @covers     \local_aifeedback\api
 */
final class api_test extends \advanced_testcase {

    /** Réponse Chat Completions minimale. */
    private function response(string $content): array {
        return array('choices' => array(array('message' => array('role' => 'assistant', 'content' => $content),
            'finish_reason' => 'stop')));
    }

    public function test_parse_completion_json_text_and_reasoning(): void {
        $json = array('response_format' => array('type' => 'json_schema'));
        $this->assertSame(array('score' => 72),
            api::parse_completion($this->response("```json\n{\"score\":72}\n```"), $json));
        $this->assertSame(array('score' => 5),
            api::parse_completion($this->response('<think>calcul</think>Voici : {"score":5}'), $json));
        $this->assertSame(array('__text__' => 'Bonjour'),
            api::parse_completion($this->response('<think>…</think> Bonjour '), array()));
    }

    public function test_parse_completion_rejects_unusable_answers(): void {
        $json = array('response_format' => array('type' => 'json_schema'));
        foreach (array(array(), $this->response('<think>seulement</think>'), $this->response('pas du json')) as $data) {
            try {
                api::parse_completion($data, $json, '[job=x]');
                $this->fail('exception attendue');
            } catch (\moodle_exception $e) {
                $this->assertSame('apicallfailed', $e->errorcode);
            }
        }
    }

    public function test_sse_stream_with_waits_split_chunks_and_usage(): void {
        $waits = 0;
        $state = api::new_stream_state(function() use (&$waits) {
            $waits++;
            return true;
        });
        $deltas = '';
        $ondelta = function($d) use (&$deltas) {
            $deltas .= $d;
            return true;
        };
        $chunk = function($text) {
            return 'data: ' . json_encode(array('choices' => array(array('delta' => array('content' => $text))))) . "\n\n";
        };
        $raw = ": queued\n\n: heartbeat\n\n" . $chunk('<think>x</think>') . $chunk('Bon') . $chunk('jour')
            . 'data: ' . json_encode(array('choices' => array(), 'usage' => array('prompt_tokens' => 5))) . "\n\n"
            . "data: [DONE]\n\n";
        // Découpage TCP arbitraire : par morceaux de 7 octets.
        foreach (str_split($raw, 7) as $part) {
            $this->assertTrue(api::feed_sse($state, $part, $ondelta));
        }
        $this->assertSame(2, $waits);
        $this->assertSame('Bonjour', $deltas);
        $this->assertSame('Bonjour', $state->content);
        $this->assertSame(5, $state->usage['prompt_tokens']);
    }

    public function test_sse_error_event_after_open_stream(): void {
        $state = api::new_stream_state();
        $ok = api::feed_sse($state, ": queued\n\ndata: {\"error\":{\"code\":\"expired\",\"message\":\"Délai\"}}\n\n",
            function() {
                return true;
            });
        $this->assertFalse($ok);
        $this->assertSame('expired', $state->lberror['code']);
    }

    public function test_sse_abort_while_waiting_and_drain(): void {
        // Abandon pendant l'attente : la lecture s'arrête (connexion coupée).
        $state = api::new_stream_state(function() {
            return false;
        });
        $this->assertFalse(api::feed_sse($state, ": queued\n\n", function() {
            return true;
        }));
        $this->assertTrue($state->cut);

        // Arrêt pendant la génération, en mode « drain » : la suite est lue
        // sans être transmise.
        $state = api::new_stream_state();
        $calls = 0;
        $ondelta = function() use (&$calls) {
            $calls++;
            return false;
        };
        $line = 'data: ' . json_encode(array('choices' => array(array('delta' => array('content' => 'a'))))) . "\n\n";
        $this->assertFalse(api::feed_sse($state, $line, $ondelta));
        $state->draining = true;
        $this->assertTrue(api::feed_sse($state, $line . $line, $ondelta));
        $this->assertSame(1, $calls);
    }

    public function test_build_request_targets(): void {
        $this->resetAfterTest();
        set_config('lb_url', 'https://ia.example/v1/', 'local_aifeedback');
        set_config('lb_apikey', 'cle', 'local_aifeedback');
        set_config('model_feedback', 'modele-corrections', 'local_aifeedback');
        set_config('reasoning_budget', 0, 'local_aifeedback');
        // Catalogue en cache (pas d'appel réseau) : sortie maximale de 500 tokens.
        \cache::make('local_aifeedback', 'lbmodels')->set(sha1('https://ia.example|cle'), array(
            'time'   => time(),
            'models' => array('modele-corrections' => array('id' => 'modele-corrections', 'vision' => false,
                'context_tokens' => 8192, 'max_output_tokens' => 500)),
        ));

        $req = api::build_request(array(array('role' => 'user', 'content' => 'x')),
            array('userid' => 7, 'extra_body' => array('enable_thinking' => false)));
        $this->assertSame(500, $req['payload']['max_tokens'], 'plafonné au catalogue');
        $this->assertSame(api::TARGET_LB, $req['target']);
        $this->assertSame('https://ia.example/v1/chat/completions', $req['url']);
        $this->assertSame('modele-corrections', $req['payload']['model']);
        $this->assertContains('X-End-User-ID: moodle-7', $req['headers']);
        $this->assertContains('Authorization: Bearer cle', $req['headers']);
        $this->assertFalse($req['payload']['chat_template_kwargs']['enable_thinking']);

        // Surcharge d'une activité : API externe appelée directement.
        $req = api::build_request(array(array('role' => 'user', 'content' => 'x')), array(
            'apiurl' => 'https://api.openai.com/v1/chat/completions', 'model' => 'gpt-5-mini', 'apikey' => 'sk'));
        $this->assertSame(api::TARGET_DIRECT, $req['target']);
        $this->assertArrayHasKey('max_completion_tokens', $req['payload']);
        $this->assertNotContains('X-End-User-ID: moodle-0', $req['headers']);
    }

    public function test_build_request_requires_configuration(): void {
        $this->resetAfterTest();
        set_config('lb_url', '', 'local_aifeedback');
        $this->expectException(\moodle_exception::class);
        api::build_request(array(array('role' => 'user', 'content' => 'x')), array());
    }

    public function test_load_balancer_errors_are_classified(): void {
        $quota = lb\client::error_from_response(429, array('error' => array('code' => 'quota_exceeded',
            'message' => 'Budget', 'details' => array('retry_at' => gmdate('c', time() + 120)))));
        $this->assertTrue($quota->transient);
        $this->assertTrue($quota->is_quota());
        $this->assertGreaterThan(60, $quota->retryafter);

        $model = lb\client::error_from_response(400, array('error' => array('code' => 'unknown_model',
            'message' => 'Modèle inconnu.')));
        $this->assertFalse($model->transient);
        $this->assertInstanceOf(lb_exception::class, $model);
    }
}
