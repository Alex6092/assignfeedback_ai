<?php
defined('MOODLE_INTERNAL') || die();

use local_attemptcheck\analyser;
use local_attemptcheck\collector;

/**
 * Seuils des indicateurs et notification. Le rapport se consulte dans chaque
 * cours (« Contrôle des tentatives »).
 */
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_attemptcheck', get_string('pluginname', 'local_attemptcheck'));

    $settings->add(new admin_setting_heading('local_attemptcheck/intro', '',
        get_string('settings_intro', 'local_attemptcheck')));

    $settings->add(new admin_setting_configcheckbox('local_attemptcheck/notify',
        get_string('setting_notify', 'local_attemptcheck'),
        get_string('setting_notify_desc', 'local_attemptcheck'), 1));

    foreach (analyser::DEFAULTS as $name => $default) {
        $settings->add(new admin_setting_configtext('local_attemptcheck/' . $name,
            get_string('setting_' . $name, 'local_attemptcheck'),
            get_string('setting_' . $name . '_desc', 'local_attemptcheck'), $default, PARAM_INT));
    }

    $settings->add(new admin_setting_configtext('local_attemptcheck/textqtypes',
        get_string('setting_textqtypes', 'local_attemptcheck'),
        get_string('setting_textqtypes_desc', 'local_attemptcheck'), collector::DEFAULT_TEXTQTYPES, PARAM_TEXT));

    $ADMIN->add('localplugins', $settings);
}
