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

    // === Modération ===
    $settings->add(new admin_setting_heading(
        'local_aichat/moderation_heading',
        new lang_string('moderation_heading', 'local_aichat'),
        new lang_string('moderation_heading_desc', 'local_aichat')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/moderation_enabled',
        new lang_string('setting_moderation', 'local_aichat'),
        new lang_string('setting_moderation_help', 'local_aichat'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/moderation_notify',
        new lang_string('setting_moderationnotify', 'local_aichat'),
        new lang_string('setting_moderationnotify_help', 'local_aichat'),
        1
    ));

    // === Recherche Web ===
    $settings->add(new admin_setting_heading(
        'local_aichat/websearch_heading',
        new lang_string('websearch_heading', 'local_aichat'),
        get_string('websearch_heading_desc', 'local_aichat') . ' '
            . html_writer::link(new moodle_url('/local/aichat/websearch.php'),
                get_string('websearch_link', 'local_aichat'))
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/websearch_enabled',
        new lang_string('setting_websearch', 'local_aichat'),
        new lang_string('setting_websearch_help', 'local_aichat'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'local_aichat/websearch_provider',
        new lang_string('setting_wsprovider', 'local_aichat'),
        new lang_string('setting_wsprovider_help', 'local_aichat'),
        'brave',
        array('brave' => 'Brave Search')
    ));

    // Chiffrée en base, jamais réaffichée dans le formulaire.
    $settings->add(new admin_setting_encryptedpassword(
        'local_aichat/websearch_apikey',
        new lang_string('setting_wsapikey', 'local_aichat'),
        new lang_string('setting_wsapikey_help', 'local_aichat')
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_cap',
        new lang_string('setting_wscap', 'local_aichat'),
        new lang_string('setting_wscap_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_CAP,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_peruser',
        new lang_string('setting_wsperuser', 'local_aichat'),
        new lang_string('setting_wsperuser_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_PERUSER,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_maxcalls',
        new lang_string('setting_wsmaxcalls', 'local_aichat'),
        new lang_string('setting_wsmaxcalls_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_MAXCALLS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_maxresults',
        new lang_string('setting_wsmaxresults', 'local_aichat'),
        new lang_string('setting_wsmaxresults_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_MAXRESULTS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_timeout',
        new lang_string('setting_wstimeout', 'local_aichat'),
        new lang_string('setting_wstimeout_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_TIMEOUT,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_cachedays',
        new lang_string('setting_wscachedays', 'local_aichat'),
        new lang_string('setting_wscachedays_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_CACHEDAYS,
        PARAM_INT
    ));

    // Mode « recherche de matériel » : lecture des pages et datasheets.
    $settings->add(new admin_setting_heading(
        'local_aichat/material_heading',
        new lang_string('material_heading', 'local_aichat'),
        new lang_string('material_heading_desc', 'local_aichat')
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_toolcalls',
        new lang_string('setting_wstoolcalls', 'local_aichat'),
        new lang_string('setting_wstoolcalls_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_TOOLCALLS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_readsperuser',
        new lang_string('setting_wsreadsperuser', 'local_aichat'),
        new lang_string('setting_wsreadsperuser_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_READSPERUSER,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_maxmb',
        new lang_string('setting_wsmaxmb', 'local_aichat'),
        new lang_string('setting_wsmaxmb_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_MAXMB,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_readtimeout',
        new lang_string('setting_wsreadtimeout', 'local_aichat'),
        new lang_string('setting_wsreadtimeout_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_READTIMEOUT,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/websearch_pagecachehours',
        new lang_string('setting_wspagecachehours', 'local_aichat'),
        new lang_string('setting_wspagecachehours_help', 'local_aichat'),
        \local_aichat\websearch\manager::DEFAULT_PAGECACHEHOURS,
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

    // État et tests de la recherche Web (lien depuis la rubrique ci-dessus).
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_aichat_websearch',
        new lang_string('ws_page', 'local_aichat'),
        new moodle_url('/local/aichat/websearch.php'),
        'moodle/site:config'
    ));
}
