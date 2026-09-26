<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Lien « Contrôle des tentatives » dans la navigation du cours, pour les
 * enseignants.
 *
 * @param navigation_node $navigation
 * @param stdClass        $course
 * @param context_course  $context
 */
function local_attemptcheck_extend_navigation_course(navigation_node $navigation, stdClass $course,
        context_course $context) {
    if (!has_capability('local/attemptcheck:view', $context)) {
        return;
    }
    $navigation->add(get_string('menu', 'local_attemptcheck'),
        new moodle_url('/local/attemptcheck/report.php', array('courseid' => $course->id)),
        navigation_node::TYPE_SETTING, null, 'localattemptcheck', new pix_icon('i/report', ''));
}
