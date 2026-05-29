<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_aifeedback',
        new lang_string('pluginname', 'local_aifeedback'));

    // === API ===
    $settings->add(new admin_setting_heading(
        'local_aifeedback/api_heading',
        new lang_string('api_heading', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/apiurl',
        new lang_string('apiurl', 'local_aifeedback'),
        new lang_string('apiurl_help', 'local_aifeedback'),
        'http://localhost:1234/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/model',
        new lang_string('model', 'local_aifeedback'),
        new lang_string('model_help', 'local_aifeedback'),
        'qwen3.5-9b-instruct',
        PARAM_TEXT
    ));

    $settings->add(new \local_aifeedback\admin\encrypted_password(
        'local_aifeedback/apikey',
        new lang_string('apikey', 'local_aifeedback'),
        new lang_string('apikey_help', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aifeedback/defaultsystemprompt',
        new lang_string('defaultsystemprompt', 'local_aifeedback'),
        new lang_string('defaultsystemprompt_help', 'local_aifeedback'),
        '',
        PARAM_TEXT
    ));

    // === Accessibilité ===
    $settings->add(new admin_setting_heading(
        'local_aifeedback/accessibility_heading',
        new lang_string('accessibility_heading', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aifeedback/spelling_tolerance',
        new lang_string('spelling_tolerance', 'local_aifeedback'),
        new lang_string('spelling_tolerance_help', 'local_aifeedback'),
        1
    ));

    // === Vision ===
    $settings->add(new admin_setting_heading(
        'local_aifeedback/vision_heading',
        new lang_string('vision_heading', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aifeedback/vision_enabled',
        new lang_string('vision_enabled', 'local_aifeedback'),
        new lang_string('vision_enabled_help', 'local_aifeedback'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/maximagespersubmission',
        new lang_string('maximagespersubmission', 'local_aifeedback'),
        new lang_string('maximagespersubmission_help', 'local_aifeedback'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/imagemindimension',
        new lang_string('imagemindimension', 'local_aifeedback'),
        new lang_string('imagemindimension_help', 'local_aifeedback'),
        200,
        PARAM_INT
    ));

    // === Binaires externes ===
    $settings->add(new admin_setting_heading(
        'local_aifeedback/binaries_heading',
        new lang_string('binaries_heading', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/pdftotextpath',
        new lang_string('pdftotextpath', 'local_aifeedback'),
        new lang_string('pdftotextpath_help', 'local_aifeedback'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/pdftoppmpath',
        new lang_string('pdftoppmpath', 'local_aifeedback'),
        new lang_string('pdftoppmpath_help', 'local_aifeedback'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/pdfimagespath',
        new lang_string('pdfimagespath', 'local_aifeedback'),
        new lang_string('pdfimagespath_help', 'local_aifeedback'),
        '',
        PARAM_RAW
    ));

    // === Quiz / questions IA (consommé par qtype_aiessay & co dans une phase ultérieure) ===
    $settings->add(new admin_setting_heading(
        'local_aifeedback/quiz_heading',
        new lang_string('quiz_heading', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/max_attempts_to_grade',
        new lang_string('max_attempts_to_grade', 'local_aifeedback'),
        new lang_string('max_attempts_to_grade_help', 'local_aifeedback'),
        0,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
