<?php
/**
 * MoodleSearch — recherches et sites ouverts.
 *
 * Avec courseid : les élèves inscrits au cours, pour ses enseignants
 * (local/moodlesearch:viewreport). Sans : tout le site, pour les
 * gestionnaires (local/moodlesearch:viewsitereport).
 *
 * @package local_moodlesearch
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');

use local_moodlesearch\report;

$courseid = optional_param('courseid', 0, PARAM_INT);
$userid   = optional_param('userid', 0, PARAM_INT);
$days     = optional_param('days', 30, PARAM_INT);
$page     = optional_param('page', 0, PARAM_INT);
$perpage  = 50;
$days     = in_array($days, report::PERIODS, true) ? $days : 30;

if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/moodlesearch:viewreport', $context);
    $PAGE->set_url(new moodle_url('/local/moodlesearch/report.php', array('courseid' => $courseid)));
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('report');
    $PAGE->set_title(get_string('report_title', 'local_moodlesearch'));
    $PAGE->set_heading(format_string($course->fullname));
    $students = report::course_students($context);
    $userids = ($userid > 0 && isset($students[$userid])) ? array($userid) : array_keys($students);
    $heading = get_string('report_title', 'local_moodlesearch');
} else {
    admin_externalpage_setup('local_moodlesearch_sitereport');
    $students = array();
    $userids = ($userid > 0) ? array($userid) : null;
    $heading = get_string('report_sitetitle', 'local_moodlesearch');
}

$baseurl = new moodle_url('/local/moodlesearch/report.php',
    array('courseid' => $courseid, 'userid' => $userid, 'days' => $days));
$data = report::searches($userids, $days, $page, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo html_writer::div(get_string('report_intro', 'local_moodlesearch'), 'mb-3');

// --- Filtres (GET) ----------------------------------------------------------
echo html_writer::start_tag('form', array('method' => 'get', 'action' => $baseurl->out_omit_querystring(),
    'class' => 'form-inline mb-3 d-flex flex-wrap gap-2'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'courseid', 'value' => $courseid));
if (!empty($students)) {
    echo html_writer::select(array(0 => get_string('report_allstudents', 'local_moodlesearch')) + $students,
        'userid', $userid, false, array('class' => 'custom-select form-select'));
} else if ($userid > 0) {
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'userid', 'value' => $userid));
}
$periods = array();
foreach (report::PERIODS as $d) {
    $periods[$d] = ($d > 0) ? get_string('report_days', 'local_moodlesearch', $d) : get_string('report_alltime', 'local_moodlesearch');
}
echo html_writer::select($periods, 'days', $days, false, array('class' => 'custom-select form-select'));
echo html_writer::tag('button', get_string('report_filter', 'local_moodlesearch'),
    array('type' => 'submit', 'class' => 'btn btn-secondary'));
if ($userid > 0) {
    echo html_writer::link(new moodle_url($baseurl, array('userid' => 0)), get_string('report_allstudents', 'local_moodlesearch'),
        array('class' => 'btn btn-link'));
}
echo html_writer::end_tag('form');

if ($data['total'] === 0) {
    echo $OUTPUT->notification(get_string('report_none', 'local_moodlesearch'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

// Noms des élèves des lignes affichées.
$names = $students;
$missing = array_diff(array_unique(array_map(function($r) {
    return (int)$r->userid;
}, $data['rows'])), array_keys($names));
if (!empty($missing)) {
    foreach (user_get_users_by_id($missing) as $u) {
        $names[(int)$u->id] = fullname($u);
    }
}

$table = new html_table();
$table->head = array(
    get_string('report_col_time', 'local_moodlesearch'),
    get_string('report_col_student', 'local_moodlesearch'),
    get_string('report_col_query', 'local_moodlesearch'),
    get_string('report_col_results', 'local_moodlesearch'),
    get_string('report_col_opened', 'local_moodlesearch'),
);
$table->attributes['class'] = 'generaltable moodlesearch-report';

foreach ($data['rows'] as $row) {
    $name = $names[(int)$row->userid] ?? ('#' . (int)$row->userid);
    $student = html_writer::link(new moodle_url($baseurl, array('userid' => (int)$row->userid, 'page' => 0)), s($name));

    $query = s($row->query);
    $tags = array();
    if ($row->tab === 'news') {
        $tags[] = get_string('tab_news', 'local_moodlesearch');
    }
    if ($row->period !== 'any') {
        $tags[] = get_string('period_' . $row->period, 'local_moodlesearch');
    }
    if (!empty($tags)) {
        $query .= ' ' . html_writer::span(s(implode(' · ', $tags)), 'badge bg-light text-dark');
    }
    if ($row->status === 'refused' || $row->status === 'error') {
        $query .= ' ' . html_writer::span(get_string('report_status_' . $row->status, 'local_moodlesearch')
            . ($row->reason ? ' : ' . s($row->reason) : ''), 'badge bg-warning text-dark');
    }

    $opened = array();
    foreach ($row->clicks as $click) {
        $status = ($click->opnstatus !== '') ? $click->opnstatus : 'waiting';
        $label = get_string_manager()->string_exists('click_' . $status, 'local_moodlesearch')
            ? get_string('click_' . $status, 'local_moodlesearch') : $status;
        $opened[] = html_writer::link($click->url, s($click->domain), array('rel' => 'noreferrer', 'target' => '_blank'))
            . ' ' . html_writer::span(s($label), 'badge bg-secondary text-white')
            . ' ' . html_writer::span(userdate($click->timecreated, get_string('strftimetime', 'langconfig')), 'text-muted small');
    }

    $table->data[] = array(
        userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        $student,
        $query,
        (int)$row->resultcount,
        empty($opened) ? '—' : implode(html_writer::empty_tag('br'), $opened),
    );
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($data['total'], $page, $perpage, $baseurl);
echo $OUTPUT->footer();
