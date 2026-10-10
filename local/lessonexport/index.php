<?php
/**
 * Leçons imprimables d'un cours : la liste, l'impression de toutes les leçons
 * d'un coup (une leçon par page), et l'activation pour le cours.
 */

require(__DIR__ . '/../../config.php');

use local_lessonexport\course_settings;
use local_lessonexport\exporter;

$courseid = required_param('id', PARAM_INT);
$all = optional_param('all', 0, PARAM_BOOL);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$canmanage = has_capability('local/lessonexport:manage', $context);

// Activation par l'enseignant.
$toggle = optional_param('enable', -1, PARAM_INT);
if ($toggle !== -1 && $canmanage && confirm_sesskey()) {
    course_settings::set_enabled((int)$course->id, $toggle === 1);
    redirect(new moodle_url('/local/lessonexport/index.php', array('id' => $course->id)),
        get_string($toggle === 1 ? 'enabled_done' : 'disabled_done', 'local_lessonexport'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}
if (!course_settings::can_view((int)$course->id)) {
    throw new moodle_exception('disabled', 'local_lessonexport');
}

$lessons = exporter::lessons(get_fast_modinfo($course));
$url = new moodle_url('/local/lessonexport/index.php', array('id' => $course->id));
$PAGE->set_url($all ? new moodle_url($url, array('all' => 1)) : $url);
$PAGE->set_title(get_string('printable_course', 'local_lessonexport') . ' — ' . format_string($course->shortname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('local-lessonexport');

if ($all) {
    // Toutes les leçons, une par page imprimée.
    $PAGE->set_pagelayout('print');
    echo $OUTPUT->header();
    echo exporter::toolbar($url, get_string('back_list', 'local_lessonexport'));
    foreach ($lessons as $cm) {
        echo exporter::render_lesson($cm, $DB->get_record('lesson', array('id' => $cm->instance), '*', MUST_EXIST));
    }
    echo $OUTPUT->footer();
    exit;
}

$PAGE->set_pagelayout('incourse');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('printable_course', 'local_lessonexport'));

if ($canmanage) {
    $enabled = course_settings::enabled((int)$course->id);
    $switch = new moodle_url($url, array('enable' => $enabled ? 0 : 1, 'sesskey' => sesskey()));
    echo $OUTPUT->notification(get_string($enabled ? 'status_enabled' : 'status_disabled', 'local_lessonexport'),
        $enabled ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO, false);
    echo html_writer::div(html_writer::link($switch,
        get_string($enabled ? 'disable' : 'enable', 'local_lessonexport'), array('class' => 'btn btn-secondary')), 'mb-3');
}

if (!$lessons) {
    echo $OUTPUT->notification(get_string('nolessons', 'local_lessonexport'), \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::tag('p', get_string('intro', 'local_lessonexport'));
    echo html_writer::div(html_writer::link(new moodle_url($url, array('all' => 1)),
        '🖨 ' . get_string('print_all', 'local_lessonexport', count($lessons)), array('class' => 'btn btn-primary')), 'mb-3');
    $items = array();
    $modinfo = get_fast_modinfo($course);
    foreach ($lessons as $cm) {
        $section = $modinfo->get_section_info($cm->sectionnum);
        $items[] = html_writer::link(new moodle_url('/local/lessonexport/lesson.php', array('id' => $cm->id)),
            format_string($cm->name)) . ' ' . html_writer::span(get_section_name($course, $section), 'text-muted small');
    }
    echo html_writer::alist($items, array('class' => 'lessonexport-list'));
}
echo $OUTPUT->footer();
