<?php
/**
 * État du serveur IA : ce que le load balancer d'inférence expose par son API
 * (santé, capacité et occupation par modèle, catalogue, consommation et
 * quotas), et les jobs Moodle en cours.
 *
 * La répartition entre serveurs IA est faite par le load balancer : le
 * détail par serveur (nom, adresse, tentatives incertaines) se consulte dans
 * sa propre administration, vers laquelle la page renvoie.
 *
 * @package local_aifeedback
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_aifeedback\jobs;
use local_aifeedback\models;
use local_aifeedback\lb\client;
use local_aifeedback\lb\lb_exception;

admin_externalpage_setup('local_aifeedback_servers');

$action  = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/local/aifeedback/servers.php');

if ($action === 'reconcile') {
    require_sesskey();
    core_php_time_limit::raise(300);
    jobs::reconcile(false);
    redirect($pageurl, get_string('servers_reconciled', 'local_aifeedback'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Actualisation automatique : la page sert à suivre l'occupation en direct.
$PAGE->set_periodic_refresh_delay(15);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('servers_page', 'local_aifeedback'));
echo html_writer::tag('p', get_string('servers_intro', 'local_aifeedback'), array('class' => 'text-muted'));

/**
 * Badge Bootstrap.
 */
function local_aifeedback_badge($text, $type) {
    return html_writer::span(s($text), 'badge badge-' . $type . ' bg-' . $type . ' mr-1 me-1');
}

/**
 * Barre d'occupation (places occupées / capacité).
 */
function local_aifeedback_bar($used, $capacity) {
    $pct = ($capacity > 0) ? (int)round(100 * min($used, $capacity) / $capacity) : 0;
    $type = ($pct >= 100) ? 'danger' : (($pct >= 70) ? 'warning' : 'success');
    return html_writer::div(
        html_writer::div('', 'progress-bar bg-' . $type, array('role' => 'progressbar',
            'style' => 'width: ' . $pct . '%', 'aria-valuenow' => $pct,
            'aria-valuemin' => 0, 'aria-valuemax' => 100)),
        'progress', array('style' => 'height: 0.6rem; min-width: 8rem;'));
}

/**
 * Nombre formaté (séparateur de milliers).
 */
function local_aifeedback_num($n) {
    return number_format((int)$n, 0, ',', ' ');
}

