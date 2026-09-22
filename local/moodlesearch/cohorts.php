<?php
/**
 * MoodleSearch — accès par cohorte.
 *
 * Chaque cohorte suit le réglage par défaut, ou est activée, ou désactivée.
 * Une cohorte désactivée l'emporte (couper une classe pendant une évaluation).
 * Le changement est immédiat.
 *
 * @package local_moodlesearch
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_moodlesearch\access;
use local_moodlesearch\opnsense_bridge;

admin_externalpage_setup('local_moodlesearch_cohorts');

$action   = optional_param('action', '', PARAM_ALPHA);
$cohortid = optional_param('cohortid', 0, PARAM_INT);
$state    = optional_param('state', '', PARAM_ALPHA);
$pageurl  = new moodle_url('/local/moodlesearch/cohorts.php');

if ($action === 'set' && $cohortid > 0 && confirm_sesskey()
        && $DB->record_exists('cohort', array('id' => $cohortid))) {
    $map = array('default' => null, 'on' => 1, 'off' => 0);
    if (array_key_exists($state, $map)) {
        access::set_cohort_state($cohortid, $map[$state], (int)$USER->id);
    }
    redirect($pageurl, get_string('cohorts_saved', 'local_moodlesearch'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cohorts_title', 'local_moodlesearch'));
if (!access::enabled()) {
    echo $OUTPUT->notification(get_string('reason_disabled', 'local_moodlesearch'), \core\output\notification::NOTIFY_WARNING);
}
echo html_writer::div(get_string('cohorts_intro', 'local_moodlesearch',
    get_string(access::default_allowed() ? 'cohorts_default_open' : 'cohorts_default_closed', 'local_moodlesearch')),
    'mb-3');

$cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber, contextid');
if (empty($cohorts)) {
    echo $OUTPUT->notification(get_string('cohorts_none', 'local_moodlesearch'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$states = access::cohort_states();
$table = new html_table();
$table->head = array(
    get_string('cohorts_col_cohort', 'local_moodlesearch'),
    get_string('cohorts_col_context', 'local_moodlesearch'),
    get_string('cohorts_col_members', 'local_moodlesearch'),
    get_string('cohorts_col_access', 'local_moodlesearch'),
    get_string('cohorts_col_actions', 'local_moodlesearch'),
);
$table->attributes['class'] = 'generaltable';

foreach ($cohorts as $cohort) {
    $current = isset($states[(int)$cohort->id]) ? ((int)$states[(int)$cohort->id]->enabled ? 'on' : 'off') : 'default';
    if ($current === 'on') {
        $badge = html_writer::span(get_string('cohorts_state_on', 'local_moodlesearch'), 'badge bg-success text-white');
    } else if ($current === 'off') {
        $badge = html_writer::span(get_string('cohorts_state_off', 'local_moodlesearch'), 'badge bg-danger text-white');
    } else {
        $badge = html_writer::span(get_string('cohorts_state_default', 'local_moodlesearch',
            get_string(access::default_allowed() ? 'cohorts_default_open' : 'cohorts_default_closed', 'local_moodlesearch')),
            'badge bg-secondary text-white');
    }
    if (opnsense_bridge::exam_active_for_cohort((int)$cohort->id)) {
        $badge .= ' ' . html_writer::span(get_string('cohorts_exam', 'local_moodlesearch'), 'badge bg-warning text-dark');
    }

    $actions = array();
    foreach (array('on', 'off', 'default') as $target) {
        if ($target === $current) {
            continue;
        }
        $actions[] = html_writer::link(new moodle_url($pageurl, array('action' => 'set',
            'cohortid' => (int)$cohort->id, 'state' => $target, 'sesskey' => sesskey())),
            get_string('cohorts_set_' . $target, 'local_moodlesearch'),
            array('class' => 'btn btn-sm ' . ($target === 'off' ? 'btn-outline-danger' : 'btn-outline-secondary')));
    }

    $context = context::instance_by_id((int)$cohort->contextid, IGNORE_MISSING);
    $table->data[] = array(
        format_string($cohort->name) . ($cohort->idnumber !== '' ? html_writer::span(' (' . s($cohort->idnumber) . ')',
            'text-muted small') : ''),
        $context ? $context->get_context_name(false) : '—',
        $DB->count_records('cohort_members', array('cohortid' => (int)$cohort->id)),
        $badge,
        implode(' ', $actions),
    );
}
echo html_writer::table($table);
echo $OUTPUT->footer();
