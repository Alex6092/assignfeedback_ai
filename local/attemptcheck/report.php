<?php
/**
 * Contrôle des tentatives d'un cours : tentatives de test et remises de
 * devoir à examiner (hors créneau, trop rapides, texte collé...), décision
 * « légitime » et suppression.
 *
 * @package local_attemptcheck
 */

require_once(__DIR__ . '/../../config.php');

use local_attemptcheck\analyser;
use local_attemptcheck\checker;
use local_attemptcheck\collector;
use local_attemptcheck\logs;
use local_attemptcheck\offslot;
use local_attemptcheck\presenter;
use local_attemptcheck\remover;
use local_attemptcheck\review;

$courseid  = required_param('courseid', PARAM_INT);
$cmid      = optional_param('cmid', 0, PARAM_INT);
$signal    = optional_param('signal', '', PARAM_ALPHA);        // '' = signalées, 'all' = toutes, ou un code
$scope     = optional_param('scope', 'restricted', PARAM_ALPHA); // hors créneau : restricted | all
$showlegit = optional_param('showlegit', 0, PARAM_BOOL);
$action    = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/attemptcheck:view', $context);

$activities = collector::activities($course);
if ($cmid && !isset($activities[$cmid])) {
    $cmid = 0;
}
if ($signal !== '' && $signal !== 'all' && !in_array($signal, analyser::CODES, true)) {
    $signal = '';
}
$scope = ($scope === 'all') ? 'all' : 'restricted';

$filters = array('courseid' => $courseid);
if ($cmid) {
    $filters['cmid'] = $cmid;
}
if ($signal !== '') {
    $filters['signal'] = $signal;
}
if ($scope !== 'restricted') {
    $filters['scope'] = $scope;
}
if ($showlegit) {
    $filters['showlegit'] = 1;
}
$pageurl = new moodle_url('/local/attemptcheck/report.php', $filters);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('menu', 'local_attemptcheck'));
$PAGE->set_heading(format_string($course->fullname));

$canbulkdelete = has_capability('local/attemptcheck:delete', $context);
$success = \core\output\notification::NOTIFY_SUCCESS;

/**
 * Tentatives désignées par la requête (« quiz:12 », « assign:34 »).
 *
 * @return array[] [type, id]
 */
$selected = function() {
    $keys = optional_param_array('keys', array(), PARAM_RAW);
    $single = optional_param('key', '', PARAM_RAW);
    if ($single !== '') {
        $keys[] = $single;
    }
    $out = array();
    foreach ($keys as $key) {
        if (preg_match('~^(quiz|assign):(\d+)$~', (string)$key, $m)) {
            $out[$m[1] . ':' . $m[2]] = array($m[1], (int)$m[2]);
        }
    }
    return array_values($out);
};

// ---------------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------------
if ($action === 'bulk') {
    require_sesskey();
    $bulk = optional_param('bulk', '', PARAM_ALPHA);
    $action = ($bulk === 'delete') ? 'confirmdelete' : (($bulk === 'legit') ? 'legit' : '');
}

if ($action === 'legit' || $action === 'unlegit') {
    require_sesskey();
    $count = 0;
    foreach ($selected() as list($type, $id)) {
        $item = remover::describe($type, $id, $courseid);
        if ($item) {
            review::set_legit($courseid, $item->cmid, $type, $id, $item->userid, $action === 'legit');
            $count++;
        }
    }
    redirect($pageurl, get_string($action === 'legit' ? 'legit_done' : 'unlegit_done', 'local_attemptcheck', $count),
        null, $success);
}

if ($action === 'setminduration' && $cmid) {
    require_sesskey();
    review::set_minduration($cmid, $courseid, max(0, optional_param('minutes', 0, PARAM_INT)) * MINSECS);
    redirect($pageurl, get_string('minduration_saved', 'local_attemptcheck'), null, $success);
}

