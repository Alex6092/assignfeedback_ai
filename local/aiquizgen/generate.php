<?php
/**
 * Point d'entrée du générateur de tests IA.
 *
 * Affiche le formulaire de génération. À la soumission :
 *   1. Crée une ligne pending dans {local_aiquizgen_jobs} avec
 *      `params` JSON qui décrit le type de source (pdf|lesson) et ses infos
 *   2. Si source=pdf : persiste le fichier dans la file area du job
 *      (component='local_aiquizgen', filearea='source', itemid=$jobid)
 *      Si source=lesson : on ne touche pas au file storage, l'id de la
 *      leçon suffit (la leçon est lue à la volée par le handler)
 *   3. Met le job dans la file partagée local_aifeedback
 *   4. Redirige vers status.php
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

// Récupère la liste des leçons du cours pour alimenter le dropdown du form.
$lessons = $DB->get_records_menu('lesson',
    array('course' => $courseid), 'name', 'id, name');

$form = new \local_aiquizgen\form\generate_form(null, array(
    'courseid' => $courseid,
    'lessons'  => $lessons,
));

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

    $now        = time();
    $sourcetype = isset($data->sourcetype) ? (string)$data->sourcetype : 'pdf';

    $params = array(
        'sourcetype' => $sourcetype,
        'mcqcount'   => (int)$data->mcqcount,
        'quizname'   => (string)$data->quizname,
    );
    if ($sourcetype === 'lesson') {
        $params['sourcelessonid'] = (int)$data->sourcelessonid;
    }
    // Mode de variation : fixed (défaut) ou random.
    $variationmode = isset($data->variationmode)
        ? (string)$data->variationmode : 'fixed';
    $params['variationmode'] = $variationmode;
    if ($variationmode === 'random') {
        $params['randomperattempt'] = (int)$data->randomperattempt;
    }

    $job = new stdClass();
    $job->courseid     = $courseid;
    $job->userid       = (int)$USER->id;
    $job->status       = 'pending';
    $job->params       = json_encode($params);
    $job->attempts     = 0;
    $job->timecreated  = $now;
    $job->timemodified = $now;

    $jobid = $DB->insert_record('local_aiquizgen_jobs', $job);

    // Pour les sources PDF, on déplace le fichier du draft area vers la
    // file area persistante du job. Pour les leçons, on n'a rien à stocker
    // (on relit la leçon depuis Moodle à la volée).
    if ($sourcetype === 'pdf' && !empty($data->sourcefile)) {
        file_save_draft_area_files(
            (int)$data->sourcefile,
            $context->id,
            'local_aiquizgen',
            'source',
            $jobid
        );
    }

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
