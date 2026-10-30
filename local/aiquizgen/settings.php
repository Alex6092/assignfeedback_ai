<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aiquizgen',
        new lang_string('pluginname', 'local_aiquizgen'));

    // Modèle de génération des questions (catalogue du load balancer).
    // Vide : modèle des corrections IA.
    $settings->add(new \local_aifeedback\admin\lb_model_setting(
        'local_aiquizgen/model',
        new lang_string('setting_model', 'local_aiquizgen'),
        new lang_string('setting_model_help', 'local_aiquizgen'),
        get_string('lbmodel_default', 'local_aifeedback')
    ));

    $ADMIN->add('localplugins', $settings);
}
