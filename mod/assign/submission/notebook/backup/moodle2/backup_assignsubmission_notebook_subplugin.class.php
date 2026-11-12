<?php
defined('MOODLE_INTERNAL') || die();

class backup_assignsubmission_notebook_subplugin extends backup_subplugin {
    protected function define_assign_subplugin_structure() {
        $root = $this->get_subplugin_element();
        $versions = new backup_nested_element($this->get_recommended_name());
        $version = new backup_nested_element('notebook_version', ['id'], [
            'userid', 'bundle', 'released', 'fingerprint', 'status', 'report', 'timecreated', 'activeversion']);
        $root->add_child($versions);
        $versions->add_child($version);
        $version->set_source_sql("SELECT v.*, p.value AS activeversion
            FROM {local_notebook_version} v
            JOIN {course_modules} cm ON cm.id = v.cmid
            JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
            LEFT JOIN {assign_plugin_config} p ON p.assignment = cm.instance
                AND p.subtype = 'assignsubmission' AND p.plugin = 'notebook' AND p.name = 'version'
            WHERE cm.instance = :assignment", ['assignment' => backup::VAR_PARENTID]);
        $version->annotate_ids('user', 'userid');
        return $root;
    }

    protected function define_submission_subplugin_structure() {
        $root = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $run = new backup_nested_element('notebook_run', ['id'], ['userid', 'submissionid', 'versionid', 'attempt',
            'fingerprint', 'generation', 'status', 'tests', 'result', 'timecreated', 'timemodified']);
        $root->add_child($wrapper);
        $wrapper->add_child($run);
        $run->set_source_table('local_notebook_run', ['submissionid' => backup::VAR_PARENTID]);
        $run->annotate_ids('user', 'userid');
        $run->annotate_files('assignsubmission_notebook', 'submission', 'id');
        return $root;
    }
}
