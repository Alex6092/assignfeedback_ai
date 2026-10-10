<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Réglages du pont. L'écriture (phase 3) reste désactivée tant que
 * l'administrateur ne l'active pas, et ne vise que les catégories bac à sable.
 */
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aibridge', get_string('pluginname', 'local_aibridge'));

    $settings->add(new admin_setting_heading('local_aibridge/intro', '',
        get_string('settings_intro', 'local_aibridge',
            (new moodle_url('/local/aibridge/log.php'))->out(false))));

    $settings->add(new admin_setting_configcheckbox('local_aibridge/enablewrites',
        get_string('setting_enablewrites', 'local_aibridge'),
        get_string('setting_enablewrites_desc', 'local_aibridge'), 0));

    // La liste des catégories n'est construite que sur la page de réglages.
    $categories = array();
    if ($ADMIN->fulltree) {
        $categories = core_course_category::make_categories_list('', 0, ' / ');
    }
    $settings->add(new admin_setting_configmulticheckbox('local_aibridge/sandboxcategories',
        get_string('setting_sandboxcategories', 'local_aibridge'),
        get_string('setting_sandboxcategories_desc', 'local_aibridge'), array(), $categories));

    $settings->add(new admin_setting_configtext('local_aibridge/logretentiondays',
        get_string('setting_logretentiondays', 'local_aibridge'),
        get_string('setting_logretentiondays_desc', 'local_aibridge'), 90, PARAM_INT));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage('local_aibridge_log',
        get_string('log_title', 'local_aibridge'),
        new moodle_url('/local/aibridge/log.php'), 'moodle/site:config'));
}
