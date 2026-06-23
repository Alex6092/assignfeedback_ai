<?php
/**
 * Évaluation de la communication client d'un groupe (compétence EFE « Communiquer »).
 *
 * Flux : l'enseignant évalue (par étudiant, à partir de ses tickets) → relit les
 * couleurs/commentaires proposés → envoie vers EFE (action sortante explicite).
 */

require_once(__DIR__ . '/../../config.php');

use local_aimissions\communication;
use local_aimissions\efe_bridge;

$courseid  = required_param('courseid', PARAM_INT);
$projectid = required_param('projectid', PARAM_INT);
$action    = optional_param('action', '', PARAM_ALPHA);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aimissions:review', $context);

$project = $DB->get_record('local_aimissions_project', array('id' => $projectid), '*', MUST_EXIST);
if ((int)$project->courseid !== $courseid) {
    throw new moodle_exception('invalidrecord', 'error');
}

$baseurl = new moodle_url('/local/aimissions/comm.php',
    array('courseid' => $courseid, 'projectid' => $projectid));
$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('comm_title', 'local_aimissions'));
$PAGE->set_heading(format_string($course->fullname));

$commcode = trim((string)get_config('local_aimissions', 'communication_competency'));

// Membres du groupe du projet.
$members = array();
if ((int)$project->groupid > 0) {
    $members = groups_get_members((int)$project->groupid, 'u.*');
}

