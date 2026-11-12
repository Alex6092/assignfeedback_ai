<?php
defined('MOODLE_INTERNAL') || die();

function assignsubmission_notebook_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;
    require_login($course, false, $cm);
    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== 'submission') {
        return false;
    }
    $run = $DB->get_record('local_notebook_run', ['id' => (int)array_shift($args), 'cmid' => $cm->id], '*', MUST_EXIST);
    $assign = \local_notebook\manager::assignment($cm->id);
    if (!$assign->can_view_submission($run->userid)) {
        return false;
    }
    $name = array_pop($args);
    $path = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'assignsubmission_notebook', 'submission', $run->id, $path, $name);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options); // Never execute student HTML inside Moodle.
}
