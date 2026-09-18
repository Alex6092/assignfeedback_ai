<?php
/**
 * État des serveurs LLM du pool : occupation, quarantaine, activité récente.
 *
 * Page d'administration : elle permet de VOIR la répartition de charge et le
 * basculement à l'œuvre, et de remettre en service un serveur mis en
 * quarantaine après une panne (sans attendre la fin du délai).
 *
 * @package local_aifeedback
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_aifeedback\pool;

admin_externalpage_setup('local_aifeedback_servers');

$action   = optional_param('action', '', PARAM_ALPHA);
$serverid = optional_param('serverid', 0, PARAM_INT);
$pageurl  = new moodle_url('/local/aifeedback/servers.php');

if ($action === 'clear' && $serverid > 0) {
    require_sesskey();
    pool::clear_server_failure($serverid);
    redirect($pageurl, get_string('servers_cleared', 'local_aifeedback', $serverid), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Actualisation automatique : la page sert à suivre la file en direct.
$PAGE->set_periodic_refresh_delay(15);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('servers_page', 'local_aifeedback'));
echo html_writer::tag('p', get_string('servers_intro', 'local_aifeedback'),
    array('class' => 'text-muted'));

if (!pool::feedback_enabled()) {
    echo $OUTPUT->notification(get_string('servers_legacy', 'local_aifeedback'),
        \core\output\notification::NOTIFY_WARNING);
}

$status = pool::server_status();
$now    = time();

// --- Tableau des serveurs --------------------------------------------------
$table = new html_table();
$table->head = array(
    '#',
    get_string('servers_col_endpoint', 'local_aifeedback'),
    get_string('servers_col_usages', 'local_aifeedback'),
    get_string('servers_col_busy', 'local_aifeedback'),
    get_string('servers_col_state', 'local_aifeedback'),
    get_string('servers_col_lasthour', 'local_aifeedback'),
    get_string('servers_col_lastused', 'local_aifeedback'),
);
$table->attributes['class'] = 'table table-sm table-striped';

$feedbackcapacity = 0;
foreach ($status as $id => $s) {
    $usages = array();
    if (!empty($s['use_feedback'])) {
        $usages[] = get_string('servers_use_feedback', 'local_aifeedback');
        $feedbackcapacity += (int)$s['maxconcurrency'];
    }
    if (!empty($s['use_tutor'])) {
        $usages[] = get_string('servers_use_tutor', 'local_aifeedback');
    }

    if ((int)$s['failinguntil'] > $now) {
        $state = html_writer::span(get_string('servers_quarantine', 'local_aifeedback',
                userdate((int)$s['failinguntil'], get_string('strftimetime', 'langconfig'))),
                'badge badge-danger mr-2')
            . html_writer::link(new moodle_url($pageurl, array(
                    'action' => 'clear', 'serverid' => (int)$id, 'sesskey' => sesskey())),
                get_string('servers_clear', 'local_aifeedback'),
                array('class' => 'btn btn-sm btn-outline-secondary'));
    } else {
        $state = html_writer::span(get_string('servers_inservice', 'local_aifeedback'),
            'badge badge-success');
    }
    if ((int)$s['failures'] > 0) {
        $state .= html_writer::div(get_string('servers_failures', 'local_aifeedback',
            (int)$s['failures']), 'small text-muted');
    }

    $busy = (int)$s['busy'] . ' / ' . (int)$s['maxconcurrency'];
    if ((int)$s['busy'] >= (int)$s['maxconcurrency']) {
        $busy = html_writer::span($busy, 'badge badge-warning');
    }

    $table->data[] = array(
        (int)$id,
        html_writer::tag('div', s($s['apiurl']))
            . html_writer::tag('div', s($s['model'] !== '' ? $s['model']
                : get_string('servers_defaultmodel', 'local_aifeedback')), array('class' => 'small text-muted')),
        empty($usages) ? '-' : implode(', ', $usages),
        $busy,
        $state,
        get_string('servers_lasthour_value', 'local_aifeedback', (object)array(
            'done' => (int)$s['done1h'], 'failed' => (int)$s['failed1h'])),
        ((int)$s['lastused'] > 0) ? userdate((int)$s['lastused'])
            : get_string('servers_never', 'local_aifeedback'),
    );
}
echo html_writer::table($table);

// --- Files d'attente -------------------------------------------------------
echo html_writer::tag('p', get_string('servers_queues', 'local_aifeedback', (object)array(
    'tutor'    => pool::queue_length(pool::PURPOSE_TUTOR),
    'feedback' => pool::queue_length(pool::PURPOSE_FEEDBACK),
)));

// --- Avertissements de configuration ---------------------------------------
if ($feedbackcapacity === 0) {
    echo $OUTPUT->notification(get_string('servers_nofeedback', 'local_aifeedback'),
        \core\output\notification::NOTIFY_ERROR);
}

// Moodle ne lance jamais plus de processus de tâches de fond que cette
// limite : au-delà, des places de serveur resteraient inutilisées.
$limit = get_config('core', 'task_adhoc_concurrency_limit');
$limit = ($limit === false || $limit === '') ? 3 : (int)$limit;
if ($feedbackcapacity > $limit) {
    echo $OUTPUT->notification(get_string('servers_concurrency', 'local_aifeedback',
        (object)array('limit' => $limit, 'capacity' => $feedbackcapacity)),
        \core\output\notification::NOTIFY_WARNING);
}

echo html_writer::tag('p', get_string('servers_modeltip', 'local_aifeedback'),
    array('class' => 'small text-muted'));

echo $OUTPUT->footer();
