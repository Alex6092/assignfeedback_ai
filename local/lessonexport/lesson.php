<?php
/**
 * Version imprimable d'une leçon : toutes ses pages, mise en page du thème.
 * « Imprimer → Enregistrer en PDF » dans le navigateur donne le PDF.
 */

require(__DIR__ . '/../../config.php');

use local_lessonexport\course_settings;
use local_lessonexport\exporter;

$id = required_param('id', PARAM_INT);
list($course, $cm) = get_course_and_cm_from_cmid($id, 'lesson');

// require_login applique la visibilité, les restrictions d'accès et le mode
// examen d'une activité surveillée (local_classhours renvoie vers l'examen).
require_login($course, false, $cm);
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
