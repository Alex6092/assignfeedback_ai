<?php
/**
 * MoodleSearch — clic sur un résultat.
 *
 * Le lien ne porte que le numéro de la recherche et le rang du résultat :
 * l'URL est relue dans le journal de l'utilisateur (pas de redirection
 * ouverte). Le clic est enregistré ; pour un élève d'une classe gérée par
 * OPNsense, une page d'attente demande l'ouverture du site pour sa classe
 * (sauf liste noire), puis y conduit. Sinon, redirection immédiate.
 *
 * @package local_moodlesearch
 */

require_once(__DIR__ . '/../../config.php');

use local_moodlesearch\clicks;
use local_moodlesearch\opnsense_bridge;

require_login(null, false);
require_sesskey();

$searchid = required_param('search', PARAM_INT);
$rank     = required_param('rank', PARAM_INT);

$result = clicks::result_of((int)$USER->id, $searchid, $rank);
if ($result === null) {
    redirect(new moodle_url('/local/moodlesearch/index.php'), get_string('click_notfound', 'local_moodlesearch'),
        null, \core\output\notification::NOTIFY_ERROR);
}
$clickid = clicks::record((int)$USER->id, $searchid, $rank, $result);

if (!opnsense_bridge::concerned((int)$USER->id)) {
    clicks::set_status($clickid, opnsense_bridge::NONE);
    redirect(new moodle_url($result['url']));
}

// --- Page d'attente : ouverture du site pour la classe ------------------------
$PAGE->set_url(new moodle_url('/local/moodlesearch/go.php', array('search' => $searchid, 'rank' => $rank)));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('open_title', 'local_moodlesearch', $result['domain']));
$PAGE->set_heading(get_string('pluginname', 'local_moodlesearch'));

$params = array(
    'ajaxurl'      => (new moodle_url('/local/moodlesearch/ajax_open.php'))->out(false),
    'clickid'      => $clickid,
    'sesskey'      => sesskey(),
    'url'          => $result['url'],
    'errormessage' => get_string('open_error', 'local_moodlesearch'),
);
$version = (int)get_config('local_moodlesearch', 'version');
$PAGE->requires->js(new moodle_url('/local/moodlesearch/js/open.js', array('v' => $version)), false);
$PAGE->requires->js_init_code('window.localMoodlesearchOpen(' . json_encode($params) . ');');

echo $OUTPUT->header();
echo html_writer::start_div('moodlesearch moodlesearch-open', array('id' => 'moodlesearch-open'));
echo $OUTPUT->heading(s($result['title'] !== '' ? $result['title'] : $result['domain']), 3);
echo html_writer::div(
    $OUTPUT->pix_icon('i/loading', '') . ' ' . get_string('open_wait', 'local_moodlesearch', s($result['domain'])),
    'moodlesearch-open-wait');
echo html_writer::div('', 'moodlesearch-open-message', array('hidden' => 'hidden', 'role' => 'status'));
echo html_writer::start_div('moodlesearch-open-actions');
echo html_writer::link($result['url'], get_string('open_link', 'local_moodlesearch', s($result['domain'])),
    array('class' => 'btn btn-primary moodlesearch-open-link', 'hidden' => 'hidden', 'rel' => 'noreferrer'));
echo ' ' . html_writer::link('#', get_string('open_declaremac', 'local_moodlesearch'),
    array('class' => 'btn btn-outline-secondary moodlesearch-open-mac', 'hidden' => 'hidden'));
echo ' ' . html_writer::link(new moodle_url('/local/moodlesearch/index.php'),
    get_string('open_back', 'local_moodlesearch'), array('class' => 'btn btn-link'));
echo html_writer::end_div();
echo html_writer::tag('noscript', html_writer::link($result['url'],
    get_string('open_link', 'local_moodlesearch', s($result['domain']))));
echo html_writer::end_div();
echo $OUTPUT->footer();
