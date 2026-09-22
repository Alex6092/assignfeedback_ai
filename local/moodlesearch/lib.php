<?php
defined('MOODLE_INTERNAL') || die();

use local_moodlesearch\access;

/**
 * MoodleSearch se sert de la clé Tavily personnelle gérée par le Tuteur IA :
 * la page « mes clés de recherche » reste disponible tant que MoodleSearch
 * est activé, même si la recherche du tuteur ne l'est pas.
 * Voir local_aichat_personal_keys_usages().
 *
 * @return string nom de l'usage, '' si MoodleSearch est désactivé
 */
function local_moodlesearch_aichat_keys_usage() {
    return access::enabled() ? get_string('pluginname', 'local_moodlesearch') : '';
}

/**
 * Lien « Recherches MoodleSearch » dans la navigation du cours, pour les
 * enseignants (recherches et clics des élèves inscrits).
 *
 * @param navigation_node $navigation
 * @param stdClass        $course
 * @param context_course  $context
 */
function local_moodlesearch_extend_navigation_course(navigation_node $navigation, stdClass $course,
        context_course $context) {
    if (!access::enabled() || !has_capability('local/moodlesearch:viewreport', $context)) {
        return;
    }
    $navigation->add(get_string('report_title', 'local_moodlesearch'),
        new moodle_url('/local/moodlesearch/report.php', array('courseid' => $course->id)),
        navigation_node::TYPE_SETTING, null, 'localmoodlesearchreport', new pix_icon('i/search', ''));
}
