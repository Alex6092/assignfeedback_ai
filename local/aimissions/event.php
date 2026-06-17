<?php
/**
 * Injection d'un événement client (besoin / bug / RGPD / budget) sur un ou
 * tous les projets d'un cours. Chaque cible donne lieu à un job kind=event
 * (génération asynchrone d'une communication client, en attente de publication).
 */

require_once(__DIR__ . '/../../config.php');

use local_aimissions\job_handler;

$courseid = required_param('courseid', PARAM_INT);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aimissions:generate', $context);

$PAGE->set_url(new moodle_url('/local/aimissions/event.php', array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('event_title', 'local_aimissions'));
$PAGE->set_heading(format_string($course->fullname));

// Projets du cours (cibles possibles).
$projects = $DB->get_records('local_aimissions_project', array('courseid' => $courseid), 'companyname ASC');

$types = array(
    'besoin' => get_string('event_besoin', 'local_aimissions'),
    'bug'    => get_string('event_bug', 'local_aimissions'),
    'rgpd'   => get_string('event_rgpd', 'local_aimissions'),
    'budget' => get_string('event_budget', 'local_aimissions'),
);

// --- Traitement ----------------------------------------------------------
if (($data = data_submitted()) && confirm_sesskey() && !empty($projects)) {
    $eventtype = isset($data->eventtype) ? (string)$data->eventtype : 'besoin';
    if (!isset($types[$eventtype])) {
        $eventtype = 'besoin';
    }
    $hint   = isset($data->hint) ? \core_text::substr(trim(clean_param($data->hint, PARAM_TEXT)), 0, 500) : '';
    $target = isset($data->target) ? (string)$data->target : 'all';

    $targets = array();
    if ($target === 'all') {
        $targets = array_keys($projects);
    } else if (ctype_digit($target) && isset($projects[(int)$target])) {
        $targets = array((int)$target);
    }

    $now = time();
    $created = 0;
    foreach ($targets as $pid) {
        $params = array('projectid' => (int)$pid, 'eventtype' => $eventtype, 'hint' => $hint);

        $job = new stdClass();
        $job->courseid        = $courseid;
        $job->userid          = $USER->id;
        $job->projectid       = (int)$pid;
        $job->kind            = 'event';
        $job->params          = json_encode($params, JSON_UNESCAPED_UNICODE);
        $job->status          = 'pending';
        $job->log             = '';
        $job->lasterror       = null;
        $job->resultmissionid = 0;
        $job->attempts        = 0;
        $job->timecreated     = $now;
        $job->timemodified    = $now;
        $job->id = $DB->insert_record('local_aimissions_job', $job);

        job_handler::enqueue($job->id);
        $created++;
    }

    redirect(
        new moodle_url('/local/aimissions/status.php', array('courseid' => $courseid)),
        get_string('jobs_queued', 'local_aimissions', $created),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// --- Rendu ---------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('event_title', 'local_aimissions'));

if (empty($projects)) {
    echo $OUTPUT->notification(get_string('event_noprojects', 'local_aimissions'), 'info');
    echo html_writer::link(
        new moodle_url('/local/aimissions/status.php', array('courseid' => $courseid)),
        get_string('back', 'core'), array('class' => 'btn btn-link'));
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(get_string('event_intro', 'local_aimissions'), 'lead mb-3');

// Options de cible.
$targetoptions = array('all' => get_string('event_target_all', 'local_aimissions'));
foreach ($projects as $p) {
    $gname = $p->groupid
        ? format_string((string)$DB->get_field('groups', 'name', array('id' => (int)$p->groupid)))
        : get_string('manage_nogroup', 'local_aimissions');
    $targetoptions[(string)$p->id] = format_string($p->companyname) . ' — ' . $gname;
}

echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url->out(false)));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

echo html_writer::start_div('mb-3');
echo html_writer::tag('label', get_string('event_type', 'local_aimissions'),
    array('for' => 'eventtype', 'class' => 'form-label fw-bold'));
echo html_writer::select($types, 'eventtype', 'besoin', false,
    array('id' => 'eventtype', 'class' => 'form-select'));
echo html_writer::end_div();

echo html_writer::start_div('mb-3');
echo html_writer::tag('label', get_string('event_target', 'local_aimissions'),
    array('for' => 'target', 'class' => 'form-label fw-bold'));
echo html_writer::select($targetoptions, 'target', 'all', false,
    array('id' => 'target', 'class' => 'form-select'));
echo html_writer::end_div();

echo html_writer::start_div('mb-3');
echo html_writer::tag('label', get_string('event_hint', 'local_aimissions'),
    array('for' => 'hint', 'class' => 'form-label fw-bold'));
echo html_writer::tag('textarea', '', array('id' => 'hint', 'name' => 'hint',
    'rows' => 2, 'class' => 'form-control', 'maxlength' => 500,
    'placeholder' => get_string('event_hint_ph', 'local_aimissions')));
echo html_writer::end_div();

echo html_writer::tag('button', get_string('event_submit', 'local_aimissions'),
    array('type' => 'submit', 'class' => 'btn btn-primary'));
echo ' ' . html_writer::link(
    new moodle_url('/local/aimissions/manage.php', array('courseid' => $courseid)),
    get_string('cancel', 'core'), array('class' => 'btn btn-link'));
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
