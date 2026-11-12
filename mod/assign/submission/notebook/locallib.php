<?php
defined('MOODLE_INTERNAL') || die();

class assign_submission_notebook extends assign_submission_plugin {
    public function get_name() {
        return get_string('pluginname', 'assignsubmission_notebook');
    }

    public function get_settings(MoodleQuickForm $mform) {
        if ($this->assignment->get_course_module()) {
            $url = new moodle_url('/local/notebook/manage.php', ['cmid' => $this->assignment->get_course_module()->id]);
            $mform->addElement('static', 'notebook_prepare', '', html_writer::link($url,
                get_string('manage', 'local_notebook')));
        }
    }

    public function save_settings(stdClass $data) {
        return true;
    }

    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        $mform->addElement('static', 'notebook_actions', '', $this->links());
        return true;
    }

    private function links() {
        $cmid = $this->assignment->get_course_module()->id;
        return html_writer::link(new moodle_url('/local/notebook/launch.php', ['cmid' => $cmid]),
            get_string('open', 'local_notebook'), ['target' => '_blank', 'rel' => 'noopener', 'class' => 'btn btn-primary'])
            . ' ' . html_writer::link(new moodle_url('/local/notebook/capture.php', ['cmid' => $cmid]),
                get_string('capture', 'local_notebook'), ['class' => 'btn btn-secondary']);
    }

    public function save(stdClass $submission, stdClass $data) {
        return true; // Capture is explicitly performed before native submission confirmation.
    }

    public function is_empty(stdClass $submission) {
        return !\local_notebook\manager::run_for_submission((int)$submission->id);
    }

    public function view_summary(stdClass $submission, &$showviewlink) {
        $run = \local_notebook\manager::run_for_submission((int)$submission->id);
        $showviewlink = (bool)$run;
        return $run ? s(get_string('captured', 'assignsubmission_notebook', userdate($run->timecreated))) : $this->links();
    }

    public function view(stdClass $submission) {
        $run = \local_notebook\manager::run_for_submission((int)$submission->id);
        $html = $run ? html_writer::div(html_writer::link(new moodle_url('/local/notebook/view.php',
            ['runid' => $run->id]), get_string('viewcopy', 'local_notebook'))) : '';
        foreach ($this->get_files($submission, new stdClass()) as $file) {
            $url = moodle_url::make_pluginfile_url($file->get_contextid(), 'assignsubmission_notebook',
                'submission', $file->get_itemid(), $file->get_filepath(), $file->get_filename(), true);
            $html .= html_writer::div(html_writer::link($url, s($file->get_filename())));
        }
        return $html;
    }

    public function get_files(stdClass $submission, stdClass $user) {
        $run = \local_notebook\manager::run_for_submission((int)$submission->id);
        return $run ? get_file_storage()->get_area_files($this->assignment->get_context()->id,
            'assignsubmission_notebook', 'submission', $run->id, 'filepath, filename', false) : [];
    }

    public function delete_instance() {
        global $DB;
        $cmid = $this->assignment->get_course_module()->id;
        if ($DB->record_exists('local_notebook_launch', ['cmid' => $cmid])
                || $DB->record_exists('local_notebook_run', ['cmid' => $cmid])) {
            \local_notebook\manager::erase_remote((int)$cmid);
        }
        get_file_storage()->delete_area_files($this->assignment->get_context()->id, 'assignsubmission_notebook');
        // Also called by course reset: preserve teaching material. Cron removes orphan subjects after deletion.
        foreach (['run', 'launch', 'gen'] as $table) {
            $DB->delete_records('local_notebook_' . $table, ['cmid' => $cmid]);
        }
        return true;
    }
}
