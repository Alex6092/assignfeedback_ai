<?php
/**
 * Point d'entrée du générateur de tests IA.
 *
 * Étape 2 : affiche le formulaire de génération. À la soumission :
 *   1. Crée une ligne pending dans {local_aiquizgen_jobs}
 *   2. Persiste le PDF dans la file area du job
 *      (component='local_aiquizgen', filearea='source', itemid=$jobid)
 *   3. Redirige vers status.php
 *
 * L'enqueue de la tâche ad-hoc et le job_handler arrivent à l'étape 3.
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);

require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aiquizgen:generate', $context);

$PAGE->set_url(new moodle_url('/local/aiquizgen/generate.php',
    array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_aiquizgen'));
$PAGE->set_heading(format_string($course->fullname));

$form = new \local_aiquizgen\form\generate_form(null,
    array('courseid' => $courseid));

// -------------------------------------------------------------
//  Annulation : retour à la page du cours
// -------------------------------------------------------------
if ($form->is_cancelled()) {
    redirect(course_get_url($course));
}

// -------------------------------------------------------------
//  Soumission valide : on crée le job
// -------------------------------------------------------------
if ($data = $form->get_data()) {
    global $DB, $USER;

    $now = time();

    $params = array(
        'mcqcount' => (int)$data->mcqcount,
        'quizname' => (string)$data->quizname,
    );

    $job = new stdClass();
    $job->courseid     = $courseid;
    $job->userid       = (int)$USER->id;
    $job->status       = 'pending';
    $job->params       = json_encode($params);
    $job->attempts     = 0;
    $job->timecreated  = $now;
    $job->timemodified = $now;

    $jobid = $DB->insert_record('local_aiquizgen_jobs', $job);

    // Déplace le PDF du draft area vers la file area persistante du job.
    file_save_draft_area_files(
        (int)$data->sourcefile,
        $context->id,
        'local_aiquizgen',
        'source',
        $jobid
    );

    // Met le job dans la file partagée → traitement par le cron.
    \local_aiquizgen\job_handler::enqueue($jobid);

    redirect(new moodle_url('/local/aiquizgen/status.php',
        array('jobid' => $jobid)));
}

// -------------------------------------------------------------
//  Affichage initial du formulaire
// -------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_aiquizgen'));
echo $OUTPUT->box(get_string('form_intro', 'local_aiquizgen'), 'generalbox');
$form->display();
echo $OUTPUT->footer();
