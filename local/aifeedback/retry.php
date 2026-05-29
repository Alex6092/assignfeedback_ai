<?php
/**
 * Relance une correction IA (réponse échouée ou bloquée) pour une question de
 * quiz. Réservé aux utilisateurs ayant le droit de noter le quiz concerné.
 *
 * Appelé depuis le bouton « Relancer la correction IA » de la carte de feedback
 * (voir \local_aifeedback\feedback_card::retry_control).
 */

require_once(__DIR__ . '/../../config.php');

$id        = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

require_login();
require_sesskey();

$row = $DB->get_record('local_aifeedback_qgrading', array('id' => $id));
if (!$row) {
    throw new \moodle_exception('retry_notfound', 'local_aifeedback');
}

// Contrôle de capacité dans le contexte du quiz d'origine.
$context = \local_aifeedback\feedback_card::quiz_context_from_qaid((int)$row->questionattemptid);
if (!$context) {
    throw new \moodle_exception('retry_notfound', 'local_aifeedback');
}
$PAGE->set_context($context);
require_capability('mod/quiz:grade', $context);

// Réinitialise pour une correction entièrement fraîche (nouvel appel LLM).
$row->status        = 'pending';
$row->attempts      = 0;
$row->error_message = null;
$row->aifeedback    = null;
$row->mark          = null;
$row->timemodified  = time();
$DB->update_record('local_aifeedback_qgrading', $row);

// Ré-enqueue via le handler du composant propriétaire.
$handlerclass = '\\' . $row->component . '\\job_handler';
if (class_exists($handlerclass) && is_subclass_of($handlerclass, '\\local_aifeedback\\quiz_grader')) {
    $handlerclass::enqueue((int)$row->id);
}

$redirect = $returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/');
redirect($redirect, get_string('retry_queued', 'local_aifeedback'), null,
    \core\output\notification::NOTIFY_SUCCESS);
