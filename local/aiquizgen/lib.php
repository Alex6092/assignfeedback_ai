<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Ajoute l'entrée « Générer un test IA » dans le menu de cours.
 *
 * Moodle appelle cette fonction lors de la construction de la navigation cours
 * (hook nommé `local_<plugin>_extend_navigation_course`).
 *
 * @param \navigation_node $coursenode noeud cours
 * @param \stdClass        $course      enregistrement du cours
 * @param \context_course  $context     contexte cours
 */
function local_aiquizgen_extend_navigation_course(navigation_node $coursenode,
                                                  stdClass $course,
                                                  context_course $context) {
    if (!has_capability('local/aiquizgen:generate', $context)) {
        return;
    }

    $url = new moodle_url('/local/aiquizgen/generate.php',
        array('courseid' => $course->id));

    $coursenode->add(
        get_string('generate_menu', 'local_aiquizgen'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'aiquizgen_generate',
        new pix_icon('icon', '', 'local_aiquizgen')
    );
}
