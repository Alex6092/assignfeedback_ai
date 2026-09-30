<?php
/**
 * Page élève : saisir le code de séance d'une activité surveillée (code
 * affiché en classe par l'enseignant). Le bon code vaut présence et ouvre
 * l'activité pour la durée de l'ouverture.
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');

use local_classhours\gate;

/** Essais faux autorisés avant une attente. */
const LOCAL_CLASSHOURS_CODE_TRIES = 5;
/** Attente après trop d'essais faux (secondes). */
const LOCAL_CLASSHOURS_CODE_WAIT = 60;

$cmid = required_param('cmid', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
// Connexion au COURS seulement : tant que le code n'est pas saisi, l'activité
// est fermée, un require_login() avec le module renverrait vers la page
// « activité restreinte ». (Le mode examen laisse passer cette page.)
require_login($course);
$coursecontext = context_course::instance($course->id);

$courseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$pageurl = new moodle_url('/local/classhours/code.php', array('cmid' => $cmid));
$activityurl = $cm->url ?: $courseurl;
$userid = (int)$USER->id;

if (!gate::needs_code((int)$course->id, (int)$cm->id, $userid)) {
    // Déjà saisi, ou l'activité n'est pas ouverte pour lui.
    redirect(gate::is_open_for((int)$course->id, (int)$cm->id, $userid) ? $activityurl : $courseurl);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('code_heading', 'local_classhours'));
$PAGE->set_heading(format_string($course->fullname));

$activityname = format_string($cm->name, true, array('context' => context_module::instance($cm->id)));

// Anti-force brute : quelques essais, puis une minute d'attente.
if (!isset($SESSION->local_classhours_codetries)) {
    $SESSION->local_classhours_codetries = array();
}
$tries = $SESSION->local_classhours_codetries[$cmid] ?? array('count' => 0, 'until' => 0);
$now = time();
$error = '';

if (optional_param('code', null, PARAM_RAW) !== null) {
    require_sesskey();
    if ($tries['until'] > $now) {
        $error = get_string('code_wait', 'local_classhours', $tries['until'] - $now);
    } else if (gate::submit_code((int)$course->id, (int)$cm->id, $userid, optional_param('code', '', PARAM_RAW))) {
        unset($SESSION->local_classhours_codetries[$cmid]);
        redirect($activityurl, get_string('code_ok', 'local_classhours'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } else {
        $tries['count']++;
        if ($tries['count'] >= LOCAL_CLASSHOURS_CODE_TRIES) {
            $tries = array('count' => 0, 'until' => $now + LOCAL_CLASSHOURS_CODE_WAIT);
            $error = get_string('code_wait', 'local_classhours', LOCAL_CLASSHOURS_CODE_WAIT);
        } else {
            $error = get_string('code_wrong', 'local_classhours');
        }
        $SESSION->local_classhours_codetries[$cmid] = $tries;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('code_heading', 'local_classhours'));
echo html_writer::tag('p', get_string('code_intro', 'local_classhours', $activityname));
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false),
    'class' => 'd-flex flex-wrap align-items-end gap-2', 'autocomplete' => 'off'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::tag('label', get_string('code_label', 'local_classhours'),
    array('for' => 'classhours-code', 'class' => 'visually-hidden sr-only'));
echo html_writer::empty_tag('input', array('type' => 'text', 'name' => 'code', 'id' => 'classhours-code',
    'maxlength' => gate::CODE_LENGTH + 4, 'size' => 8, 'autofocus' => 'autofocus', 'required' => 'required',
    'class' => 'form-control form-control-lg text-uppercase', 'style' => 'letter-spacing:.3em; max-width: 12rem;'));
echo html_writer::tag('button', get_string('code_submit', 'local_classhours'),
    array('type' => 'submit', 'class' => 'btn btn-primary btn-lg'));
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
