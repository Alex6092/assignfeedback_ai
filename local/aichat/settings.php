<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_aichat',
        new lang_string('pluginname', 'local_aichat'));

    $settings->add(new admin_setting_description(
        'local_aichat/serversnote',
        '',
        get_string('serversnote', 'local_aichat')
    ));

    // === Comportement du tuteur ===
    $settings->add(new admin_setting_heading(
        'local_aichat/tutor_heading',
        new lang_string('tutor_heading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aichat/tutorprompt',
        new lang_string('setting_tutorprompt', 'local_aichat'),
        new lang_string('setting_tutorprompt_help', 'local_aichat'),
        \local_aichat\tutor::default_system_prompt(),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/temperature',
        new lang_string('setting_temperature', 'local_aichat'),
        new lang_string('setting_temperature_help', 'local_aichat'),
        '0.4',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/maxtokens',
        new lang_string('setting_maxtokens', 'local_aichat'),
        new lang_string('setting_maxtokens_help', 'local_aichat'),
        700,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/historyturns',
        new lang_string('setting_historyturns', 'local_aichat'),
        new lang_string('setting_historyturns_help', 'local_aichat'),
        8,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/historychars',
        new lang_string('setting_historychars', 'local_aichat'),
        new lang_string('setting_historychars_help', 'local_aichat'),
        6000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/maxmessagechars',
        new lang_string('setting_maxmessagechars', 'local_aichat'),
        new lang_string('setting_maxmessagechars_help', 'local_aichat'),
        2000,
        PARAM_INT
    ));

    // === Quota par élève ===
    $settings->add(new admin_setting_heading(
        'local_aichat/quota_heading',
        new lang_string('quota_heading', 'local_aichat'),
        new lang_string('quota_heading_desc', 'local_aichat')
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/quota_window_hours',
        new lang_string('setting_quotawindow', 'local_aichat'),
        new lang_string('setting_quotawindow_help', 'local_aichat'),
        \local_aichat\quota::DEFAULT_WINDOW_HOURS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/quota_tokens',
        new lang_string('setting_quotatokens', 'local_aichat'),
        new lang_string('setting_quotatokens_help', 'local_aichat'),
        \local_aichat\quota::DEFAULT_TOKENS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/quota_messages',
        new lang_string('setting_quotamessages', 'local_aichat'),
        new lang_string('setting_quotamessages_help', 'local_aichat'),
        \local_aichat\quota::DEFAULT_MESSAGES,
        PARAM_INT
    ));

    // === Interface et conservation ===
    $settings->add(new admin_setting_heading(
        'local_aichat/misc_heading',
        new lang_string('misc_heading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aichat/studentnotice',
        new lang_string('setting_studentnotice', 'local_aichat'),
        new lang_string('setting_studentnotice_help', 'local_aichat'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/pollintervalms',
        new lang_string('setting_pollinterval', 'local_aichat'),
        new lang_string('setting_pollinterval_help', 'local_aichat'),
        1500,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/retentiondays',
        new lang_string('setting_retention', 'local_aichat'),
        new lang_string('setting_retention_help', 'local_aichat'),
        180,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
