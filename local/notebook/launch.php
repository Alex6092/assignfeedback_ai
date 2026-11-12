<?php
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$teacher = optional_param('teacher', false, PARAM_BOOL);
$version = optional_param('version', 0, PARAM_INT);
$assign = \local_notebook\manager::assignment($cmid);
require_login($assign->get_course(), false, $assign->get_course_module());
$PAGE->set_context($assign->get_context());
$PAGE->set_url(new moodle_url('/local/notebook/launch.php', ['cmid' => $cmid, 'teacher' => $teacher, 'version' => $version]));
$PAGE->set_title(get_string('open', 'local_notebook'));
echo $OUTPUT->header();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $code = \local_notebook\manager::launch($cmid, $teacher, $version);
    $hub = rtrim((string)get_config('local_notebook', 'huburl'), '/');
    if (parse_url($hub, PHP_URL_SCHEME) !== 'https') {
        throw new moodle_exception('notconfigured', 'local_notebook');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $hub . '/hub/moodle']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'code', 'value' => $code]);
    echo html_writer::tag('button', get_string('open', 'local_notebook'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
} else {
    echo $OUTPUT->single_button($PAGE->url, get_string('open', 'local_notebook'), 'post');
}
echo $OUTPUT->footer();
