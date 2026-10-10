<?php
/**
 * Journal des appels au pont : les 200 derniers, filtrables par cours.
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);

admin_externalpage_setup('local_aibridge_log');
$PAGE->set_url(new moodle_url('/local/aibridge/log.php', array('courseid' => $courseid)));

$conditions = $courseid ? array('courseid' => $courseid) : array();
$rows = $DB->get_records('local_aibridge_log', $conditions, 'timecreated DESC, id DESC', '*', 0, 200);

$table = new html_table();
$table->head = array(
    get_string('log_time', 'local_aibridge'),
    get_string('log_user', 'local_aibridge'),
    get_string('log_function', 'local_aibridge'),
    get_string('log_target', 'local_aibridge'),
    get_string('log_status', 'local_aibridge'),
    get_string('log_duration', 'local_aibridge'),
    get_string('log_params', 'local_aibridge'),
);
$users = array();
foreach ($rows as $row) {
    if (!isset($users[$row->userid])) {
        $user = core_user::get_user($row->userid);
        $users[$row->userid] = $user ? fullname($user) : '#' . $row->userid;
    }
    $target = '';
    if ($row->courseid) {
        $target = html_writer::link(new moodle_url('/local/aibridge/log.php', array('courseid' => $row->courseid)),
            get_string('log_course', 'local_aibridge', $row->courseid));
    }
    if ($row->cmid) {
        $target .= ' · cm ' . (int)$row->cmid;
    }
    $status = s($row->status);
    if ($row->status !== 'ok' && $row->error) {
        $status .= html_writer::div(s($row->error), 'small text-danger');
    }
    $table->data[] = array(
        userdate($row->timecreated, get_string('strftimedatetimeshort', 'core_langconfig')),
        s($users[$row->userid]),
        s($row->function),
        $target,
        $status,
        $row->durationms . ' ms',
        html_writer::tag('code', s($row->params), array('class' => 'small')),
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('log_title', 'local_aibridge'));
if ($courseid) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/aibridge/log.php'),
        get_string('log_all', 'local_aibridge')), 'mb-3');
}
if ($rows) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('log_empty', 'local_aibridge'), 'info');
}
echo $OUTPUT->footer();
