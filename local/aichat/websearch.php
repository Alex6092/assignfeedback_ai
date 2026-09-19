<?php
/**
 * Recherche Web du tuteur : état, consommation du budget et tests.
 *
 * Page d'administration. Deux tests :
 *   - « Tester l'appel d'outil » sur chaque serveur du tuteur : vérifie que
 *     LM Studio reconnaît l'appel d'outil de SON modèle en streaming (sinon il
 *     fuirait en texte brut chez l'élève). Aucun appel au moteur de recherche.
 *   - « Tester Tavily / Brave » : une vraie recherche avec la clé de TEST du
 *     site (les élèves utilisent leurs propres clés), décomptée de cette clé.
 *
 * @package local_aichat
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_aichat\websearch\budget;
use local_aichat\websearch\diagnostic;
use local_aichat\websearch\manager;
use local_aichat\websearch\userkeys;
use local_aifeedback\pool;

admin_externalpage_setup('local_aichat_websearch');

$action     = optional_param('action', '', PARAM_ALPHA);
$serverid   = optional_param('serverid', 0, PARAM_INT);
$providerid = optional_param('provider', '', PARAM_ALPHA);
$pageurl    = new moodle_url('/local/aichat/websearch.php');
if (!in_array($providerid, userkeys::PROVIDERS, true)) {
    $providerid = '';
}

if ($action === 'unblock' && $providerid !== '') {
    require_sesskey();
    budget::unblock($providerid);
    redirect($pageurl, get_string('ws_unblocked', 'local_aichat'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$testsearch = null;
$testtools  = null;
$testread   = null;
$readurl    = optional_param('readurl', '', PARAM_URL);
$readfocus  = optional_param('readfocus', '', PARAM_TEXT);
if ($action === 'testread' && $readurl !== '') {
    require_sesskey();
    $testread = diagnostic::test_read($readurl, $readfocus);
}
if ($action === 'testsearch' && $providerid !== '') {
    require_sesskey();
    $testsearch = diagnostic::test_search($providerid);
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

// --- État des moteurs -------------------------------------------------------------
echo $OUTPUT->heading(get_string('ws_state_heading', 'local_aichat'), 3);
if (!manager::site_enabled()) {
    echo $OUTPUT->notification(get_string('ws_state_disabled', 'local_aichat'),
        \core\output\notification::NOTIFY_INFO);
}
echo html_writer::tag('p', get_string('ws_state_userkeys', 'local_aichat'), array('class' => 'small text-muted'));

$table = new html_table();
$table->head = array(get_string('ws_col_engine', 'local_aichat'), get_string('ws_col_users', 'local_aichat'),
    get_string('ws_col_used', 'local_aichat'), get_string('ws_col_keycap', 'local_aichat'),
    get_string('ws_col_state', 'local_aichat'), get_string('ws_col_test', 'local_aichat'));
$table->attributes['class'] = 'table table-sm';
foreach (userkeys::PROVIDERS as $id) {
    $blocked = budget::blocked($id);
    if ($blocked !== null) {
        $until = ($blocked->until === -1) ? get_string('ws_until_manual', 'local_aichat') : userdate($blocked->until);
        $state = html_writer::span(get_string('ws_state_blocked', 'local_aichat', (object)array(
                'reason' => manager::reason_label($blocked->reason), 'until' => $until)), 'badge badge-danger')
            . ($blocked->detail !== '' ? html_writer::div(s($blocked->detail), 'small text-muted') : '')
            . html_writer::div(html_writer::link(
                new moodle_url($pageurl, array('action' => 'unblock', 'provider' => $id, 'sesskey' => sesskey())),
                get_string('ws_unblock', 'local_aichat'), array('class' => 'btn btn-sm btn-secondary mt-1')));
    } else {
        $state = html_writer::span(get_string('ws_state_inservice', 'local_aichat'), 'badge badge-success');
    }
    $cap = manager::keycap($id);
    $table->data[] = array(
        html_writer::tag('strong', manager::NAMES[$id]),
        $DB->count_records('user_preferences', array('name' => userkeys::PREF_KEY . $id)),
        budget::used($id),
        ($cap === PHP_INT_MAX) ? '-' : $cap,
        $state,
        (manager::site_key($id) === '') ? html_writer::span(get_string('ws_notestkey', 'local_aichat'), 'small text-muted')
            : $OUTPUT->single_button(new moodle_url($pageurl, array('action' => 'testsearch', 'provider' => $id)),
                get_string('ws_testsearch_engine', 'local_aichat', manager::NAMES[$id]), 'post'),
    );
}
echo html_writer::table($table);

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
echo html_writer::tag('p', get_string('ws_testsearch_explain', 'local_aichat'), array('class' => 'small text-muted'));

// --- Consommation ----------------------------------------------------------------
echo $OUTPUT->heading(get_string('ws_budget_heading', 'local_aichat'), 3);
echo html_writer::tag('p', get_string('ws_cache_hits', 'local_aichat', (object)array(
    'hits' => budget::cached_count(),
    'days' => manager::cachedays(),
)));
$top = budget::top_activities(5);
if (!empty($top)) {
    $items = array();
    foreach ($top as $cmid => $count) {
        $cm   = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        $name = $cm ? html_writer::link(new moodle_url('/local/aichat/manage.php', array('id' => $cmid)),
            format_string($cm->name)) : '#' . (int)$cmid;
        $items[] = html_writer::tag('li', $name . ' — ' . s(get_string('ws_activity_used', 'local_aichat', $count)));
    }
    echo html_writer::tag('p', get_string('ws_top_activities', 'local_aichat'), array('class' => 'mb-0'))
        . html_writer::tag('ul', implode('', $items));
}
echo html_writer::tag('p', get_string('ws_budget_explain', 'local_aichat', (object)array(
    'peruser'  => manager::peruser(),
    'hours'    => \local_aichat\quota::window_hours(),
    'maxcalls' => manager::maxcalls(),
)), array('class' => 'small text-muted'));

// --- Dernières informations des moteurs -------------------------------------------
$ratelimit = budget::last_ratelimit();
if ($ratelimit !== null) {
    $fmt = function($key, $i) use ($ratelimit) {
        return isset($ratelimit[$key][$i]) ? (string)$ratelimit[$key][$i] : '?';
    };
    echo html_writer::tag('p', get_string('ws_ratelimit', 'local_aichat', (object)array(
        'provider'  => manager::NAMES['brave'],
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
    $engine = (isset($lasterror['provider']) && isset(manager::NAMES[$lasterror['provider']]))
        ? manager::NAMES[$lasterror['provider']] : '';
    echo html_writer::tag('p', get_string('ws_lasterror', 'local_aichat', (object)array(
        'time'   => userdate((int)$lasterror['time']),
        'reason' => ($engine !== '' ? $engine . ' : ' : '') . manager::reason_label($lasterror['reason']),
        'detail' => s($lasterror['detail']),
    )), array('class' => 'small text-muted'));
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

// --- Test de la lecture d'une page (mode « recherche de matériel ») ---------
echo $OUTPUT->heading(get_string('ws_testread_heading', 'local_aichat'), 3);
echo html_writer::tag('p', get_string('ws_testread_explain', 'local_aichat'), array('class' => 'small text-muted'));
if (\local_aifeedback\content_extractor::find_pdftotext() === null) {
    echo $OUTPUT->notification(get_string('ws_testread_nopdftotext', 'local_aichat'),
        \core\output\notification::NOTIFY_WARNING);
}
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false),
    'class' => 'mb-3'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'testread'));
echo html_writer::div(
    html_writer::tag('label', get_string('ws_testread_url', 'local_aichat'), array('for' => 'aichat-readurl'))
    . html_writer::empty_tag('input', array('type' => 'url', 'name' => 'readurl', 'id' => 'aichat-readurl',
        'value' => $readurl, 'class' => 'form-control', 'required' => 'required',
        'placeholder' => 'https://www.hw-group.com/device/poseidon2-3268')), 'form-group');
echo html_writer::div(
    html_writer::tag('label', get_string('ws_testread_focus', 'local_aichat'), array('for' => 'aichat-readfocus'))
    . html_writer::empty_tag('input', array('type' => 'text', 'name' => 'readfocus', 'id' => 'aichat-readfocus',
        'value' => $readfocus, 'class' => 'form-control', 'placeholder' => 'digital input counter')), 'form-group');
echo html_writer::empty_tag('input', array('type' => 'submit', 'class' => 'btn btn-secondary',
    'value' => get_string('ws_testread', 'local_aichat')));
echo html_writer::end_tag('form');

if ($testread !== null) {
    if ($testread['ok']) {
        echo $OUTPUT->notification(get_string('ws_testread_ok', 'local_aichat'),
            \core\output\notification::NOTIFY_SUCCESS);
    } else {
        echo $OUTPUT->notification(get_string('ws_testread_failed', 'local_aichat',
            manager::reason_label($testread['reason'])), \core\output\notification::NOTIFY_ERROR);
    }
    echo html_writer::tag('p', html_writer::tag('strong', get_string('ws_testread_output', 'local_aichat')));
    echo html_writer::tag('pre', s($testread['output']), array('class' => 'small border p-2',
        'style' => 'white-space: pre-wrap; max-height: 40em; overflow: auto;'));
}

echo $OUTPUT->footer();
