<?php
namespace local_notebook;
defined('MOODLE_INTERNAL') || die();

class manager {
    public static function assignment(int $cmid): \assign {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
        return new \assign(\context_module::instance($cmid), $cm, $course);
    }

    public static function allowed(int $cmid, int $userid, bool $teacher = false): bool {
        try {
            $assign = self::assignment($cmid);
            $ctx = $assign->get_context();
            if (!is_enrolled($ctx, $userid, '', true) && !has_capability('moodle/site:config', $ctx, $userid)) {
                return false;
            }
            if ($teacher) {
                return has_capability('local/notebook:manage', $ctx, $userid);
            }
            $cm = get_fast_modinfo($assign->get_course(), $userid)->get_cm($cmid);
            $plugin = $assign->get_submission_plugin_by_type('notebook');
            if (!$plugin || !$plugin->is_enabled() || !$cm->uservisible || $assign->get_instance()->teamsubmission) {
                return false;
            }
            // A released correction may make a supervised activity visible again;
            // that must not reopen its executable workspace outside a session.
            if (class_exists('\local_classhours\gate') && \local_classhours\gate::is_supervised_json($cm->availability)) {
                if (!\local_classhours\gate::is_open_for((int)$cm->course, $cmid, $userid)) {
                    return false;
                }
            }
            return has_capability('mod/assign:submit', $ctx, $userid)
                && $assign->submissions_open($userid) && $assign->can_edit_submission($userid, $userid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function validate_bundle(array $bundle): void {
        if (($bundle['profile'] ?? 'python') !== 'python' || ($bundle['master']['nbformat'] ?? 0) !== 4
                || empty($bundle['master']['cells']) || empty($bundle['rubric'])
                || count($bundle['master']['cells']) > 300) {
            throw new \moodle_exception('invalidbundle', 'local_notebook');
        }
        $weight = $bundle['tests_weight'] ?? 70;
        if (!is_int($weight) || $weight < 0 || $weight > 100 || strlen(json_encode($bundle)) > 20 * 1024 * 1024) {
            throw new \moodle_exception('invalidbundle', 'local_notebook');
        }
        self::decode_files($bundle['files'] ?? []);
    }

    public static function decode_files(array $files): array {
        $total = 0;
        $decoded = [];
        if (count($files) > 100) {
            throw new \moodle_exception('invalidbundle', 'local_notebook');
        }
        foreach ($files as $name => $encoded) {
            if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_./-]{0,179}$~D', $name)
                    || preg_match('~(^|/)\.|//~', $name)
                    || !preg_match('~\.(ipynb|py|csv|json|txt|png|jpg|npy)$~i', $name)) {
                throw new \moodle_exception('invalidbundle', 'local_notebook');
            }
            $raw = base64_decode($encoded, true);
            if ($raw === false || ($total += strlen($raw)) > 20 * 1024 * 1024) {
                throw new \moodle_exception('invalidbundle', 'local_notebook');
            }
            $decoded[$name] = $raw;
        }
        return $decoded;
    }

    public static function put_version(int $cmid, array $bundle, bool $dryrun = false): array {
        global $DB, $USER;
        require_capability('local/notebook:manage', \context_module::instance($cmid));
        self::validate_bundle($bundle);
        $bundle['tests_weight'] = $bundle['tests_weight'] ?? 70;
        $bundle['files'] = (object)($bundle['files'] ?? []);
        $bundle['master']['metadata'] = (object)($bundle['master']['metadata'] ?? []);
        foreach ($bundle['master']['cells'] as &$cell) {
            $cell['metadata'] = (object)($cell['metadata'] ?? []);
        }
        unset($cell);
        $bundle['profile'] = 'python';
        $encoded = json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($dryrun) {
            return ['dryrun' => true, 'valid_structure' => true, 'tests_weight' => $bundle['tests_weight']];
        }
        $version = (object)['cmid' => $cmid, 'userid' => $USER->id, 'bundle' => $encoded,
            'fingerprint' => hash('sha256', $encoded), 'status' => 'draft', 'timecreated' => time()];
        $version->id = $DB->insert_record('local_notebook_version', $version);
        return ['version' => (int)$version->id, 'status' => 'draft'];
    }

    public static function validate(int $id): array {
        global $DB, $CFG;
        $v = $DB->get_record('local_notebook_version', ['id' => $id], '*', MUST_EXIST);
        require_capability('local/notebook:manage', \context_module::instance($v->cmid));
        if ($v->status === 'valid') {
            return ['version' => $id, 'status' => 'valid', 'job' => $v->remoteid];
        }
        $job = client::call('grader', '/v1/jobs', ['kind' => 'validate',
            'payload' => ['bundle' => json_decode($v->bundle), 'owner' => ['site' => hash('sha256', $CFG->wwwroot),
                'userid' => (int)$v->userid, 'cmid' => (int)$v->cmid]]], 'validate-' . $v->id . '-' . $v->fingerprint);
        $DB->update_record('local_notebook_version', (object)['id' => $id, 'remoteid' => $job['id'], 'status' => 'validating']);
        return ['version' => $id, 'job' => $job['id'], 'status' => 'validating'];
    }

    public static function publish(int $id): void {
        global $DB;
        $v = $DB->get_record('local_notebook_version', ['id' => $id, 'status' => 'valid'], '*', MUST_EXIST);
        require_capability('local/notebook:manage', \context_module::instance($v->cmid));
        $assign = self::assignment($v->cmid);
        if ($assign->get_instance()->teamsubmission) {
            throw new \moodle_exception('individualonly', 'local_notebook');
        }
        $DB->set_field('assign', 'submissiondrafts', 1, ['id' => $assign->get_instance()->id]);
        foreach (['file', 'onlinetext'] as $type) {
            $other = $assign->get_submission_plugin_by_type($type);
            if ($other) { $other->set_config('enabled', 0); }
        }
        $assign->get_submission_plugin_by_type('notebook')->set_config('version', $id);
        $assign->get_submission_plugin_by_type('notebook')->set_config('enabled', 1);
    }

    public static function current(int $cmid): ?\stdClass {
        global $DB;
        $plugin = self::assignment($cmid)->get_submission_plugin_by_type('notebook');
        return $plugin ? ($DB->get_record('local_notebook_version', ['id' => (int)$plugin->get_config('version'),
            'cmid' => $cmid, 'status' => 'valid']) ?: null) : null;
    }

    public static function launch(int $cmid, bool $teacher, int $version = 0): string {
        global $DB, $USER;
        if (!self::allowed($cmid, (int)$USER->id, $teacher)) {
            throw new \moodle_exception('accessdenied', 'local_notebook');
        }
        $v = $teacher ? $DB->get_record('local_notebook_version', ['id' => $version, 'cmid' => $cmid], '*', MUST_EXIST)
            : self::current($cmid);
        if (!$v) {
            throw new \moodle_exception('notready', 'local_notebook');
        }
        // Existing work remains attached to the version with which it started.
        $old = $DB->get_records('local_notebook_launch', ['cmid' => $cmid, 'userid' => $USER->id,
            'teacher' => (int)$teacher], 'id DESC', '*', 0, 1);
        if (!$teacher && $old) {
            $v = $DB->get_record('local_notebook_version', ['id' => reset($old)->versionid], '*', MUST_EXIST);
        }
        $code = bin2hex(random_bytes(32));
        $DB->set_field('local_notebook_launch', 'used', 2, ['cmid' => $cmid, 'userid' => $USER->id, 'used' => 1]);
        $DB->insert_record('local_notebook_launch', (object)['cmid' => $cmid, 'userid' => $USER->id,
            'versionid' => $v->id, 'teacher' => (int)$teacher, 'codehash' => hash('sha256', $code),
            'lease' => bin2hex(random_bytes(32)), 'expires' => time() + 60, 'used' => 0]);
        return $code;
    }

    public static function exchange(string $code): array {
        global $DB, $CFG;
        $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock(hash('sha256', $code), 5);
        if (!$lock) {
            throw new \moodle_exception('accessdenied', 'local_notebook');
        }
        try {
            $row = $DB->get_record('local_notebook_launch', ['codehash' => hash('sha256', $code), 'used' => 0], '*', MUST_EXIST);
            if ($row->expires < time() || !self::allowed($row->cmid, $row->userid, (bool)$row->teacher)) {
                throw new \moodle_exception('accessdenied', 'local_notebook');
            }
            $DB->set_field('local_notebook_launch', 'used', 1, ['id' => $row->id]);
            $v = $DB->get_record('local_notebook_version', ['id' => $row->versionid], '*', MUST_EXIST);
            $spec = json_decode($v->bundle, true);
            $files = $spec['files'];
            $files['assignment.ipynb'] = base64_encode($row->teacher ? json_encode(json_decode($v->bundle)->master) : $v->released);
            return ['site' => hash('sha256', $CFG->wwwroot), 'userid' => (int)$row->userid, 'cmid' => (int)$row->cmid,
                'version' => (int)$row->versionid, 'teacher' => (bool)$row->teacher, 'lease' => $row->lease,
                'files' => $files, 'egress_hosts' => $spec['egress_hosts'] ?? [], 'environment' => $spec['environment'] ?? null,
                'return_url' => $CFG->wwwroot . '/local/notebook/capture.php?cmid=' . $row->cmid
                    . '&teacher=' . (int)$row->teacher];
        } finally {
            $lock->release();
        }
    }

    public static function capture(int $cmid, bool $teacher = false): int {
        global $DB, $USER;
        if (!self::allowed($cmid, $USER->id, $teacher)) {
            throw new \moodle_exception('accessdenied', 'local_notebook');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_notebook')->get_lock('capture-' . $cmid . '-' . $USER->id, 5);
        if (!$lock) {
            throw new \moodle_exception('serviceerror', 'local_notebook');
        }
        try {
            $rows = $DB->get_records('local_notebook_launch', ['cmid' => $cmid, 'userid' => $USER->id,
                'teacher' => (int)$teacher, 'used' => 1], 'id DESC', '*', 0, 1);
            $lease = reset($rows);
            if (!$lease) {
                throw new \moodle_exception('notready', 'local_notebook');
            }
            $capture = client::call('workspace', '/v1/capture', ['lease' => $lease->lease]);
            $raw = self::decode_files($capture['files']);
            if (!isset($raw['assignment.ipynb']) || !self::allowed($cmid, $USER->id, $teacher)) {
                throw new \moodle_exception('accessdenied', 'local_notebook');
            }
            if ($teacher) {
                $v = $DB->get_record('local_notebook_version', ['id' => $lease->versionid], '*', MUST_EXIST);
                $spec = json_decode($v->bundle, true);
                $spec['master'] = json_decode($raw['assignment.ipynb'], true, 512, JSON_THROW_ON_ERROR);
                unset($capture['files']['assignment.ipynb']);
                $spec['files'] = $capture['files'];
                return self::put_version($cmid, $spec)['version'];
            }
            $assign = self::assignment($cmid);
            $submission = $assign->get_user_submission($USER->id, true);
            if ($submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                throw new \moodle_exception('accessdenied', 'local_notebook');
            }
            ksort($capture['files']);
            $fingerprint = hash('sha256', json_encode($capture['files']));
            $previous = self::run_for_submission($submission->id);
            if ($previous && $previous->status === 'draft' && (int)$previous->versionid === (int)$lease->versionid
                    && hash_equals($previous->fingerprint, $fingerprint)) {
                return (int)$previous->id;
            }
            $transaction = $DB->start_delegated_transaction();
            $run = (object)['cmid' => $cmid, 'userid' => $USER->id, 'submissionid' => $submission->id,
                'attempt' => $submission->attemptnumber, 'versionid' => $lease->versionid,
                'fingerprint' => $fingerprint, 'status' => 'draft',
                'generation' => bin2hex(random_bytes(16)), 'timecreated' => time(), 'timemodified' => time()];
            $run->id = $DB->insert_record('local_notebook_run', $run);
            foreach ($raw as $name => $bytes) {
                get_file_storage()->create_file_from_string(['contextid' => $assign->get_context()->id,
                    'component' => 'assignsubmission_notebook', 'filearea' => 'submission', 'itemid' => $run->id,
                    'filepath' => dirname($name) === '.' ? '/' : '/' . dirname($name) . '/',
                    'filename' => basename($name), 'userid' => $USER->id], $bytes);
            }
            $submission->status = ASSIGN_SUBMISSION_STATUS_DRAFT;
            $submission->timemodified = time();
            $DB->update_record('assign_submission', $submission);
            $transaction->allow_commit();
            return (int)$run->id;
        } finally {
            $lock->release();
        }
    }

    public static function run_for_submission(int $id): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_notebook_run', ['submissionid' => $id], 'id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    public static function run_files(\stdClass $run): array {
        $files = get_file_storage()->get_area_files(\context_module::instance($run->cmid)->id,
            'assignsubmission_notebook', 'submission', $run->id, 'filepath, filename', false);
        $result = [];
        foreach ($files as $file) {
            $result[ltrim($file->get_filepath(), '/') . $file->get_filename()] = base64_encode($file->get_content());
        }
        return $result;
    }

    /** Remote erasure is idempotent and precedes deletion of its local ownership records. */
    public static function erase_remote(int $cmid): void {
        global $CFG;
        $identity = ['site' => hash('sha256', $CFG->wwwroot), 'cmid' => $cmid, 'userid' => 0];
        client::call('workspace', '/v1/data/delete', $identity);
        client::call('grader', '/v1/data/delete', $identity);
    }

    public static function purge_orphans(): void {
        global $DB;
        $cmids = $DB->get_fieldset_sql('SELECT DISTINCT v.cmid FROM {local_notebook_version} v
            LEFT JOIN {course_modules} cm ON cm.id = v.cmid WHERE cm.id IS NULL');
        foreach ($cmids as $cmid) {
            try {
                self::erase_remote((int)$cmid);
                foreach (['run', 'launch', 'gen', 'version'] as $table) {
                    $DB->delete_records('local_notebook_' . $table, ['cmid' => $cmid]);
                }
            } catch (\Throwable $e) {
                mtrace('Notebook cleanup ' . $cmid . ': ' . $e->getMessage());
            }
        }
    }
}