if ($action === 'delete') {
    require_sesskey();
    $done = 0;
    $failed = 0;
    foreach ($selected() as list($type, $id)) {
        try {
            remover::delete($type, $id, $courseid);
            $done++;
        } catch (\Throwable $e) {
            $failed++;
            debugging('local_attemptcheck : suppression de ' . $type . ':' . $id . ' — ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }
    $message = get_string('delete_done', 'local_attemptcheck', $done);
    if ($failed) {
        $message .= ' ' . get_string('delete_failed', 'local_attemptcheck', $failed);
    }
    redirect($pageurl, $message, null, $failed ? \core\output\notification::NOTIFY_WARNING : $success);
}

$datetime = function(int $t) {
    return $t > 0 ? userdate($t, get_string('strftimedatetimeshort', 'langconfig')) : '–';
};
$studentnames = function(array $userids) {
    global $DB;
    $names = array();
    if ($userids) {
        foreach ($DB->get_records_list('user', 'id', array_values(array_unique($userids))) as $user) {
            $names[(int)$user->id] = fullname($user);
        }
    }
    return $names;
};

// ---------------------------------------------------------------------------
//  Confirmation de suppression
// ---------------------------------------------------------------------------
if ($action === 'confirmdelete') {
    $items = array();
    foreach ($selected() as list($type, $id)) {
        $item = remover::describe($type, $id, $courseid);
        if ($item) {
            $items[] = $item;
        }
    }
    if (!$items) {
        redirect($pageurl, get_string('nothing_selected', 'local_attemptcheck'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    $names = $studentnames(array_map(function($i) {
        return $i->userid;
    }, $items));

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirm_heading', 'local_attemptcheck'));
    echo $OUTPUT->notification(get_string('confirm_warning', 'local_attemptcheck'), 'warning');

    $table = new html_table();
    $table->head = array(get_string('student', 'local_attemptcheck'), get_string('activity'),
        get_string('attempt', 'local_attemptcheck'), get_string('timestart', 'local_attemptcheck'),
        get_string('timeend', 'local_attemptcheck'), '');
    $table->attributes['class'] = 'generaltable';
    $deletable = 0;
    foreach ($items as $item) {
        $table->data[] = array(
            s($names[$item->userid] ?? '?'),
            $item->activity . ' (' . get_string('type_' . $item->type, 'local_attemptcheck') . ')',
            $item->attempt,
            $datetime($item->timestart),
            $datetime($item->timeend),
            $item->candelete ? '' : html_writer::span(get_string('cannotdelete', 'local_attemptcheck'), 'text-danger'),
        );
        if ($item->candelete) {
            $deletable++;
        }
    }
    echo html_writer::table($table);

    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false)));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'delete'));
    foreach ($items as $item) {
        if ($item->candelete) {
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'keys[]', 'value' => $item->key));
        }
    }
    if ($deletable) {
        echo html_writer::empty_tag('input', array('type' => 'submit', 'class' => 'btn btn-danger me-2',
            'value' => get_string('confirm_delete', 'local_attemptcheck', $deletable)));
    }
    echo html_writer::link($pageurl, get_string('cancel'), array('class' => 'btn btn-secondary'));
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
//  Rapport
// ---------------------------------------------------------------------------
$items = checker::run($course, $cmid, $scope !== 'all');
$legit = review::legit($courseid);

$rows = array();
foreach ($items as $item) {
    $codes = array_column($item->signals, 'code');
    $item->legit = isset($legit[$item->key]);
    if ($item->legit && !$showlegit) {
        continue;
    }
    if ($signal === '' && !$codes) {
        continue;
    }
    if ($signal !== '' && $signal !== 'all' && !in_array($signal, $codes, true)) {
        continue;
    }
    $rows[] = $item;
}
$names = $studentnames(array_map(function($i) {
    return $i->userid;
}, $rows));
$order = array_flip(array_keys($activities));
usort($rows, function($a, $b) use ($order, $names) {
    return ($order[$a->cmid] <=> $order[$b->cmid])
        ?: strcmp(core_text::strtolower($names[$a->userid] ?? ''), core_text::strtolower($names[$b->userid] ?? ''))
        ?: ($a->attempt <=> $b->attempt);
});

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('menu', 'local_attemptcheck'));
echo html_writer::tag('p', get_string('intro', 'local_attemptcheck'), array('class' => 'alert alert-info'));
if (!offslot::has_schedule($courseid)) {
    echo html_writer::tag('p', get_string('info_noschedule', 'local_attemptcheck'), array('class' => 'text-muted'));
}
if (logs::table() === null) {
    echo html_writer::tag('p', get_string('info_nologs', 'local_attemptcheck'), array('class' => 'text-muted'));
}

