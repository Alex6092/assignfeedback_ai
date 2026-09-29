<?php
/**
 * Page enseignant « Demandes d'accès » : accepter ou refuser les demandes
 * d'accès exceptionnel des élèves, voir et retirer les accès en cours, et
 * accorder un accès sans demande.
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');

use local_classhours\access;
use local_classhours\availability_json;
use local_classhours\grant;
use local_classhours\schedule;

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/classhours:grantaccess', $context);

$pageurl = new moodle_url('/local/classhours/requests.php', array('courseid' => $courseid));
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('requests_heading', 'local_classhours'));
$PAGE->set_heading(format_string($course->fullname));

$modinfo = get_fast_modinfo($course);
$sched = schedule::for_course($courseid);
$durations = grant::duration_options();
$success = \core\output\notification::NOTIFY_SUCCESS;
$warning = \core\output\notification::NOTIFY_WARNING;

/** Ligne de la table, vérifiée comme appartenant à ce cours. */
$loadrow = function(int $id) use ($DB, $courseid) {
    return $DB->get_record(grant::TABLE, array('id' => $id, 'courseid' => $courseid), '*', MUST_EXIST);
};

// ---------------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------------
if ($action !== '') {
    require_sesskey();
    $duration = optional_param('duration', grant::DEFAULT_DURATION, PARAM_INT);
    if (!array_key_exists($duration, $durations)) {
        $duration = grant::DEFAULT_DURATION;
    }
    $comment = optional_param('comment', '', PARAM_TEXT);

    if ($action === 'accept') {
        $row = $loadrow(required_param('id', PARAM_INT));
        $ok = grant::accept((int)$row->id, $duration, (int)$USER->id, $comment);
        redirect($pageurl, get_string($ok ? 'requests_accepted' : 'requests_alreadydecided', 'local_classhours'),
            null, $ok ? $success : $warning);
    }
    if ($action === 'refuse') {
        $row = $loadrow(required_param('id', PARAM_INT));
        $ok = grant::refuse((int)$row->id, (int)$USER->id, $comment);
        redirect($pageurl, get_string($ok ? 'requests_refused' : 'requests_alreadydecided', 'local_classhours'),
            null, $ok ? $success : $warning);
    }
    if ($action === 'revoke') {
        $row = $loadrow(required_param('id', PARAM_INT));
        grant::revoke((int)$row->id, (int)$USER->id);
        redirect($pageurl, get_string('requests_revoked', 'local_classhours'), null, $success);
    }
    if ($action === 'grant') {
        $cmid = required_param('cmid', PARAM_INT);
        $userid = required_param('userid', PARAM_INT);
        $cms = $modinfo->get_cms();
        if (!isset($cms[$cmid]) || !in_array($cms[$cmid]->modname, access::READ_MODS, true)
                || !is_enrolled($context, $userid, 'local/classhours:requestaccess', true)) {
            throw new moodle_exception('invalidparameter');
        }
        grant::grant_direct($cms[$cmid], $userid, $duration, (int)$USER->id);
        redirect($pageurl, get_string('requests_granted', 'local_classhours'), null, $success);
    }
    redirect($pageurl);
}

// ---------------------------------------------------------------------------
//  Données
// ---------------------------------------------------------------------------
$now = schedule::now();
$pending = $DB->get_records(grant::TABLE, array('courseid' => $courseid, 'status' => grant::PENDING), 'requestedat');
$active = $DB->get_records_select(grant::TABLE,
    'courseid = :courseid AND status = :accepted AND timeend > :now',
    array('courseid' => $courseid, 'accepted' => grant::ACCEPTED, 'now' => $now), 'timeend');
$history = $DB->get_records_select(grant::TABLE,
    'courseid = :courseid AND status <> :pending AND NOT (status = :accepted AND timeend > :now)',
    array('courseid' => $courseid, 'pending' => grant::PENDING, 'accepted' => grant::ACCEPTED, 'now' => $now),
    'decidedat DESC', '*', 0, 30);

$userids = array();
foreach (array($pending, $active, $history) as $rows) {
    foreach ($rows as $row) {
        $userids[(int)$row->userid] = true;
        if (!empty($row->decidedby)) {
            $userids[(int)$row->decidedby] = true;
        }
    }
}
$users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : array();
$username = function(int $id) use ($users) {
    return isset($users[$id]) ? fullname($users[$id]) : '—';
};
$cmname = function(int $cmid) use ($modinfo) {
    $cms = $modinfo->get_cms();
    return isset($cms[$cmid]) ? format_string($cms[$cmid]->name) : get_string('deletedactivity', 'local_classhours');
};

