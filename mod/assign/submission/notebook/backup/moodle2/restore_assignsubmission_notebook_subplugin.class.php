<?php
defined('MOODLE_INTERNAL') || die();

class restore_assignsubmission_notebook_subplugin extends restore_subplugin {
    protected $activeversion = 0;

    protected function define_assign_subplugin_structure() {
        return [new restore_path_element($this->get_namefor('version'), $this->get_pathfor('/notebook_version'))];
    }
    protected function define_submission_subplugin_structure() {
        return [new restore_path_element($this->get_namefor('run'), $this->get_pathfor('/notebook_run'))];
    }
    public function process_assignsubmission_notebook_version($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $active = (int)$data->activeversion === (int)$oldid;
        unset($data->id, $data->activeversion);
        $data->cmid = $this->task->get_moduleid();
        $data->userid = $this->get_mappingid('user', $data->userid, $this->task->get_userid());
        // Validation can be reused only with a stored release; remote jobs are not restored.
        if ($data->status !== 'valid' || empty($data->released)) {
            $data->status = 'draft';
        }
        $id = $DB->insert_record('local_notebook_version', $data);
        $this->set_mapping('notebook_version', $oldid, $id);
        if ($active && $data->status === 'valid') {
            $this->activeversion = $id;
        }
    }
    public function process_assignsubmission_notebook_run($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);
        $data->cmid = $this->task->get_moduleid();
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->submissionid = $this->get_mappingid('submission', $data->submissionid);
        $data->versionid = $this->get_mappingid('notebook_version', $data->versionid);
        $data->status = 'restored';
        $id = $DB->insert_record('local_notebook_run', $data);
        $this->set_mapping('notebook_run', $oldid, $id, true);
    }
    protected function after_execute_assign() {
        $assign = \local_notebook\manager::assignment($this->task->get_moduleid());
        $assign->get_submission_plugin_by_type('notebook')->set_config('version', $this->activeversion);
    }
    protected function after_execute_submission() {
        $this->add_related_files('assignsubmission_notebook', 'submission', 'notebook_run');
    }
}