if (!client::configured()) {
    echo $OUTPUT->notification(get_string('servers_notconfigured', 'local_aifeedback',
        (new moodle_url('/admin/settings.php', array('section' => 'local_aifeedback')))->out()),
        \core\output\notification::NOTIFY_WARNING);
} else {
    $configured = models::configured();

    // --- Santé et authentification ------------------------------------------
    echo $OUTPUT->heading(get_string('servers_health', 'local_aifeedback'), 3);
    $health  = client::health();
    $catalog = null;
    $autherr = null;
    try {
        $catalog = client::models(true);
    } catch (lb_exception $e) {
        $autherr = $e;
    }
    $lines = array();
    if ($health['ok']) {
        $lines[] = local_aifeedback_badge(get_string('servers_reachable', 'local_aifeedback'), 'success')
            . ($health['release'] !== '' ? ' ' . html_writer::span(
                get_string('servers_release', 'local_aifeedback', s($health['release'])), 'text-muted small') : '');
    } else {
        $lines[] = local_aifeedback_badge(get_string('servers_unreachable', 'local_aifeedback'), 'danger')
            . ' ' . html_writer::span(s($health['error']), 'text-muted small');
    }
    if ($autherr === null) {
        $lines[] = local_aifeedback_badge(get_string('servers_authok', 'local_aifeedback'), 'success');
    } else {
        if ($autherr->httpstatus === 401) {
            $why = get_string('servers_auth401', 'local_aifeedback');
        } else if ($autherr->httpstatus === 403) {
            $why = ($autherr->lbcode === 'ip_forbidden')
                ? get_string('servers_auth403ip', 'local_aifeedback')
                : get_string('servers_auth403', 'local_aifeedback');
        } else {
            $why = $autherr->getMessage();
        }
        $lines[] = local_aifeedback_badge(get_string('servers_autherror', 'local_aifeedback'), 'danger') . ' ' . s($why);
    }
    $lines[] = html_writer::link(client::admin_url(), get_string('servers_lbadmin', 'local_aifeedback'),
        array('target' => '_blank', 'rel' => 'noopener'));
    echo html_writer::alist($lines, array('class' => 'list-unstyled'));

    // --- Capacité et occupation par modèle ------------------------------------
    echo $OUTPUT->heading(get_string('servers_capacity', 'local_aifeedback'), 3);
    try {
        $status = client::workers_status();
        $table  = new html_table();
        $table->head = array(
            get_string('servers_col_model', 'local_aifeedback'),
            get_string('servers_col_usages', 'local_aifeedback'),
            get_string('servers_col_workers', 'local_aifeedback'),
            get_string('servers_col_busy', 'local_aifeedback'),
            '',
        );
        $table->attributes['class'] = 'table table-sm table-striped';
        $seen = array();
        foreach ((isset($status['data']) && is_array($status['data'])) ? $status['data'] : array() as $m) {
            $model = (string)$m['model'];
            $seen[$model] = true;
            $usages = isset($configured[$model]) ? $configured[$model] : array();
            $state  = '';
            if (!empty($usages) && (int)$m['available_workers'] === 0) {
                $state = local_aifeedback_badge(get_string('servers_noworker', 'local_aifeedback'), 'danger');
            }
            $table->data[] = array(
                html_writer::tag('code', s($model)),
                empty($usages) ? html_writer::span('—', 'text-muted')
                    : implode(' ', array_map(function($u) {
                        return local_aifeedback_badge($u, 'info');
                    }, array_unique($usages))),
                get_string('servers_workers_value', 'local_aifeedback', (object)array(
                    'available' => (int)$m['available_workers'], 'total' => (int)$m['workers'])) . ' ' . $state,
                get_string('servers_busy_value', 'local_aifeedback', (object)array(
                    'occupied' => (int)$m['occupied'], 'capacity' => (int)$m['capacity'])),
                local_aifeedback_bar((int)$m['occupied'], (int)$m['capacity']),
            );
        }
        echo html_writer::table($table);
        echo html_writer::tag('p', get_string('servers_capacity_note', 'local_aifeedback'),
            array('class' => 'small text-muted'));
    } catch (lb_exception $e) {
        echo $OUTPUT->notification(s($e->getMessage()), \core\output\notification::NOTIFY_ERROR);
    }

    // --- Catalogue des modèles -------------------------------------------------
    echo $OUTPUT->heading(get_string('servers_catalog', 'local_aifeedback'), 3);
    if ($catalog !== null) {
        $missing = array_diff_key($configured, $catalog);
        foreach ($missing as $model => $usages) {
            echo $OUTPUT->notification(get_string('servers_modelmissing', 'local_aifeedback', (object)array(
                'model'  => s($model),
                'usages' => s(implode(', ', array_unique($usages))),
            )), \core\output\notification::NOTIFY_ERROR);
        }
        $table = new html_table();
        $table->head = array(
            get_string('servers_col_model', 'local_aifeedback'),
            get_string('servers_col_vision', 'local_aifeedback'),
            get_string('servers_col_context', 'local_aifeedback'),
            get_string('servers_col_output', 'local_aifeedback'),
        );
        $table->attributes['class'] = 'table table-sm table-striped';
        foreach ($catalog as $model) {
            $table->data[] = array(
                html_writer::tag('code', s($model['id'])),
                $model['vision'] ? get_string('yes') : get_string('no'),
                local_aifeedback_num($model['context_tokens']),
                local_aifeedback_num($model['max_output_tokens']),
            );
        }
        echo html_writer::table($table);
    }

    // --- Consommation et quotas -----------------------------------------------
    echo $OUTPUT->heading(get_string('servers_usage', 'local_aifeedback'), 3);
    try {
        $now   = time();
        $day   = client::usage($now - DAYSECS, 'model');
        $week  = client::usage($now - 7 * DAYSECS, 'model');
        $table = new html_table();
        $table->head = array(
            get_string('servers_col_model', 'local_aifeedback'),
            get_string('servers_col_tokens24h', 'local_aifeedback'),
            get_string('servers_col_tokens7d', 'local_aifeedback'),
            get_string('servers_col_attempts7d', 'local_aifeedback'),
        );
        $table->attributes['class'] = 'table table-sm table-striped';
        $byday = array();
        foreach ((array)$day['data'] as $r) {
            $byday[(string)$r['dimension']] = (int)$r['prompt_tokens'] + (int)$r['completion_tokens'];
        }
        foreach ((array)$week['data'] as $r) {
            $model = (string)$r['dimension'];
            $table->data[] = array(
                html_writer::tag('code', s($model)),
                local_aifeedback_num(isset($byday[$model]) ? $byday[$model] : 0),
                get_string('servers_tokens_value', 'local_aifeedback', (object)array(
                    'total'      => local_aifeedback_num((int)$r['prompt_tokens'] + (int)$r['completion_tokens']),
                    'prompt'     => local_aifeedback_num($r['prompt_tokens']),
                    'completion' => local_aifeedback_num($r['completion_tokens']),
                )),
                local_aifeedback_num($r['attempts']),
            );
        }
        if (empty($table->data)) {
            echo html_writer::tag('p', get_string('servers_nousage', 'local_aifeedback'), array('class' => 'text-muted'));
        } else {
            echo html_writer::table($table);
        }

        // Budgets restants (application et clé ; les quotas par élève se
        // consultent dans l'administration du load balancer).
        $quotas = array();
        foreach ((isset($week['quotas']) && is_array($week['quotas'])) ? $week['quotas'] : array() as $q) {
            $scope = get_string('servers_scope_' . (($q['scope'] === 'key') ? 'key' : 'application'), 'local_aifeedback');
            if (!empty($q['credit'])) {
                $quotas[] = get_string('servers_quota_credit', 'local_aifeedback', (object)array(
                    'scope'     => $scope,
                    'available' => local_aifeedback_num($q['credit']['available']),
                    'limit'     => local_aifeedback_num($q['credit']['limit']),
                ));
            }
            foreach ((isset($q['windows']) && is_array($q['windows'])) ? $q['windows'] : array() as $w) {
                $quotas[] = get_string('servers_quota_window', 'local_aifeedback', (object)array(
                    'scope'     => $scope,
                    'hours'     => (int)$w['hours'],
                    'available' => local_aifeedback_num($w['available']),
                    'limit'     => local_aifeedback_num($w['limit']),
                ));
            }
        }
        echo html_writer::tag('p', empty($quotas)
            ? get_string('servers_noquota', 'local_aifeedback')
            : get_string('servers_quotas', 'local_aifeedback') . ' ' . implode(' · ', $quotas),
            array('class' => 'small'));
    } catch (lb_exception $e) {
        echo $OUTPUT->notification(s($e->getMessage()), \core\output\notification::NOTIFY_ERROR);
    }
}

