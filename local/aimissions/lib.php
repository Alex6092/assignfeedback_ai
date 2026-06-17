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
    $canmanage = has_capability('local/aimissions:generate', $context);
    $canask    = has_capability('local/aimissions:askclient', $context);

    // Enseignant : accès au générateur / suivi.
    if ($canmanage) {
        $node = $navigation->add(
            get_string('pluginname', 'local_aimissions'),
            new moodle_url('/local/aimissions/status.php', array('courseid' => $course->id)),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_aimissions',
            new pix_icon('i/competencies', '')
        );
        if ($node) {
            $node->showinflatnavigation = true;
        }
    } else if ($canask) {
        // Étudiant : accès au fil « questions au client ».
        $node = $navigation->add(
            get_string('ticket_navlabel', 'local_aimissions'),
            new moodle_url('/local/aimissions/ticket.php', array('courseid' => $course->id)),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_aimissions_ticket',
            new pix_icon('i/email', '')
        );
        if ($node) {
            $node->showinflatnavigation = true;
        }
    }
}