// Filtres.
$activityoptions = array(0 => get_string('allactivities', 'local_attemptcheck'));
foreach ($activities as $id => $cm) {
    $activityoptions[$id] = $cm->get_formatted_name() . ' (' . get_string('type_' . $cm->modname, 'local_attemptcheck') . ')';
}
$signaloptions = array('' => get_string('filter_flagged', 'local_attemptcheck'),
    'all' => get_string('filter_all', 'local_attemptcheck'));
foreach (analyser::CODES as $code) {
    $signaloptions[$code] = presenter::label($code);
}
echo html_writer::start_tag('form', array('method' => 'get', 'action' => (new moodle_url('/local/attemptcheck/report.php'))->out(false),
    'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'courseid', 'value' => $courseid));
echo html_writer::select($activityoptions, 'cmid', $cmid, false, array('class' => 'form-select w-auto'));
echo html_writer::select($signaloptions, 'signal', $signal, false, array('class' => 'form-select w-auto'));
echo html_writer::select(array(
    'restricted' => get_string('scope_restricted', 'local_attemptcheck'),
    'all'        => get_string('scope_all', 'local_attemptcheck'),
), 'scope', $scope, false, array('class' => 'form-select w-auto'));
echo html_writer::start_div('form-check');
echo html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'showlegit', 'value' => 1,
    'id' => 'attemptcheck-showlegit', 'class' => 'form-check-input') + ($showlegit ? array('checked' => 'checked') : array()));
echo html_writer::tag('label', get_string('showlegit', 'local_attemptcheck'),
    array('for' => 'attemptcheck-showlegit', 'class' => 'form-check-label'));
echo html_writer::end_div();
echo html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('filter', 'local_attemptcheck'),
    'class' => 'btn btn-secondary'));
echo html_writer::end_tag('form');

// Durée minimale attendue de l'activité choisie.
if ($cmid) {
    $minutes = intdiv(review::get_minduration($cmid), MINSECS);
    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false),
        'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3 border rounded p-2'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'setminduration'));
    echo html_writer::tag('label', get_string('minduration_label', 'local_attemptcheck'),
        array('for' => 'attemptcheck-minutes', 'class' => 'mb-0'));
    echo html_writer::empty_tag('input', array('type' => 'number', 'min' => 0, 'max' => 1440, 'name' => 'minutes',
        'id' => 'attemptcheck-minutes', 'value' => $minutes, 'class' => 'form-control w-auto'));
    echo html_writer::span(get_string('minutes'));
    echo html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('savechanges'),
        'class' => 'btn btn-secondary btn-sm'));
    echo html_writer::tag('small', get_string('minduration_help', 'local_attemptcheck'), array('class' => 'text-muted w-100'));
    echo html_writer::end_tag('form');
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('nothing_found', 'local_attemptcheck'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Tableau (formulaire des actions groupées).
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false)));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'bulk'));

$master = new \core\output\checkbox_toggleall('attemptcheck', true, array(
    'id' => 'attemptcheck-all', 'name' => 'attemptcheck-all', 'label' => get_string('selectall'),
    'labelclasses' => 'accesshide'));
$table = new html_table();
$table->head = array($OUTPUT->render($master), get_string('student', 'local_attemptcheck'), get_string('activity'),
    get_string('attempt', 'local_attemptcheck'), get_string('timestart', 'local_attemptcheck'),
    get_string('timeend', 'local_attemptcheck'), get_string('duration', 'local_attemptcheck'),
    get_string('signals', 'local_attemptcheck'), '');
