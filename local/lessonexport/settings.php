<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_lessonexport', get_string('pluginname', 'local_lessonexport'));
    $settings->add(new admin_setting_configcheckbox('local_lessonexport/defaultenabled',
        get_string('setting_defaultenabled', 'local_lessonexport'),
        get_string('setting_defaultenabled_desc', 'local_lessonexport'), 0));
    $ADMIN->add('localplugins', $settings);
}
