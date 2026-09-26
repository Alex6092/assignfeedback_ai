<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\efe_sync;

/**
 * Lien « Heures de cours » dans la navigation du cours, pour les enseignants
 * qui règlent l'emploi du temps.
 *
 * @param navigation_node $navigation
 * @param stdClass        $course
 * @param context_course  $context
 */
function local_classhours_extend_navigation_course(navigation_node $navigation, stdClass $course,
        context_course $context) {
    if (!has_capability('local/classhours:manage', $context)) {
        return;
    }
    $navigation->add(get_string('menu', 'local_classhours'),
        new moodle_url('/local/classhours/manage.php', array('courseid' => $course->id)),
        navigation_node::TYPE_SETTING, null, 'localclasshours', new pix_icon('i/calendar', ''));
}

/**
 * Après l'enregistrement du formulaire d'une activité : l'option EFE est
 * réévaluée pour elle, en fin de requête (voir efe_sync::defer_cm).
 *
 * @param stdClass $data   données du formulaire ($data->coursemodule = cmid)
 * @param stdClass $course
 * @return stdClass $data inchangé
 */
function local_classhours_coursemodule_edit_post_actions($data, $course) {
    if (!empty($data->coursemodule)) {
        efe_sync::defer_cm((int)$data->coursemodule);
    }
    return $data;
}
