<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Lien « Leçons imprimables » dans le menu du cours (« Plus »).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_lessonexport_extend_navigation_course($navigation, $course, $context) {
    if (empty($course->id) || $course->id == SITEID || !\local_lessonexport\course_settings::can_view((int)$course->id)) {
        return;
    }
    $navigation->add(get_string('printable_course', 'local_lessonexport'),
        new moodle_url('/local/lessonexport/index.php', array('id' => $course->id)),
        navigation_node::TYPE_SETTING, null, 'local_lessonexport', new pix_icon('t/print', ''));
}
