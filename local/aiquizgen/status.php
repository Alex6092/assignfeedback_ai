<?php
/**
 * Page de statut d'un job de génération.
 *
 * Étape 2 : affichage simple de l'état (pending/running/done/failed).
 *   - pending/running : meta-refresh toutes les 5 secondes
 *   - done            : liens vers la catégorie et le quiz créés
 *   - failed          : message d'erreur
 *
 * Tant que le job_handler n'est pas implémenté (étape 3), tous les jobs
 * créés via le formulaire restent en 'pending' indéfiniment. C'est attendu.
 */

require_once(__DIR__ . '/../../config.php');

$jobid = required_param('jobid', PARAM_INT);

$job = $DB->get_record('local_aiquizgen_jobs',
    array('id' => $jobid), '*', MUST_EXIST);

$course  = get_course($job->courseid);
require_login($course);

$context = context_course::instance($job->courseid);
require_capability('local/aiquizgen:generate', $context);

// Sécurité : seul l'auteur du job ou un manager peut voir son statut.
if ($job->userid != $USER->id
    && !has_capability('moodle/course:manageactivities', $context)) {
    throw new \moodle_exception('nopermissions', 'error', '',
        get_string('status_pagetitle', 'local_aiquizgen'));
}

$PAGE->set_url(new moodle_url('/local/aiquizgen/status.php',
    array('jobid' => $jobid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('status_pagetitle', 'local_aiquizgen'));
$PAGE->set_heading(format_string($course->fullname));

// Auto-refresh tant que le job est actif.
if ($job->status === 'pending' || $job->status === 'running') {
    $PAGE->set_periodic_refresh_delay(5);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('status_pagetitle', 'local_aiquizgen'));

// -------------------------------------------------------------
//  Tableau récapitulatif du job
// -------------------------------------------------------------
$params = json_decode((string)$job->params, true) ?: array();
$badgemap = array(
    'pending' => 'badge bg-secondary text-white',
    'running' => 'badge bg-info text-white',
    'done'    => 'badge bg-success text-white',
    'failed'  => 'badge bg-danger text-white',
);
$badgeclass = isset($badgemap[$job->status]) ? $badgemap[$job->status] : 'badge bg-secondary';
$statuslabel = get_string('status_' . $job->status, 'local_aiquizgen');

$rows = array();
$rows[] = array(
    get_string('status_label_status', 'local_aiquizgen'),
    \html_writer::span(s($statuslabel), $badgeclass),
);
$rows[] = array(
    get_string('status_label_created', 'local_aiquizgen'),
    userdate((int)$job->timecreated),
);
if (isset($params['quizname'])) {
    $rows[] = array(
        get_string('quizname', 'local_aiquizgen'),
        s((string)$params['quizname']),
    );
}
if (isset($params['mcqcount'])) {
    $rows[] = array(
        get_string('mcqcount', 'local_aiquizgen'),
        (int)$params['mcqcount'],
    );
}
// Affichage de la source selon le type.
$sourcetype = isset($params['sourcetype']) ? (string)$params['sourcetype'] : 'pdf';
if ($sourcetype === 'lesson') {
    $rows[] = array(
        get_string('source_type', 'local_aiquizgen'),
        get_string('source_type_lesson', 'local_aiquizgen'),
    );
    if (!empty($params['sourcelessonid'])) {
        $lessonname = (string)$DB->get_field('lesson', 'name',
            array('id' => (int)$params['sourcelessonid']));
        if ($lessonname !== '') {
            $rows[] = array(
                get_string('source_lesson', 'local_aiquizgen'),
                s($lessonname),
            );
        }
    }
} else {
    $rows[] = array(
        get_string('source_type', 'local_aiquizgen'),
        get_string('source_type_pdf', 'local_aiquizgen'),
    );
    $fs    = get_file_storage();
    $files = $fs->get_area_files($context->id, 'local_aiquizgen', 'source',
        $jobid, 'filename', false);
    if (!empty($files)) {
        $names = array();
        foreach ($files as $f) {
            $names[] = s($f->get_filename());
        }
        $rows[] = array(
            get_string('source_pdf', 'local_aiquizgen'),
            implode(', ', $names),
        );
    }
}
if ((int)$job->attempts > 0) {
    $rows[] = array(
        get_string('status_label_attempts', 'local_aiquizgen'),
        (int)$job->attempts,
    );
}

$table = new \html_table();
$table->attributes['class'] = 'generaltable';
$table->data = $rows;
echo \html_writer::table($table);

// -------------------------------------------------------------
//  Message contextuel selon le statut
// -------------------------------------------------------------
switch ($job->status) {
    case 'pending':
        echo $OUTPUT->notification(
            get_string('status_pending_help', 'local_aiquizgen'), 'info');
        break;

    case 'running':
        echo $OUTPUT->notification(
            get_string('status_running_help', 'local_aiquizgen'), 'info');
        break;

    case 'done':
        echo $OUTPUT->notification(
            get_string('status_done_help', 'local_aiquizgen'), 'success');
        // Liens vers ce qui a été créé.
        if (!empty($job->resultquizid)) {
            $cm = get_coursemodule_from_id('quiz', (int)$job->resultquizid);
            if ($cm) {
                echo \html_writer::link(
                    new moodle_url('/mod/quiz/view.php', array('id' => $cm->id)),
                    get_string('open_quiz', 'local_aiquizgen'),
                    array('class' => 'btn btn-primary me-2'));
            }
        }
        if (!empty($job->resultcategoryid) && !empty($job->resultquizid)) {
            // La catégorie vit dans le contexte MODULE du quiz (Moodle 5.x).
            // La banque de questions de ce module s'ouvre via cmid + cat.
            $catrec = $DB->get_record('question_categories',
                array('id' => (int)$job->resultcategoryid),
                'id, contextid');
            if ($catrec) {
                echo \html_writer::link(
                    new moodle_url('/question/edit.php',
                        array('cmid' => (int)$job->resultquizid,
                              'cat'  => $catrec->id . ',' . $catrec->contextid)),
                    get_string('open_category', 'local_aiquizgen'),
                    array('class' => 'btn btn-secondary'));
            }
        }
        break;

    case 'failed':
        echo $OUTPUT->notification(
            get_string('status_failed_help', 'local_aiquizgen'), 'error');
        if (!empty($job->lasterror)) {
            echo \html_writer::div(
                \html_writer::tag('strong', get_string('status_lasterror', 'local_aiquizgen')
                    . ' ') . s((string)$job->lasterror),
                'alert alert-danger');
        }
        break;
}

// -------------------------------------------------------------
//  Journal de génération (si présent)
// -------------------------------------------------------------
if (!empty($job->log)) {
    echo \html_writer::start_tag('details', array('class' => 'mt-3'));
    echo \html_writer::tag('summary',
        get_string('status_label_log', 'local_aiquizgen'),
        array('style' => 'cursor:pointer;font-weight:600;'));
    echo \html_writer::tag('pre', s((string)$job->log),
        array('class' => 'mt-2',
              'style' => 'background:#f8f9fa;border:1px solid #dee2e6;'
                      .  'padding:.75rem;border-radius:.25rem;'
                      .  'max-height:300px;overflow:auto;'
                      .  'font-size:.85rem;white-space:pre-wrap;'));
    echo \html_writer::end_tag('details');
}

// -------------------------------------------------------------
//  Bouton retour
// -------------------------------------------------------------
echo \html_writer::div(
    \html_writer::link(
        new moodle_url('/local/aiquizgen/generate.php',
            array('courseid' => $job->courseid)),
        get_string('back_to_form', 'local_aiquizgen'),
        array('class' => 'btn btn-link')
    ),
    'mt-3');

echo $OUTPUT->footer();
