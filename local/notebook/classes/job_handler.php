<?php
namespace local_notebook;
defined('MOODLE_INTERNAL') || die();

class job_handler implements \local_aifeedback\async_handler {
    public function build_request(\stdClass $payload): ?\local_aifeedback\request {
        global $DB;
        if (isset($payload->generationid)) {
            $job = $DB->get_record('local_notebook_gen', ['id' => $payload->generationid, 'status' => 'pending']);
            if (!$job) {
                return null;
            }
            $system = 'Créer un TP Python nbformat 4. Retourner un JSON avec master (notebook), rubric (barème IA), '
                . 'tests_weight (70), profile (python), files (objet vide). Chaque cellule solution a metadata.nbgrader '
                . '{solution:true,grade:false,grade_id:identifiant_unique,schema_version:3,locked:false}. '
                . 'Chaque cellule de test a {solution:false,grade:true,grade_id:identifiant_unique,points:entier_positif,'
                . 'schema_version:3,locked:true}. Mettre les réponses entre ### BEGIN SOLUTION et ### END SOLUTION, '
                . 'les tests privés entre ### BEGIN HIDDEN TESTS et ### END HIDDEN TESTS. '
                . 'Code autonome, pas de réseau, bibliothèques numpy/pandas/matplotlib disponibles. '
                . 'Prévoir plusieurs tests discriminants. L’IA note uniquement méthode, explications et lisibilité. '
                . 'Les cellules code ont outputs:[] et execution_count:null. Aucun corrigé dans les consignes élèves.';
            return new \local_aifeedback\request([['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $job->prompt]], ['response_format' => ['type' => 'json_object'],
                    'max_tokens' => 16384, 'temperature' => 0.2], '', $job->userid);
        }
        $run = grading::active($payload);
        return $run ? grading::plugin($run)->build_notebook_request($run) : null;
    }

    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        global $DB;
        if (isset($payload->generationid)) {
            $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock('generate-' . $payload->generationid, 10);
            if (!$lock) { throw new \moodle_exception('serviceerror', 'local_notebook'); }
            try {
                $job = $DB->get_record('local_notebook_gen', ['id' => $payload->generationid, 'status' => 'pending']);
                if (!$job) {
                    return null;
                }
                // Background callbacks run as an administrator; preserve original authorship.
                if (!has_capability('local/notebook:manage', \context_module::instance($job->cmid), $job->userid)) {
                    throw new \moodle_exception('accessdenied', 'local_notebook');
                }
                if (!$job->versionid) {
                    $tx = $DB->start_delegated_transaction();
                    try {
                        $created = manager::put_version($job->cmid, $result);
                        $job->versionid = $created['version'];
                        $DB->set_field('local_notebook_version', 'userid', $job->userid, ['id' => $job->versionid]);
                        $DB->set_field('local_notebook_gen', 'versionid', $job->versionid, ['id' => $job->id]);
                        $tx->allow_commit();
                    } catch (\Throwable $e) {
                        $tx->rollback($e);
                    }
                }
                manager::validate($job->versionid);
                $DB->set_field('local_notebook_gen', 'status', 'done', ['id' => $job->id]);
                return null;
            } finally {
                $lock->release();
            }
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock('grade-' . $payload->runid, 10);
        if (!$lock) {
            throw new \moodle_exception('serviceerror', 'local_notebook');
        }
        try {
            $run = grading::active($payload);
            if (!$run) {
                return null;
            }
            $v = $DB->get_record('local_notebook_version', ['id' => $run->versionid], '*', MUST_EXIST);
            $spec = json_decode($v->bundle, true);
            $tests = json_decode($run->tests, true);
            if (!isset($result['score']) || (!is_int($result['score']) && !is_float($result['score']))) {
                throw new \moodle_exception('invalidbundle', 'local_notebook');
            }
            $combined = grading::combine((float)$tests['score'], (float)$result['score'], $spec['tests_weight']);
            $result['notebook'] = ['tests_score' => $tests['score'], 'ai_score' => $result['score'],
                'tests_weight' => $spec['tests_weight'], 'runid' => $run->id, 'generation' => $run->generation];
            $result = array_merge($result, $combined);
            // Persist before applying: a failed gradebook update can reuse this result.
            $run->result = json_encode($result, JSON_UNESCAPED_UNICODE);
            $run->status = 'applying';
            $DB->update_record('local_notebook_run', $run);
            try {
                grading::apply($run);
            } catch (\Throwable $e) {
                // Cron retries application of this saved result, without another LLM call.
                $DB->set_field('local_notebook_run', 'error', $e->getMessage(), ['id' => $run->id]);
            }
        } finally {
            $lock->release();
        }
        return null;
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        global $DB;
        $key = isset($payload->generationid) ? 'generate-' . $payload->generationid : 'grade-' . $payload->runid;
        $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock($key, 10);
        if (!$lock) { throw new \moodle_exception('serviceerror', 'local_notebook'); }
        try {
            $table = isset($payload->generationid) ? 'local_notebook_gen' : 'local_notebook_run';
            $id = $payload->generationid ?? $payload->runid;
            if (isset($payload->generationid) && !$DB->record_exists($table, ['id' => $id, 'status' => 'pending'])) {
                return null;
            }
            if (!isset($payload->generationid) && !grading::active($payload)) {
                return null;
            }
            $DB->update_record($table, (object)['id' => $id, 'status' => 'failed', 'error' => $error]);
            if ($table === 'local_notebook_run') {
                $run = $DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
                $DB->update_record('assignfeedback_ai_grade', (object)['id' => $run->feedbackid,
                    'status' => 'failed', 'error_message' => $error, 'timemodified' => time()]);
            }
            return null; // Explicit retry uses a new correction generation.
        } finally {
            $lock->release();
        }
    }

    public function pending_payloads(int $before): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records_select('local_notebook_run', 'status = ? AND timemodified < ?', ['ai', $before]) as $run) {
            $out[] = (object)['runid' => (int)$run->id, 'generation' => $run->generation];
        }
        foreach ($DB->get_records_select('local_notebook_gen', 'status = ? AND timecreated < ?', ['pending', $before]) as $job) {
            $out[] = (object)['generationid' => (int)$job->id];
        }
        return $out;
    }
}
