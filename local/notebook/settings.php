<?php
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_notebook', get_string('pluginname', 'local_notebook'));
    $ADMIN->add('localplugins', $settings);
    foreach (['workspaceurl', 'graderurl', 'huburl'] as $name) {
        $settings->add(new admin_setting_configtext('local_notebook/' . $name,
            get_string($name, 'local_notebook'), get_string('httpsrequired', 'local_notebook'), '', PARAM_URL));
    }
    $settings->add(new admin_setting_heading('local_notebook/secrets',
        get_string('secrets', 'local_notebook'), get_string('secrets_help', 'local_notebook')));
}