// --- Côté Moodle : jobs soumis au load balancer ------------------------------------
echo $OUTPUT->heading(get_string('servers_moodle', 'local_aifeedback'), 3);

$stats = jobs::stats(time() - DAYSECS);
$statuses = array('submitting', 'submitted', 'received', 'processing', 'done', 'failed', 'cancelled');
$table = new html_table();
$table->head = array(get_string('servers_col_component', 'local_aifeedback'));
foreach ($statuses as $st) {
    $table->head[] = get_string('jobstatus_' . $st, 'local_aifeedback');
}
$table->attributes['class'] = 'table table-sm table-striped';
foreach ($stats as $component => $counts) {
    $row = array(s(get_string('pluginname', $component)));
    foreach ($statuses as $st) {
        $row[] = isset($counts[$st]) ? (int)$counts[$st] : '';
    }
    $table->data[] = $row;
}
if (empty($table->data)) {
    echo html_writer::tag('p', get_string('servers_nojobs', 'local_aifeedback'), array('class' => 'text-muted'));
} else {
    echo html_writer::table($table);
}
$tokens = jobs::token_totals(time() - DAYSECS);
echo html_writer::tag('p', get_string('servers_moodletokens', 'local_aifeedback', (object)array(
    'prompt'     => local_aifeedback_num($tokens->prompt),
    'completion' => local_aifeedback_num($tokens->completion),
)), array('class' => 'small text-muted'));

$active = jobs::oldest_active(10);
if (!empty($active)) {
    echo $OUTPUT->heading(get_string('servers_active', 'local_aifeedback'), 4);
    $table = new html_table();
    $table->head = array(
        get_string('servers_col_component', 'local_aifeedback'),
        get_string('servers_col_state', 'local_aifeedback'),
        get_string('servers_col_model', 'local_aifeedback'),
        get_string('servers_col_lbjob', 'local_aifeedback'),
        get_string('servers_col_since', 'local_aifeedback'),
    );
    $table->attributes['class'] = 'table table-sm';
    foreach ($active as $j) {
        $table->data[] = array(
            s($j->component),
            get_string('jobstatus_' . $j->status, 'local_aifeedback')
                . ((int)$j->attempt > 1 ? ' (' . get_string('servers_attempt', 'local_aifeedback', (int)$j->attempt) . ')' : ''),
            html_writer::tag('code', s($j->model)),
            html_writer::tag('code', s($j->lbjobid)),
            format_time(time() - (int)$j->timecreated),
        );
    }
    echo html_writer::table($table);
}

$errors = jobs::recent_errors(10);
if (!empty($errors)) {
    echo $OUTPUT->heading(get_string('servers_errors', 'local_aifeedback'), 4);
    $table = new html_table();
    $table->head = array(
        get_string('servers_col_when', 'local_aifeedback'),
        get_string('servers_col_component', 'local_aifeedback'),
        get_string('servers_col_state', 'local_aifeedback'),
        get_string('servers_col_error', 'local_aifeedback'),
    );
    $table->attributes['class'] = 'table table-sm';
    foreach ($errors as $e) {
        $table->data[] = array(
            userdate((int)$e->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
            s($e->component),
            get_string('jobstatus_' . $e->status, 'local_aifeedback'),
            html_writer::span(s(shorten_text((string)$e->error, 300)), 'small'),
        );
    }
    echo html_writer::table($table);
}

echo $OUTPUT->single_button(new moodle_url($pageurl, array('action' => 'reconcile')),
    get_string('servers_reconcile', 'local_aifeedback'), 'post');
echo html_writer::tag('p', get_string('servers_reconcile_help', 'local_aifeedback'),
    array('class' => 'small text-muted mt-2'));

echo $OUTPUT->footer();
