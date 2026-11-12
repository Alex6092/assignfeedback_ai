<?php
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$teacher = optional_param('teacher', false, PARAM_BOOL);
$assign = \local_notebook\manager::assignment($cmid);
require_login($assign->get_course(), false, $assign->get_course_module());
$PAGE->set_context($assign->get_context());
$PAGE->set_url(new moodle_url('/local/notebook/capture.php', ['cmid' => $cmid, 'teacher' => $teacher]));
$PAGE->set_title(get_string('capture', 'local_notebook'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $id = \local_notebook\manager::capture($cmid, $teacher);
    redirect(new moodle_url($teacher ? '/local/notebook/manage.php' : '/mod/assign/view.php',
        $teacher ? ['cmid' => $cmid, 'version' => $id] : ['id' => $cmid, 'action' => 'submit']));
}
echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('capture_help', 'local_notebook'), 'info');
echo $OUTPUT->single_button($PAGE->url, get_string('capture', 'local_notebook'), 'post');
echo $OUTPUT->footer();
