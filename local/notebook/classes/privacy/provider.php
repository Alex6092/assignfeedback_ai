<?php
namespace local_notebook\privacy;
defined('MOODLE_INTERNAL') || die();
use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;

class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider, \core_privacy\local\request\core_userlist_provider {
    public static function get_metadata(collection $collection): collection {
        foreach (['run', 'version', 'launch', 'gen'] as $table) {
            $collection->add_database_table('local_notebook_' . $table,
                ['userid' => 'privacy:metadata:userid', 'cmid' => 'privacy:metadata:cmid'], 'privacy:metadata');
        }
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        $collection->add_external_location_link('school_jupyter', ['userid' => 'privacy:metadata:userid',
            'files' => 'privacy:metadata:files'], 'privacy:metadata:external');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        foreach (['run', 'version', 'launch', 'gen'] as $table) {
            $list->add_from_sql('SELECT c.id FROM {context} c JOIN {local_notebook_' . $table . '}
                n ON n.cmid = c.instanceid WHERE c.contextlevel = :level AND n.userid = :userid',
                ['level' => CONTEXT_MODULE, 'userid' => $userid]);
        }
        return $list;
    }
    public static function get_users_in_context(userlist $list) {
        $context = $list->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) { return; }
        foreach (['run', 'version', 'launch', 'gen'] as $table) {
            $list->add_from_sql('userid', 'SELECT userid FROM {local_notebook_' . $table . '} WHERE cmid = ?', [$context->instanceid]);
        }
    }
    public static function export_user_data(approved_contextlist $list) {
        global $DB;
        $userid = $list->get_user()->id;
        foreach ($list->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) { continue; }
            foreach (['run', 'version', 'gen'] as $table) {
                foreach ($DB->get_records('local_notebook_' . $table, ['cmid' => $context->instanceid, 'userid' => $userid]) as $row) {
                    unset($row->generation, $row->gradestamp, $row->remoteid);
                    $path = ['Notebooks', $table, (string)$row->id];
                    writer::with_context($context)->export_data($path, $row);
                    if ($table === 'run') {
                        writer::with_context($context)->export_area_files($path, 'assignsubmission_notebook', 'submission', $row->id);
                    }
                }
            }
            // Working files are retained externally, in addition to the immutable Moodle copies.
            $cursor = [];
            $part = 0;
            do {
                $external = \local_notebook\client::call('workspace', '/v1/data/export',
                    array_merge(self::identity($context, $userid), $cursor));
                if ($external['entry']) {
                    writer::with_context($context)->export_data(['Notebooks', 'workspaces', 'part-' . $part++],
                        (object)$external['entry']);
                }
                $cursor = $external['next'];
            } while ($cursor !== null);
        }
    }
    private static function identity(\context $context, int $userid = 0): array {
        global $CFG;
        return ['site' => hash('sha256', $CFG->wwwroot), 'cmid' => (int)$context->instanceid, 'userid' => $userid];
    }
    private static function remove(\context $context, int $userid = 0): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) { return; }
        // If the external service is unavailable, fail rather than falsely report complete erasure.
        \local_notebook\client::call('workspace', '/v1/data/delete', self::identity($context, $userid));
        \local_notebook\client::call('grader', '/v1/data/delete', self::identity($context, $userid));
        $conditions = ['cmid' => $context->instanceid];
        if ($userid) { $conditions['userid'] = $userid; }
        foreach ($DB->get_records('local_notebook_run', $conditions) as $run) {
            get_file_storage()->delete_area_files($context->id, 'assignsubmission_notebook', 'submission', $run->id);
        }
        foreach (['run', 'launch', 'gen'] as $table) {
            $DB->delete_records('local_notebook_' . $table, $conditions);
        }
        // Subjects are course material; remove author identity on a per-user request.
        if ($userid) {
            $DB->set_field('local_notebook_version', 'userid', 0, $conditions);
        } else {
            $DB->delete_records('local_notebook_version', $conditions);
        }
    }
    public static function delete_data_for_all_users_in_context(\context $context) { self::remove($context); }
    public static function delete_data_for_user(approved_contextlist $list) {
        foreach ($list->get_contexts() as $context) { self::remove($context, $list->get_user()->id); }
    }
    public static function delete_data_for_users(approved_userlist $list) {
        foreach ($list->get_userids() as $userid) { self::remove($list->get_context(), $userid); }
    }
}
