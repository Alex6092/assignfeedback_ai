<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_configcheckbox(
        'assignfeedback_ai/default',
        new lang_string('default', 'assignfeedback_ai'),
        new lang_string('default_help', 'assignfeedback_ai'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_ai/apiurl',
        new lang_string('apiurl', 'assignfeedback_ai'),
        new lang_string('apiurl_help', 'assignfeedback_ai'),
        'http://localhost:1234/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_ai/model',
        new lang_string('model', 'assignfeedback_ai'),
        new lang_string('model_help', 'assignfeedback_ai'),
        'qwen3.5-9b-instruct',
        PARAM_TEXT
    ));

    $settings->add(new \assignfeedback_ai\admin\encrypted_password(
        'assignfeedback_ai/apikey',
        new lang_string('apikey', 'assignfeedback_ai'),
        new lang_string('apikey_help', 'assignfeedback_ai'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'assignfeedback_ai/defaultsystemprompt',
        new lang_string('defaultsystemprompt', 'assignfeedback_ai'),
        new lang_string('defaultsystemprompt_help', 'assignfeedback_ai'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_ai/pdftotextpath',
        new lang_string('pdftotextpath', 'assignfeedback_ai'),
        new lang_string('pdftotextpath_help', 'assignfeedback_ai'),
        '',
        PARAM_RAW
    ));
}
