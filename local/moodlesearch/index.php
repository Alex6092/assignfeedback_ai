<?php
/**
 * MoodleSearch — page de recherche : des résultats, et rien d'autre.
 *
 * GET q, tab (web|news), period (any|day|week|month|year), more : l'adresse de
 * la page décrit la recherche, qu'on peut donc partager ou retrouver.
 *
 * @package local_moodlesearch
 */

require_once(__DIR__ . '/../../config.php');

use local_moodlesearch\access;
use local_moodlesearch\opnsense_bridge;
use local_moodlesearch\output;
use local_moodlesearch\searcher;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception(get_string('guest', 'local_moodlesearch'));
}

$q      = optional_param('q', '', PARAM_RAW_TRIMMED);
$tab    = optional_param('tab', searcher::TAB_WEB, PARAM_ALPHA);
$period = optional_param('period', 'any', PARAM_ALPHA);
$more   = (bool)optional_param('more', 0, PARAM_BOOL);
$tab    = in_array($tab, searcher::TABS, true) ? $tab : searcher::TAB_WEB;
$period = in_array($period, searcher::PERIODS, true) ? $period : 'any';

$params = array();
if ($q !== '') {
    $params = array('q' => $q, 'tab' => $tab, 'period' => $period);
}
$PAGE->set_url(new moodle_url('/local/moodlesearch/index.php', $params));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(($q !== '' ? $q . ' — ' : '') . get_string('pluginname', 'local_moodlesearch'));
$PAGE->set_heading(get_string('pluginname', 'local_moodlesearch'));

$reason = access::reason((int)$USER->id);

echo $OUTPUT->header();
echo html_writer::start_div('moodlesearch');

// MoodleSearch fermé pour cette personne : on explique pourquoi, sans formulaire.
if (in_array($reason, array(access::DISABLED, access::CAPABILITY, access::COHORT, access::EXAM), true)) {
    echo $OUTPUT->notification(output::reason_message($reason), \core\output\notification::NOTIFY_INFO);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo output::search_form($q, $tab, $period);
echo html_writer::div(get_string('notice_monitoring', 'local_moodlesearch'), 'moodlesearch-notice');

if ($reason === access::NOKEY) {
    echo $OUTPUT->notification(output::reason_message($reason), \core\output\notification::NOTIFY_INFO);
} else if ($q !== '') {
    echo output::tabs($q, $tab, $period);
    $res = searcher::search((int)$USER->id, $q, $tab, $period, $more);
    $blocked = $res->ok ? opnsense_bridge::blocked((int)$USER->id, array_column($res->items, 'url')) : array();
    echo output::results($res, $blocked, $q, $tab, $period, $more);
    if ($res->ok) {
        echo output::footer(searcher::key_usage((int)$USER->id));
    }
}

echo output::recent(searcher::recent((int)$USER->id));
echo html_writer::end_div();
echo $OUTPUT->footer();