/** Petit formulaire POST d'une action. */
$actionform = function(string $action, array $hidden, string $inner, string $label, string $btnclass)
        use ($courseid) {
    $fields = html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()))
        . html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'courseid', 'value' => $courseid))
        . html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => $action));
    foreach ($hidden as $name => $value) {
        $fields .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => $name, 'value' => $value));
    }
    return html_writer::tag('form',
        $fields . $inner . html_writer::tag('button', $label, array('type' => 'submit', 'class' => 'btn btn-sm ' . $btnclass)),
        array('method' => 'post', 'action' => (new moodle_url('/local/classhours/requests.php'))->out(false),
            'class' => 'd-inline-flex align-items-center gap-1 me-2 mb-1'));
};
$durationselect = html_writer::select($durations, 'duration', grant::DEFAULT_DURATION, false,
    array('class' => 'form-select form-select-sm w-auto'));
$commentinput = html_writer::empty_tag('input', array('type' => 'text', 'name' => 'comment', 'size' => 24,
    'maxlength' => grant::MAXTEXT, 'class' => 'form-control form-control-sm',
    'placeholder' => get_string('requests_comment', 'local_classhours')));

// ---------------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('requests_heading', 'local_classhours'));
echo html_writer::tag('p', get_string('requests_intro', 'local_classhours'));

// Demandes en attente.
echo $OUTPUT->heading(get_string('requests_pending', 'local_classhours'), 3);
if (!$pending) {
    echo html_writer::tag('p', get_string('requests_nopending', 'local_classhours'), array('class' => 'text-muted'));
} else {
    $table = new html_table();
    $table->head = array(get_string('student', 'local_classhours'), get_string('activity', 'local_classhours'),
        get_string('requestedat', 'local_classhours'), get_string('request_reason', 'local_classhours'),
        get_string('decision', 'local_classhours'));
    foreach ($pending as $row) {
        $table->data[] = array(
            $username((int)$row->userid),
            $cmname((int)$row->cmid),
            $sched->format_time((int)$row->requestedat),
            trim((string)$row->reason) !== '' ? s($row->reason) : html_writer::span('—', 'text-muted'),
            $actionform('accept', array('id' => $row->id), $durationselect,
                get_string('accept', 'local_classhours'), 'btn-primary')
            . $actionform('refuse', array('id' => $row->id), $commentinput,
                get_string('refuse', 'local_classhours'), 'btn-outline-danger'),
        );
    }
    echo html_writer::table($table);
}

// Accès en cours.
echo $OUTPUT->heading(get_string('requests_active', 'local_classhours'), 3);
if (!$active) {
    echo html_writer::tag('p', get_string('requests_noactive', 'local_classhours'), array('class' => 'text-muted'));
} else {
    $table = new html_table();
    $table->head = array(get_string('student', 'local_classhours'), get_string('activity', 'local_classhours'),
        get_string('until', 'local_classhours'), '');
    foreach ($active as $row) {
        $table->data[] = array(
            $username((int)$row->userid),
            $cmname((int)$row->cmid),
            $sched->format_time((int)$row->timeend),
            $actionform('revoke', array('id' => $row->id), '', get_string('revoke', 'local_classhours'),
                'btn-outline-secondary'),
        );
    }
    echo html_writer::table($table);
}

// Accorder un accès sans demande.
echo $OUTPUT->heading(get_string('requests_grant', 'local_classhours'), 3);
$activities = array();
foreach ($modinfo->get_cms() as $cm) {
    if (in_array($cm->modname, access::READ_MODS, true) && !$cm->deletioninprogress
            && availability_json::has_root_condition($cm->availability)) {
        $activities[$cm->id] = format_string($cm->name);
    }
}
$students = array();
foreach (get_enrolled_users($context, 'local/classhours:requestaccess', 0, 'u.*', 'u.lastname, u.firstname',
        0, 0, true) as $student) {
    $students[$student->id] = fullname($student);
}
if (!$activities || !$students) {
    echo html_writer::tag('p', get_string('requests_grant_none', 'local_classhours'), array('class' => 'text-muted'));
} else {
    echo $actionform('grant', array(),
        html_writer::select($students, 'userid', '', false, array('class' => 'form-select form-select-sm w-auto'))
        . html_writer::select($activities, 'cmid', '', false, array('class' => 'form-select form-select-sm w-auto'))
        . $durationselect,
        get_string('requests_grant_submit', 'local_classhours'), 'btn-primary');
}

// Historique.
echo $OUTPUT->heading(get_string('requests_history', 'local_classhours'), 3);
if (!$history) {
    echo html_writer::tag('p', get_string('requests_nohistory', 'local_classhours'), array('class' => 'text-muted'));
} else {
    $table = new html_table();
    $table->head = array(get_string('student', 'local_classhours'), get_string('activity', 'local_classhours'),
        get_string('status', 'local_classhours'), get_string('decidedby', 'local_classhours'),
        get_string('decidedat', 'local_classhours'), get_string('requests_comment', 'local_classhours'));
    foreach ($history as $row) {
        $table->data[] = array(
            $username((int)$row->userid),
            $cmname((int)$row->cmid),
            get_string('grantstatus_' . $row->status, 'local_classhours'),
            $username((int)$row->decidedby),
            $row->decidedat ? $sched->format_time((int)$row->decidedat) : '—',
            s((string)$row->decisioncomment),
        );
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
