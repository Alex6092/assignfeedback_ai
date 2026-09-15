<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Tous les réglages partagés (URL/modèle/clé/vision/binaires) vivent
    // désormais dans le plugin local_aifeedback. Cette page d'admin ne garde
    // que ce qui est strictement spécifique au plugin "feedback de devoir".

    $settings->add(new admin_setting_configcheckbox(
        'assignfeedback_ai/default',
        new lang_string('default', 'assignfeedback_ai'),
        new lang_string('default_help', 'assignfeedback_ai'),
        0
    ));

    // Score chiffré (/100) et barre de progression côté étudiant : masqués par
    // défaut, le niveau de maîtrise suffit. Les enseignants voient toujours le score.
    $settings->add(new admin_setting_configcheckbox(
        'assignfeedback_ai/showscore',
        new lang_string('showscore', 'assignfeedback_ai'),
        new lang_string('showscore_help', 'assignfeedback_ai'),
        0
    ));

    $settings->add(new admin_setting_description(
        'assignfeedback_ai/sharedconfignote',
        '',
        get_string('sharedconfignote', 'assignfeedback_ai')
    ));
}
