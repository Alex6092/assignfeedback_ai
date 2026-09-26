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

    // Périodes de fermeture globales (vacances) : saisies sur leur propre page.
    $settings->add(new admin_setting_heading('local_classhours/globalheading',
        get_string('global_heading', 'local_classhours'),
        get_string('setting_global_desc', 'local_classhours',
            (new moodle_url('/local/classhours/globalperiods.php'))->out(false))));

    $settings->add(new admin_setting_configcheckbox('local_classhours/useglobal_default',
        get_string('setting_useglobal_default', 'local_classhours'),
        get_string('setting_useglobal_default_desc', 'local_classhours'), 1));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage('local_classhours_globalperiods',
        get_string('global_menu', 'local_classhours'),
        new moodle_url('/local/classhours/globalperiods.php'), 'moodle/site:config'));
}
