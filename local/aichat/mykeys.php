<?php
/**
 * « Tuteur IA : mes clés de recherche » — clés d'API personnelles de l'élève.
 *
 * Le tuteur cherche sur le Web avec les clés de l'élève, dans l'ordre Tavily
 * (gratuit, sans carte bancaire) puis Brave. Sans clé, il ne va pas sur
 * Internet pour lui. Les clés sont chiffrées dans les préférences et jamais
 * réaffichées.
 *
 * Accessible depuis Préférences > Compte utilisateur. Chacun ne gère que ses
 * propres clés, et pas en mode « connecté en tant que ».
 *
 * @package local_aichat
 */

require_once(__DIR__ . '/../../config.php');

use local_aichat\form\mykeys_form;
use local_aichat\websearch\budget;
use local_aichat\websearch\diagnostic;
use local_aichat\websearch\manager;
use local_aichat\websearch\userkeys;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception(get_string('mykeys_guest', 'local_aichat'));
}

$context = context_user::instance($USER->id);
$pageurl = new moodle_url('/local/aichat/mykeys.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mykeys_page', 'local_aichat'));
$PAGE->set_heading(fullname($USER));
$PAGE->navbar->add(get_string('preferences'), new moodle_url('/user/preferences.php'));
$PAGE->navbar->add(get_string('mykeys_page', 'local_aichat'));

// Personne ne modifie les clés d'un autre, pas même via « connecté en tant que ».
if (\core\session\manager::is_loggedinas() || !manager::site_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('mykeys_page', 'local_aichat'));
    echo $OUTPUT->notification(get_string(\core\session\manager::is_loggedinas()
        ? 'mykeys_loggedinas' : 'mykeys_disabled', 'local_aichat'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

// --- Tester une clé -----------------------------------------------------------
$action   = optional_param('action', '', PARAM_ALPHA);
$provider = optional_param('provider', '', PARAM_ALPHA);
if ($action === 'test' && in_array($provider, userkeys::PROVIDERS, true)) {
    require_sesskey();
    $test = diagnostic::test_user_key($USER->id, $provider);
    if ($test->ok && $provider === 'tavily') {
        $message = get_string('mykeys_test_ok_tavily', 'local_aichat', (object)array(
            'usage' => ($test->usage !== null) ? $test->usage : '?',
            'limit' => ($test->limit !== null) ? $test->limit : '?',
        ));
    } else if ($test->ok) {
        $message = get_string('mykeys_test_ok_brave', 'local_aichat', $test->results);
    } else {
        $message = get_string('mykeys_test_failed', 'local_aichat', manager::reason_label($test->reason));
    }
    redirect($pageurl, $message, null, $test->ok ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_ERROR);
}

// --- État de chaque clé ---------------------------------------------------------
$status = array();
$configured = array();
foreach (userkeys::PROVIDERS as $id) {
    $key = userkeys::get($USER->id, $id);
    $configured[$id] = ($key !== '');
    if ($key === '') {
        $status[$id] = html_writer::span(get_string('mykeys_none', 'local_aichat'), 'badge badge-secondary');
        continue;
    }
    $html  = html_writer::span(get_string('mykeys_saved', 'local_aichat', userkeys::masked($key)), 'badge badge-success');
    $state = userkeys::state($USER->id, $id);
    if ($state !== null) {
        $until = ((int)$state->until === -1) ? get_string('mykeys_until_change', 'local_aichat')
            : userdate((int)$state->until);
        $html .= html_writer::div(get_string('mykeys_suspended', 'local_aichat', (object)array(
            'reason' => manager::reason_label($state->status), 'until' => $until)), 'text-danger small mt-1');
    }
    $cap = manager::keycap($id);
    $html .= html_writer::div(get_string('mykeys_used', 'local_aichat', (object)array(
        'used' => budget::used($id, userkeys::keyhash($id, $key)),
        'cap'  => ($cap === PHP_INT_MAX) ? '-' : $cap,
    )), 'small text-muted mt-1');
    $status[$id] = $html;
}

// --- Enregistrement --------------------------------------------------------------
$form = new mykeys_form($pageurl, array('status' => $status, 'configured' => $configured));
if ($data = $form->get_data()) {
    foreach (userkeys::PROVIDERS as $id) {
        if (!empty($data->{'remove_' . $id})) {
            userkeys::remove($USER->id, $id);
        } else if (isset($data->{'key_' . $id}) && trim($data->{'key_' . $id}) !== '') {
            userkeys::set($USER->id, $id, $data->{'key_' . $id});
        }
    }
    redirect($pageurl, get_string('mykeys_savedmsg', 'local_aichat'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mykeys_page', 'local_aichat'));
echo html_writer::div(get_string('mykeys_intro', 'local_aichat', manager::keycap('brave')), 'mb-3');

$form->display();

// Boutons de test (hors du formulaire : chacun est une action à part).
foreach (userkeys::PROVIDERS as $id) {
    if ($configured[$id]) {
        echo $OUTPUT->single_button(new moodle_url($pageurl, array('action' => 'test', 'provider' => $id)),
            get_string('mykeys_test', 'local_aichat', manager::NAMES[$id]), 'post',
            array('class' => 'd-inline-block mr-2'));
    }
}
if ($configured['brave']) {
    echo html_writer::div(get_string('mykeys_test_brave_note', 'local_aichat'), 'small text-muted mt-1');
}
echo html_writer::div(get_string('mykeys_privacy', 'local_aichat'), 'small text-muted mt-3');

echo $OUTPUT->footer();
