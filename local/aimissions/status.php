<?php
/**
 * Suivi des jobs de génération de missions pour un cours.
 * Rafraîchit tant qu'au moins un job est en attente / en cours.
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$jobid    = optional_param('jobid', 0, PARAM_INT);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aimissions:generate', $context);

$PAGE->set_url(new moodle_url('/local/aimissions/status.php', array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('status_title', 'local_aimissions'));
$PAGE->set_heading(format_string($course->fullname));

// Suppression d'un job de la liste (un échec ne reste pas affiché à vie).
// On ne supprime jamais un job en cours d'exécution.
if ($action === 'deletejob' && $jobid > 0 && confirm_sesskey()) {
    $job = $DB->get_record('local_aimissions_job',
        array('id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id));
    if ($job && $job->status !== 'running') {
        $DB->delete_records('local_aimissions_job', array('id' => $jobid));
    }
    redirect($PAGE->url);
}

// Les 30 derniers jobs de l'enseignant courant sur ce cours.
$jobs = $DB->get_records_select('local_aimissions_job',
    'courseid = ? AND userid = ?', array($courseid, $USER->id),
    'timecreated DESC', '*', 0, 30);

// Rafraîchissement tant qu'un job est actif.
$active = false;
foreach ($jobs as $j) {
    if ($j->status === 'pending' || $j->status === 'running') {
        $active = true;
        break;
    }
}
if ($active) {
    $PAGE->set_periodic_refresh_delay(5);
}

$badge = array(
    'pending' => 'badge bg-secondary text-white',
    'running' => 'badge bg-info text-white',
    'done'    => 'badge bg-success text-white',
    'failed'  => 'badge bg-danger text-white',
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('status_title', 'local_aimissions'));

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/aimissions/generate.php', array('courseid' => $courseid)),
        get_string('status_newgeneration', 'local_aimissions'),
        array('class' => 'btn btn-primary')) . ' ' .
    html_writer::link(
        new moodle_url('/local/aimissions/manage.php', array('courseid' => $courseid)),
        get_string('status_managemissions', 'local_aimissions'),
        array('class' => 'btn btn-secondary')),
    'mb-3');

if (empty($jobs)) {
    echo $OUTPUT->notification(get_string('status_nojobs', 'local_aimissions'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = array(
    get_string('status_col_created', 'local_aimissions'),
    get_string('status_col_status', 'local_aimissions'),
    get_string('status_col_result', 'local_aimissions'),
    get_string('status_col_log', 'local_aimissions'),
    get_string('status_col_actions', 'local_aimissions'),
);
$table->attributes['class'] = 'generaltable';

foreach ($jobs as $j) {
    $cls   = $badge[$j->status] ?? 'badge bg-secondary';
    $label = get_string('status_' . $j->status, 'local_aimissions');
    $statuscell = html_writer::span(s($label), $cls);
    if ($j->status === 'failed' && !empty($j->lasterror)) {
        $statuscell .= html_writer::div(s($j->lasterror), 'small text-danger mt-1');
    }

    $result = '—';
    if ($j->kind === 'event') {
        $result = html_writer::span(get_string('status_eventjob', 'local_aimissions'),
            'badge bg-info text-white');
    } else if ((int)$j->resultmissionid > 0) {
        $mission = $DB->get_record('local_aimissions_mission', array('id' => (int)$j->resultmissionid));
        if ($mission && (int)$mission->assigncmid > 0) {
            $result = html_writer::link(
                new moodle_url('/mod/assign/view.php', array('id' => (int)$mission->assigncmid)),
                s($mission->title));
        }
    }

    $log = '';
    if (!empty($j->log)) {
        $log = html_writer::tag('pre', s((string)$j->log),
            array('style' => 'max-height:160px;overflow:auto;font-size:.8rem;'
                          . 'background:#f8f9fa;border:1px solid #dee2e6;padding:.5rem;'
                          . 'border-radius:.25rem;white-space:pre-wrap;margin:0;'));
    }

    $deletecell = '';
    if ($j->status !== 'running') {
        $deletecell = html_writer::link(
            new moodle_url($PAGE->url, array('action' => 'deletejob', 'jobid' => (int)$j->id,
                'sesskey' => sesskey())),
            get_string('status_deletejob', 'local_aimissions'),
            array('class' => 'btn btn-sm btn-outline-danger'));
    }

    $table->data[] = array(
        userdate((int)$j->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        $statuscell,
        $result,
        $log,
        $deletecell,
    );
}

echo html_writer::table($table);
echo $OUTPUT->footer();
