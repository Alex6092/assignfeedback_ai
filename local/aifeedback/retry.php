<?php
/**
 * Relance une correction IA (réponse échouée ou bloquée) pour une question de
 * quiz. Réservé aux utilisateurs ayant le droit de noter le quiz concerné.
 *
 * Workflow :
 *   - GET  ?id=X        → affiche une page de confirmation (form POST + sesskey)
 *   - POST id=X+sesskey → exécute la relance et redirige vers la review
 *
 * Le lien dans le commentaire HTML d'échec (posté en cron) ne peut PAS porter
 * de sesskey, d'où le double passage : on le génère côté navigateur au moment
 * où l'enseignant clique « Confirmer ».
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$row = $DB->get_record('local_aifeedback_qgrading', array('id' => $id));
if (!$row) {
    require_login();
    throw new \moodle_exception('retry_notfound', 'local_aifeedback');
}

// Résolution du quiz / cm / course depuis la ligne de grading.
$qarec = $DB->get_record('question_attempts',
    array('id' => (int)$row->questionattemptid),
    'id, questionusageid, slot');
$qza = $qarec ? $DB->get_record('quiz_attempts',
    array('uniqueid' => (int)$qarec->questionusageid), 'id, quiz') : null;
if (!$qarec || !$qza) {
    require_login();
    throw new \moodle_exception('retry_notfound', 'local_aifeedback');
}
$cm     = get_coursemodule_from_instance('quiz', (int)$qza->quiz, 0, false, MUST_EXIST);
$course = get_course($cm->course);

// Bootstrap propre de la page (pose context + cm + course de façon cohérente).
require_login($course, false, $cm);
$context = \context_module::instance($cm->id);
require_capability('mod/quiz:grade', $context);

$pageurl = new moodle_url('/local/aifeedback/retry.php', array('id' => $id));
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('retry_pagetitle', 'local_aifeedback'));
$PAGE->set_heading(get_string('retry_pagetitle', 'local_aifeedback'));

// === Exécution (POST avec sesskey) ===
if (data_submitted() && confirm_sesskey()) {

    // Réinitialise la ligne pour une correction entièrement fraîche.
    $row->status        = 'pending';
    $row->attempts      = 0;
    $row->error_message = null;
    $row->aifeedback    = null;
    $row->mark          = null;
    $row->timemodified  = time();
    $DB->update_record('local_aifeedback_qgrading', $row);

    // Ré-enqueue via le handler du composant propriétaire.
    $handlerclass = '\\' . $row->component . '\\job_handler';
    if (class_exists($handlerclass)
            && is_subclass_of($handlerclass, '\\local_aifeedback\\quiz_grader')) {
        $handlerclass::enqueue((int)$row->id);
    }

    redirect(new moodle_url('/mod/quiz/review.php', array('attempt' => (int)$qza->id)),
        get_string('retry_queued', 'local_aifeedback'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// === Page de confirmation (GET) ===
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('retry_pagetitle', 'local_aifeedback'));

echo html_writer::div(
    get_string('retry_confirm_body', 'local_aifeedback'),
    'alert alert-info');

if (!empty($row->error_message)) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('retry_lasterror', 'local_aifeedback') . ' ') .
        s((string)$row->error_message),
        'alert alert-warning small');
}

echo html_writer::start_tag('form', array(
    'action' => $pageurl->out(false),
    'method' => 'post',
));
echo html_writer::empty_tag('input',
    array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input',
    array('type' => 'hidden', 'name' => 'id',      'value' => (int)$id));
echo html_writer::empty_tag('input', array(
    'type'  => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('retry_confirm_button', 'local_aifeedback'),
));
echo ' ';
echo html_writer::link(new moodle_url('/mod/quiz/review.php', array('attempt' => (int)$qza->id)),
    get_string('cancel'), array('class' => 'btn btn-secondary'));
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
