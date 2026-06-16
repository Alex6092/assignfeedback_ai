<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Ajoute un lien « Missions client IA » dans la navigation du cours, pour les
 * enseignants disposant de la capacité de génération.
 *
 * @param navigation_node $navigation
 * @param stdClass        $course
 * @param context         $context
 */
function local_aimissions_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/aimissions:generate', $context)) {
        return;
    }
    $url = new moodle_url('/local/aimissions/status.php', array('courseid' => $course->id));
    $node = $navigation->add(
        get_string('pluginname', 'local_aimissions'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_aimissions',
        new pix_icon('i/competencies', '')
    );
    if ($node) {
        $node->showinflatnavigation = true;
    }
}
