<?php
/**
 * Recherche Web du tuteur : état, consommation du budget et tests.
 *
 * Page d'administration. Deux tests :
 *   - « Tester l'appel d'outil » sur chaque serveur du tuteur : vérifie que
 *     LM Studio reconnaît l'appel d'outil de SON modèle en streaming (sinon il
 *     fuirait en texte brut chez l'élève). Aucun appel au moteur de recherche.
 *   - « Tester la recherche » : une vraie recherche, décomptée du budget.
 *
 * @package local_aichat
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_aichat\websearch\budget;
use local_aichat\websearch\diagnostic;
use local_aichat\websearch\manager;
use local_aifeedback\pool;

admin_externalpage_setup('local_aichat_websearch');

$action   = optional_param('action', '', PARAM_ALPHA);
$serverid = optional_param('serverid', 0, PARAM_INT);
$pageurl  = new moodle_url('/local/aichat/websearch.php');

if ($action === 'unblock') {
    require_sesskey();
    budget::unblock();
    redirect($pageurl, get_string('ws_unblocked', 'local_aichat'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$testsearch = null;
$testtools  = null;
if ($action === 'testsearch') {
    require_sesskey();
    $testsearch = diagnostic::test_search();
}
if ($action === 'testtools') {
    require_sesskey();
    $server = pool::server($serverid);
    if ($server !== null) {
        core_php_time_limit::raise(400);
        $testtools = array('server' => $server, 'report' => diagnostic::test_tool_calling($server));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ws_page', 'local_aichat'));
echo html_writer::tag('p', get_string('ws_page_intro', 'local_aichat'), array('class' => 'text-muted'));

$provider = manager::provider();

// --- État --------------------------------------------------------------------
echo $OUTPUT->heading(get_string('ws_state_heading', 'local_aichat'), 3);
if (!manager::site_enabled()) {
    echo $OUTPUT->notification(get_string('ws_state_disabled', 'local_aichat'),
        \core\output\notification::NOTIFY_INFO);
}
if (!$provider->is_configured()) {
    echo $OUTPUT->notification(get_string('ws_state_nokey', 'local_aichat', $provider->name()),
        \core\output\notification::NOTIFY_WARNING);
}
$blocked = budget::blocked();
if ($blocked !== null) {
    $until = ($blocked->until === -1) ? get_string('ws_until_manual', 'local_aichat')
        : userdate($blocked->until);
    echo $OUTPUT->notification(get_string('ws_state_blocked', 'local_aichat', (object)array(
            'reason' => manager::reason_label($blocked->reason),
            'until'  => $until,
        )) . ($blocked->detail !== '' ? html_writer::div(s($blocked->detail), 'small') : '')
        . html_writer::div(html_writer::link(
            new moodle_url($pageurl, array('action' => 'unblock', 'sesskey' => sesskey())),
            get_string('ws_unblock', 'local_aichat'), array('class' => 'btn btn-sm btn-secondary mt-2'))),
        \core\output\notification::NOTIFY_ERROR);
} else if (manager::site_enabled() && $provider->is_configured()) {
    echo $OUTPUT->notification(get_string('ws_state_ok', 'local_aichat', $provider->name()),
        \core\output\notification::NOTIFY_SUCCESS);
}

// --- Budget ------------------------------------------------------------------
echo $OUTPUT->heading(get_string('ws_budget_heading', 'local_aichat'), 3);
$cap  = manager::cap();
$used = budget::used();
echo html_writer::tag('p', html_writer::tag('strong',
    get_string('ws_budget_used', 'local_aichat', (object)array('used' => $used, 'cap' => $cap))));
if ($cap > 0 && $used >= $cap) {
    $release = budget::next_release();
    echo $OUTPUT->notification(get_string('ws_budget_full', 'local_aichat',
        $release ? userdate($release) : '-'), \core\output\notification::NOTIFY_WARNING);
}
echo html_writer::tag('p', get_string('ws_cache_hits', 'local_aichat', (object)array(
    'hits' => budget::cached_count(),
    'days' => manager::cachedays(),
)));
echo html_writer::tag('p', get_string('ws_budget_explain', 'local_aichat', (object)array(
    'peruser'  => manager::peruser(),
    'hours'    => \local_aichat\quota::window_hours(),
    'maxcalls' => manager::maxcalls(),
)), array('class' => 'small text-muted'));

// --- Fournisseur ---------------------------------------------------------------
$ratelimit = budget::last_ratelimit();
if ($ratelimit !== null) {
    $fmt = function($key, $i) use ($ratelimit) {
        return isset($ratelimit[$key][$i]) ? (string)$ratelimit[$key][$i] : '?';
    };
    echo html_writer::tag('p', get_string('ws_ratelimit', 'local_aichat', (object)array(
        'provider'  => $provider->name(),
        'persecond' => $fmt('limit', 0),
        'permonth'  => $fmt('limit', 1),
        'remaining' => $fmt('remaining', 1),
        'reset'     => isset($ratelimit['reset'][1]) ? format_time((int)$ratelimit['reset'][1]) : '?',
        'time'      => userdate((int)$ratelimit['time']),
    )), array('class' => 'small'));
    echo html_writer::tag('p', get_string('ws_ratelimit_note', 'local_aichat'),
        array('class' => 'small text-muted'));
}
$lasterror = budget::last_error();
if ($lasterror !== null) {
    echo html_writer::tag('p', get_string('ws_lasterror', 'local_aichat', (object)array(
        'time'   => userdate((int)$lasterror['time']),
        'reason' => manager::reason_label($lasterror['reason']),
        'detail' => s($lasterror['detail']),
    )), array('class' => 'small text-muted'));
}

// --- Test de la recherche ------------------------------------------------------
echo $OUTPUT->heading(get_string('ws_testsearch_heading', 'local_aichat'), 3);
echo html_writer::tag('p', get_string('ws_testsearch_explain', 'local_aichat'),
    array('class' => 'small text-muted'));
echo $OUTPUT->single_button(new moodle_url($pageurl, array('action' => 'testsearch')),
    get_string('ws_testsearch', 'local_aichat'), 'post');
if ($testsearch !== null) {
    if ($testsearch->ok) {
        $items = array();
        foreach ($testsearch->items as $item) {
            $items[] = html_writer::tag('li', s($item['title']) . ' — '
                . html_writer::tag('code', s($item['url'])));
        }
        echo $OUTPUT->notification(get_string('ws_testsearch_ok', 'local_aichat', count($testsearch->items))
            . html_writer::tag('ul', implode('', $items)), \core\output\notification::NOTIFY_SUCCESS);
    } else {
        echo $OUTPUT->notification(get_string('ws_testsearch_failed', 'local_aichat',
            manager::reason_label($testsearch->reason))
            . ($testsearch->detail !== '' ? html_writer::div(s($testsearch->detail), 'small') : ''),
            \core\output\notification::NOTIFY_ERROR);
    }
}

// --- Test de l'appel d'outil, serveur par serveur ------------------------------
echo $OUTPUT->heading(get_string('ws_testtools_heading', 'local_aichat'), 3);
echo html_writer::tag('p', get_string('ws_testtools_explain', 'local_aichat'),
    array('class' => 'small text-muted'));
$table = new html_table();
$table->head = array('#', get_string('ws_col_server', 'local_aichat'), '');
$table->attributes['class'] = 'table table-sm';
foreach (pool::servers() as $id => $server) {
    if (empty($server['use_tutor'])) {
        continue;
    }
    $table->data[] = array(
        (int)$id,
        html_writer::tag('div', s($server['apiurl']))
            . html_writer::tag('div', s($server['model'] !== '' ? $server['model']
                : get_string('ws_globalmodel', 'local_aichat')), array('class' => 'small text-muted')),
        $OUTPUT->single_button(new moodle_url($pageurl, array('action' => 'testtools', 'serverid' => (int)$id)),
            get_string('ws_testtools', 'local_aichat'), 'post'),
    );
}
if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('ws_notutorserver', 'local_aichat'),
        \core\output\notification::NOTIFY_WARNING);
} else {
    echo html_writer::table($table);
}

if ($testtools !== null) {
    $report = $testtools['report'];
    $rows   = array();
    $allok  = true;
    foreach ($report['checks'] as $check) {
        if ($check['ok'] === true) {
            $badge = html_writer::span(get_string('ws_check_ok', 'local_aichat'), 'badge badge-success');
        } else if ($check['ok'] === false) {
            $badge = html_writer::span(get_string('ws_check_failed', 'local_aichat'), 'badge badge-danger');
            $allok = false;
        } else {
            $badge = html_writer::span(get_string('ws_check_info', 'local_aichat'), 'badge badge-secondary');
        }
        $rows[] = array($badge, get_string($check['label'], 'local_aichat'),
            html_writer::tag('code', s($check['detail'])));
    }
    $result = new html_table();
    $result->data = $rows;
    $result->attributes['class'] = 'table table-sm';

    echo $OUTPUT->heading(get_string('ws_testtools_result', 'local_aichat', (object)array(
        'id'      => (int)$testtools['server']['id'],
        'seconds' => $report['seconds'],
    )), 4);
    echo html_writer::table($result);
    echo $OUTPUT->notification(get_string($allok ? 'ws_testtools_verdict_ok' : 'ws_testtools_verdict_bad',
        'local_aichat'), $allok ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR);
    foreach (array('answer1' => 'ws_answer1', 'answer2' => 'ws_answer2') as $key => $label) {
        if (trim($report[$key]) !== '') {
            echo html_writer::tag('p', html_writer::tag('strong', get_string($label, 'local_aichat')));
            echo html_writer::tag('pre', s($report[$key]), array('class' => 'small border p-2'));
        }
    }
}

echo $OUTPUT->footer();
