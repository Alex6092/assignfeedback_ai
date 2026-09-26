<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Réglages du site pour les heures de cours. L'emploi du temps lui-même se
 * règle dans chaque cours (page « Heures de cours »).
 */
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_classhours', get_string('pluginname', 'local_classhours'));

    $settings->add(new admin_setting_heading('local_classhours/intro', '',
        get_string('settings_intro', 'local_classhours')));

    $settings->add(new admin_setting_configtext('local_classhours/graceminutes',
        get_string('setting_graceminutes', 'local_classhours'),
        get_string('setting_graceminutes_desc', 'local_classhours'),
        \local_classhours\schedule::DEFAULT_GRACE_MINUTES, PARAM_INT));

    $settings->add(new admin_setting_configcheckbox('local_classhours/efeauto_default',
        get_string('setting_efeauto_default', 'local_classhours'),
        get_string('setting_efeauto_default_desc', 'local_classhours'), 0));

    $ADMIN->add('localplugins', $settings);
}
