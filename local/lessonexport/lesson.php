<?php
/**
 * Version imprimable d'une leçon : toutes ses pages, mise en page du thème.
 * « Imprimer → Enregistrer en PDF » dans le navigateur donne le PDF.
 */

require(__DIR__ . '/../../config.php');

use local_lessonexport\course_settings;
use local_lessonexport\exporter;

$id = required_param('id', PARAM_INT);
list($course) = get_course_and_cm_from_cmid($id, 'lesson');

// Connexion au niveau du COURS, sans déclarer l'activité : cette page n'est pas
// une page d'activité, et les plugins qui ajoutent un widget à toute page
// d'activité (accès réseau, tuteur...) n'ont rien à y faire, encore moins sur
// une impression. Le mode examen d'une activité surveillée s'applique bien au
// niveau du cours (local_classhours renvoie l'élève vers l'examen) ; la
// visibilité et les restrictions d'accès de la leçon sont vérifiées ci-dessous
// (exporter::denied_reason, sur cm_info::uservisible).
require_login($course, false);
$cm = get_fast_modinfo($course)->get_cm($id);
if (!course_settings::can_view((int)$course->id)) {
    throw new moodle_exception('disabled', 'local_lessonexport');
}
$lesson = $DB->get_record('lesson', array('id' => $cm->instance), '*', MUST_EXIST);
$reason = exporter::denied_reason($cm, $lesson);
if ($reason !== null) {
    throw new moodle_exception($reason, 'local_lessonexport');
}

$PAGE->set_url(new moodle_url('/local/lessonexport/lesson.php', array('id' => $cm->id)));
$PAGE->set_pagelayout('print');
$PAGE->set_title(format_string($cm->name) . ' — ' . get_string('printable', 'local_lessonexport'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('local-lessonexport');

echo $OUTPUT->header();
echo exporter::toolbar(new moodle_url('/mod/lesson/view.php', array('id' => $cm->id)),
    get_string('back_lesson', 'local_lessonexport'));
echo exporter::render_lesson($cm, $lesson);
echo $OUTPUT->footer();
