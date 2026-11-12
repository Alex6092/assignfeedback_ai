<?php
namespace local_notebook\task;
defined('MOODLE_INTERNAL') || die();

class reconcile extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('reconcile', 'local_notebook');
    }

    public function execute() {
        global $DB, $CFG;
        \local_notebook\manager::purge_orphans();
        foreach ($DB->get_records('local_notebook_version', ['status' => 'validating']) as $v) {
            try {
                $job = \local_notebook\client::call('grader', '/v1/jobs/' . $v->remoteid);
                if ($job['status'] === 'done') {
                    $v->status = !empty($job['result']['valid']) ? 'valid' : 'invalid';
                    $bundle = json_decode($v->bundle);
                    $bundle->environment = $job['result']['environment'];
                    $v->bundle = json_encode($bundle, JSON_UNESCAPED_UNICODE);
                    $released = $job['result']['release'];
                    $released['metadata'] = (object)($released['metadata'] ?? []);
                    foreach ($released['cells'] as &$cell) {
                        $cell['metadata'] = (object)($cell['metadata'] ?? []);
                    }
                    unset($cell);
                    $v->released = json_encode($released);
                    $v->fingerprint = hash('sha256', $v->bundle);
                    unset($job['result']['release']);
                    $v->report = json_encode($job['result'], JSON_UNESCAPED_UNICODE);
                    $DB->update_record('local_notebook_version', $v);
                } else if ($job['status'] === 'failed') {
                    $v->status = 'failed';
                    $v->report = $job['error'];
                    $DB->update_record('local_notebook_version', $v);
                }
            } catch (\Throwable $e) {
                mtrace('Notebook validation ' . $v->id . ': ' . $e->getMessage());
            }
        }
        foreach ($DB->get_records_select('local_notebook_run', 'status IN (?, ?)', ['queued', 'testing']) as $run) {
            $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock('grade-' . $run->id, 0);
            if (!$lock) {
                continue;
            }
            try {
                // The teacher may have restarted this correction while cron waited for the lock.
                $run = $DB->get_record('local_notebook_run', ['id' => $run->id], '*', MUST_EXIST);
                if (!in_array($run->status, ['queued', 'testing'], true)) { continue; }
                if ($run->status === 'queued') {
                    $v = $DB->get_record('local_notebook_version', ['id' => $run->versionid], '*', MUST_EXIST);
                    $job = \local_notebook\client::call('grader', '/v1/jobs', ['kind' => 'grade', 'payload' => [
                        'bundle' => json_decode($v->bundle), 'files' => \local_notebook\manager::run_files($run),
                        'owner' => ['site' => hash('sha256', $CFG->wwwroot), 'userid' => (int)$run->userid, 'cmid' => (int)$run->cmid]]],
                        'grade-' . $run->id . '-' . $run->generation);
                    $run->remoteid = $job['id'];
                    $run->status = 'testing';
                    $DB->update_record('local_notebook_run', $run);
                }
                $job = \local_notebook\client::call('grader', '/v1/jobs/' . $run->remoteid);
                if ($job['status'] === 'done') {
                    $run->tests = json_encode($job['result']);
                    $run->status = 'ai';
                    $run->error = null;
                    $DB->update_record('local_notebook_run', $run);
                    \local_aifeedback\jobs::enqueue('local_notebook', (object)['runid' => (int)$run->id,
                        'generation' => $run->generation]);
                } else if ($job['status'] === 'failed') {
                    $run->status = 'failed';
                    $run->error = $job['error'];
                    $DB->update_record('local_notebook_run', $run);
                    $DB->update_record('assignfeedback_ai_grade', (object)['id' => $run->feedbackid,
                        'status' => 'failed', 'error_message' => $job['error'], 'timemodified' => time()]);
                }
            } catch (\Throwable $e) {
                // Transient transport failures stay retryable; no grade is written.
                $DB->set_field('local_notebook_run', 'error', $e->getMessage(), ['id' => $run->id]);
            } finally {
                $lock->release();
            }
        }
        $DB->delete_records_select('local_notebook_launch', 'used = 0 AND expires < ?', [time() - DAYSECS]);
        foreach ($DB->get_records('local_notebook_run', ['status' => 'applying']) as $run) {
            $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock('grade-' . $run->id, 0);
            if (!$lock) { continue; }
            try {
                \local_notebook\grading::apply($run);
            } catch (\Throwable $e) {
                $DB->set_field('local_notebook_run', 'error', $e->getMessage(), ['id' => $run->id]);
            } finally {
                $lock->release();
            }
        }
    }
}
