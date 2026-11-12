<?php
namespace local_notebook;
defined('MOODLE_INTERNAL') || die();

class grading {
    public static function stamp(\stdClass $grade): string {
        return hash('sha256', json_encode([(int)$grade->id, round((float)$grade->grade, 5), (int)$grade->grader,
            (int)$grade->timemodified, (int)$grade->attemptnumber]));
    }

    public static function check_target(int $assignid, int $userid, int $gradeid): void {
        global $DB;
        $cm = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $assign = manager::assignment($cm->id);
        $plugin = $assign->get_submission_plugin_by_type('notebook');
        if (!$plugin || !$plugin->is_enabled()) { return; }
        $grade = $DB->get_record('assign_grades', ['id' => $gradeid, 'userid' => $userid, 'assignment' => $assignid], '*', MUST_EXIST);
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission || (int)$grade->attemptnumber !== (int)$submission->attemptnumber
                || !manager::run_for_submission($submission->id)) {
            throw new \moodle_exception('notready', 'local_notebook');
        }
    }

    /** Atomically reset feedback and correction; repeated submission events are harmless. */
    public static function enqueue_feedback(int $assignid, int $userid, int $gradeid, bool $force): ?int {
        global $DB;
        $cm = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $assign = manager::assignment($cm->id);
        $plugin = $assign->get_submission_plugin_by_type('notebook');
        if (!$plugin || !$plugin->is_enabled()) {
            return null;
        }
        $submission = $assign->get_user_submission($userid, false);
        $run = $submission ? manager::run_for_submission($submission->id) : null;
        if (!$run || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            throw new \moodle_exception('notready', 'local_notebook');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock('grade-' . $run->id, 10);
        if (!$lock) { throw new \moodle_exception('serviceerror', 'local_notebook'); }
        $tx = null;
        try {
            self::check_target($assignid, $userid, $gradeid);
            $run = $DB->get_record('local_notebook_run', ['id' => $run->id], '*', MUST_EXIST);
            if (!$force && $run->feedbackid) { return (int)$run->feedbackid; }
            $grade = $DB->get_record('assign_grades', ['id' => $gradeid], '*', MUST_EXIST);
            $tx = $DB->start_delegated_transaction();
            $feedback = $DB->get_record('assignfeedback_ai_grade', ['assignment' => $assignid, 'grade' => $gradeid]);
            if (!$feedback) {
                $feedback = (object)['assignment' => $assignid, 'grade' => $gradeid, 'userid' => $userid, 'timecreated' => time()];
            }
            $feedback->status = 'pending';
            $feedback->attempts = 0;
            $feedback->aifeedback = null;
            $feedback->error_message = null;
            $feedback->timemodified = time();
            if (empty($feedback->id)) {
                $feedback->id = $DB->insert_record('assignfeedback_ai_grade', $feedback);
            } else {
                $DB->update_record('assignfeedback_ai_grade', $feedback);
            }
            $run->generation = bin2hex(random_bytes(16));
            $run->status = 'queued';
            $run->gradeid = $grade->id;
            $run->gradestamp = self::stamp($grade);
            $run->feedbackid = $feedback->id;
            $run->tests = null;
            $run->result = null;
            $run->remoteid = null;
            $run->error = null;
            $run->timemodified = time();
            $DB->update_record('local_notebook_run', $run);
            $tx->allow_commit();
            return (int)$feedback->id;
        } catch (\Throwable $e) {
            if ($tx) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function owns_feedback(int $id): bool {
        global $DB;
        return $DB->record_exists('local_notebook_run', ['feedbackid' => $id]);
    }

    public static function active(\stdClass $payload): ?\stdClass {
        global $DB;
        $run = $DB->get_record('local_notebook_run', ['id' => $payload->runid, 'generation' => $payload->generation]);
        if (!$run || !in_array($run->status, ['ai', 'applying'], true)) {
            return null;
        }
        $current = manager::assignment($run->cmid)->get_user_submission($run->userid, false);
        $grade = $DB->get_record('assign_grades', ['id' => $run->gradeid]);
        if (!$current || $current->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED
                || (int)$current->id !== (int)$run->submissionid
                || (int)manager::run_for_submission($current->id)->id !== (int)$run->id
                || !$grade || !hash_equals($run->gradestamp, self::stamp($grade))) {
            $DB->set_field_select('local_notebook_run', 'status', 'superseded', 'id = ? AND generation = ?',
                [$run->id, $run->generation]);
            return null;
        }
        return $run;
    }

    public static function combine(float $tests, float $ai, int $weight): array {
        foreach ([$tests, $ai, $weight] as $number) {
            if (!is_finite((float)$number) || $number < 0 || $number > 100) {
                throw new \moodle_exception('invalidbundle', 'local_notebook');
            }
        }
        $score = round(($tests * $weight + $ai * (100 - $weight)) / 100, 2);
        $levels = ['Maîtrise insuffisante', 'Maîtrise fragile', 'Maîtrise satisfaisante', 'Très bonne maîtrise'];
        return ['score' => $score, 'niveau' => $levels[($score >= 25) + ($score >= 50) + ($score >= 80)]];
    }

    public static function plugin(\stdClass $run): \assign_feedback_ai {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');
        return new \assign_feedback_ai(manager::assignment($run->cmid), 'ai');
    }

    public static function apply(\stdClass $run): void {
        global $DB;
        $current = self::active((object)['runid' => $run->id, 'generation' => $run->generation]);
        if (!$current || !$current->result) { return; }
        $tx = $DB->start_delegated_transaction();
        try {
            self::plugin($current)->complete_feedback_row($current->feedbackid, json_decode($current->result, true));
            $DB->set_field('local_notebook_run', 'status', 'done', ['id' => $current->id]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
    }

    public static function input(\stdClass $run): array {
        $files = manager::run_files($run);
        $nb = json_decode(base64_decode($files['assignment.ipynb']), true, 512, JSON_THROW_ON_ERROR);
        $parts = [];
        foreach ($nb['cells'] as $index => $cell) {
            $source = $cell['source'];
            $parts[] = 'Cellule ' . ($index + 1) . ' (' . $cell['cell_type'] . ")\n"
                . (is_array($source) ? implode('', $source) : $source);
        }
        $parts[] = "Résultats des tests (points déjà calculés) :\n" . $run->tests;
        $text = implode("\n\n", $parts);
        if (strlen($text) > 200000) {
            throw new \moodle_exception('invalidbundle', 'local_notebook');
        }
        return ['text' => $text, 'images' => []];
    }
}
