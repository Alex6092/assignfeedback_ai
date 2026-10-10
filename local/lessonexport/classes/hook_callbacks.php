<?php
namespace local_lessonexport;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;

/**
 * Boutons d'accès : « Version imprimable » sous chaque leçon, « Leçons
 * imprimables » sous la page du cours, seulement si le cours les a activées.
 */
class hook_callbacks {

    public static function after_main_region(\core\hook\output\after_standard_main_region_html_generation $hook): void {
        global $PAGE, $DB;
        try {
            $pagetype = (string)$PAGE->pagetype;
            $course = $PAGE->course;
            if (empty($course->id) || $course->id == SITEID || !isloggedin() || isguestuser()) {
                return;
            }
            if ($pagetype === 'mod-lesson-view' && $PAGE->cm) {
                if (!course_settings::can_view((int)$course->id)) {
                    return;
                }
                $lesson = $DB->get_record('lesson', array('id' => $PAGE->cm->instance));
                $cm = get_fast_modinfo($course)->get_cm($PAGE->cm->id);
                if (!$lesson || exporter::denied_reason($cm, $lesson) !== null) {
                    return;
                }
                $hook->add_html(self::button(new moodle_url('/local/lessonexport/lesson.php', array('id' => $cm->id)),
                    get_string('printable_lesson', 'local_lessonexport')));
            } else if (strpos($pagetype, 'course-view-') === 0) {
                if (!course_settings::can_view((int)$course->id)) {
                    return;
                }
                $hook->add_html(self::button(new moodle_url('/local/lessonexport/index.php', array('id' => $course->id)),
                    get_string('printable_course', 'local_lessonexport')));
            }
        } catch (\Throwable $e) {
            // Un bouton manquant ne doit jamais casser une page.
            debugging('local_lessonexport : ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private static function button(moodle_url $url, string $label): string {
        return html_writer::div(html_writer::link($url, '🖨 ' . $label,
            array('class' => 'btn btn-outline-secondary btn-sm')), 'lessonexport-launch');
    }
}