$table->attributes['class'] = 'generaltable';

$candelete = array();
foreach ($rows as $item) {
    if (!isset($candelete[$item->cmid])) {
        $candelete[$item->cmid] = remover::can_delete($item->type, $item->cmid, $courseid);
    }
    $checkbox = new \core\output\checkbox_toggleall('attemptcheck', false, array(
        'id' => 'attemptcheck-' . $item->type . '-' . $item->id, 'name' => 'keys[]', 'value' => $item->key,
        'label' => get_string('select'), 'labelclasses' => 'accesshide'));

    if ($item->type === 'assign') {
        $state = get_string('submissionstatus_submitted', 'assign');
    } else if (get_string_manager()->string_exists('state' . $item->state, 'quiz')) {
        $state = get_string('state' . $item->state, 'quiz');
    } else {
        $state = s($item->state);
    }

    $signals = '';
    foreach ($item->signals as $sig) {
        $signals .= html_writer::div(
            html_writer::span(presenter::label($sig['code']), 'badge bg-warning text-dark me-1')
            . html_writer::span(s(presenter::text($sig, $courseid)), 'small'), 'mb-1');
    }
    if ($item->legit) {
        $signals .= html_writer::span(get_string('legit', 'local_attemptcheck'), 'badge bg-success text-white');
    }

    $view = ($item->type === 'quiz')
        ? new moodle_url('/mod/quiz/review.php', array('attempt' => $item->id))
        : new moodle_url('/mod/assign/view.php', array('id' => $item->cmid, 'action' => 'grader', 'userid' => $item->userid));
    $actions = array(html_writer::link($view, get_string('view')));
    $actions[] = html_writer::link(new moodle_url($pageurl, array(
        'action' => $item->legit ? 'unlegit' : 'legit', 'key' => $item->key, 'sesskey' => sesskey())),
        get_string($item->legit ? 'action_unlegit' : 'action_legit', 'local_attemptcheck'));
    if ($candelete[$item->cmid]) {
        $actions[] = html_writer::link(new moodle_url($pageurl, array('action' => 'confirmdelete', 'key' => $item->key)),
            get_string('delete'), array('class' => 'text-danger'));
    }

    $row = new html_table_row(array(
        $OUTPUT->render($checkbox),
        html_writer::link(new moodle_url('/user/view.php', array('id' => $item->userid, 'course' => $courseid)),
            s($names[$item->userid] ?? '?')),
        html_writer::link($item->cm->url ?? new moodle_url('/mod/' . $item->type . '/view.php', array('id' => $item->cmid)),
            $item->cm->get_formatted_name()),
        get_string('attempt_number', 'local_attemptcheck', $item->attempt) . html_writer::empty_tag('br')
            . html_writer::span($state, 'small text-muted'),
        $datetime($item->timestart),
        $datetime($item->timeend),
        $item->duration !== null ? presenter::duration((int)$item->duration) : '–',
        $signals,
        implode(html_writer::empty_tag('br'), $actions),
    ));
    if ($item->legit) {
        $row->attributes['class'] = 'dimmed_text';
    }
    $table->data[] = $row;
}
echo html_writer::tag('p', get_string('count_shown', 'local_attemptcheck', count($rows)), array('class' => 'text-muted'));
echo html_writer::table($table);

echo html_writer::start_div('d-flex gap-2');
echo html_writer::tag('button', get_string('bulk_legit', 'local_attemptcheck'),
    array('type' => 'submit', 'name' => 'bulk', 'value' => 'legit', 'class' => 'btn btn-secondary'));
if ($canbulkdelete) {
    echo html_writer::tag('button', get_string('bulk_delete', 'local_attemptcheck'),
        array('type' => 'submit', 'name' => 'bulk', 'value' => 'delete', 'class' => 'btn btn-danger'));
}
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