// --- Traitement -----------------------------------------------------------
if ($action === 'evaluate' && data_submitted() && confirm_sesskey() && $commcode !== '') {
    // L'évaluation enchaîne un appel LLM par étudiant : on évite le timeout.
    \core_php_time_limit::raise(120);
    $done = 0;
    foreach ($members as $m) {
        $tickets = $DB->get_records('local_aimissions_ticket',
            array('projectid' => $projectid, 'userid' => (int)$m->id), 'timecreated ASC');
        if (empty($tickets)) {
            continue;
        }
        try {
            $eval = communication::evaluate_student($project, array_values($tickets));
        } catch (\Throwable $e) {
            debugging('[local_aimissions] comm eval failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            continue;
        }
        $now = time();
        $existing = $DB->get_record('local_aimissions_commeval',
            array('projectid' => $projectid, 'userid' => (int)$m->id));
        $rec = $existing ?: new stdClass();
        $rec->projectid    = $projectid;
        $rec->userid       = (int)$m->id;
        $rec->efecode      = $commcode;
        $rec->colour       = $eval['colour'];
        $rec->score        = $eval['score'];
        $rec->comment      = $eval['comment'];
        $rec->status       = 'draft';
        $rec->timemodified = $now;
        if ($existing) {
            $DB->update_record('local_aimissions_commeval', $rec);
        } else {
            $rec->timecreated = $now;
            $DB->insert_record('local_aimissions_commeval', $rec);
        }
        $done++;
    }
    redirect($baseurl, get_string('comm_evaluated', 'local_aimissions', $done), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'send' && data_submitted() && confirm_sesskey()) {
    $evals = $DB->get_records('local_aimissions_commeval',
        array('projectid' => $projectid, 'status' => 'draft'));
    $sent = 0;
    $failed = 0;
    foreach ($evals as $ev) {
        if (empty($ev->efecode) || empty($ev->colour)) {
            continue;
        }
        $label = get_string('comm_efelabel', 'local_aimissions', format_string($project->companyname));
        $key = 'aimissions_comm_' . $projectid . '_' . (int)$ev->userid;
        $res = efe_bridge::report_competency((int)$ev->userid, $ev->efecode, $ev->colour,
            $key, $label, (int)$USER->id, $ev->comment);
        if (($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300) {
            $DB->set_field('local_aimissions_commeval', 'status', 'sent', array('id' => $ev->id));
            $DB->set_field('local_aimissions_commeval', 'timemodified', time(), array('id' => $ev->id));
            $sent++;
        } else {
            $failed++;
        }
    }
    $msg = get_string('comm_sent', 'local_aimissions', $sent);
    $type = \core\output\notification::NOTIFY_SUCCESS;
    if ($failed > 0) {
        $msg .= ' ' . get_string('comm_sendfailed', 'local_aimissions', $failed);
        $type = \core\output\notification::NOTIFY_WARNING;
    }
    redirect($baseurl, $msg, null, $type);
}

// --- Rendu ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('comm_title', 'local_aimissions'));

$groupname = (int)$project->groupid
    ? format_string((string)$DB->get_field('groups', 'name', array('id' => (int)$project->groupid)))
    : get_string('manage_nogroup', 'local_aimissions');
echo html_writer::div(
    html_writer::tag('strong', s($project->companyname)) . ' — ' . $groupname,
    'alert alert-secondary');

echo html_writer::div(get_string('comm_intro', 'local_aimissions'), 'mb-3');

if ($commcode === '') {
    echo $OUTPUT->notification(get_string('comm_nocode', 'local_aimissions'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

if (empty($members)) {
    echo $OUTPUT->notification(get_string('comm_nostudents', 'local_aimissions'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Couleur → libellé + classe badge.
$colourmap = array(
    'vert'  => array('badge bg-success',       get_string('colour_vert', 'local_aimissions')),
    'bleu'  => array('badge bg-primary',       get_string('colour_bleu', 'local_aimissions')),
    'jaune' => array('badge bg-warning text-dark', get_string('colour_jaune', 'local_aimissions')),
    'rouge' => array('badge bg-danger',        get_string('colour_rouge', 'local_aimissions')),
);

$evalbyuser = array();
foreach ($DB->get_records('local_aimissions_commeval', array('projectid' => $projectid)) as $e) {
    $evalbyuser[(int)$e->userid] = $e;
}

$table = new html_table();
$table->head = array(
    get_string('comm_col_student', 'local_aimissions'),
    get_string('comm_col_tickets', 'local_aimissions'),
    get_string('comm_col_level', 'local_aimissions'),
    get_string('comm_col_comment', 'local_aimissions'),
    get_string('comm_col_status', 'local_aimissions'),
);
$table->attributes['class'] = 'generaltable';

$hasdraft = false;
foreach ($members as $m) {
    $nbtickets = $DB->count_records('local_aimissions_ticket',
        array('projectid' => $projectid, 'userid' => (int)$m->id));
    $eval = $evalbyuser[(int)$m->id] ?? null;

    $level = '—';
    $comment = '';
    $status = '';
    if ($eval) {
        $cm = $colourmap[$eval->colour] ?? array('badge bg-secondary', s($eval->colour));
        $level = html_writer::span($cm[1], $cm[0]) . ' <span class="text-muted">(' . (int)$eval->score . ')</span>';
        $comment = s($eval->comment);
        if ($eval->status === 'sent') {
            $status = html_writer::span(get_string('comm_status_sent', 'local_aimissions'), 'badge bg-success');
        } else {
            $status = html_writer::span(get_string('comm_status_draft', 'local_aimissions'), 'badge bg-secondary text-white');
            $hasdraft = true;
        }
    }

    $table->data[] = array(
        fullname($m),
        (int)$nbtickets,
        $level,
        $comment,
        $status,
    );
}
echo html_writer::table($table);

// Boutons d'action.
echo html_writer::start_div('mt-3');

echo html_writer::start_tag('form', array('method' => 'post', 'action' => $baseurl->out(false),
    'style' => 'display:inline'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'evaluate'));
echo html_writer::tag('button', get_string('comm_evaluate', 'local_aimissions'),
    array('type' => 'submit', 'class' => 'btn btn-primary'));
echo html_writer::end_tag('form');

if ($hasdraft) {
    $cansend = efe_bridge::is_configured();
    echo ' ';
    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $baseurl->out(false),
        'style' => 'display:inline'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'send'));
    $attrs = array('type' => 'submit', 'class' => 'btn btn-outline-success');
    if (!$cansend) {
        $attrs['disabled'] = 'disabled';
    }
    echo html_writer::tag('button', get_string('comm_send', 'local_aimissions'), $attrs);
    echo html_writer::end_tag('form');
    if (!$cansend) {
        echo html_writer::div(get_string('efe_unavailable', 'local_aimissions'), 'small text-muted mt-1');
    }
}
echo html_writer::end_div();

echo html_writer::div(
    html_writer::link(new moodle_url('/local/aimissions/manage.php', array('courseid' => $courseid)),
        get_string('back', 'core')), 'mt-3');

echo $OUTPUT->footer();
