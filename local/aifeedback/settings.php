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

    // === Pool de serveurs LLM ===
    // Les appels régulés (tuteur interactif, et à terme la file de corrections)
    // sont répartis entre ces serveurs selon leur simultanéité maximale et les
    // usages qu'ils acceptent. L'emplacement 1 est le serveur configuré
    // ci-dessus ; les emplacements 2 et 3 sont facultatifs.
    $settings->add(new admin_setting_heading(
        'local_aifeedback/pool_heading',
        new lang_string('pool_heading', 'local_aifeedback'),
        new lang_string('pool_heading_desc', 'local_aifeedback')
    ));

    for ($slot = 1; $slot <= \local_aifeedback\pool::MAX_SERVERS; $slot++) {
        $p = 'server' . $slot . '_';

        $settings->add(new admin_setting_heading(
            'local_aifeedback/' . $p . 'heading',
            get_string('server_heading', 'local_aifeedback', $slot),
            ($slot === 1) ? new lang_string('server1_desc', 'local_aifeedback') : ''
        ));

        // L'emplacement 1 réutilise apiurl / model / apikey déjà saisis plus haut.
        if ($slot > 1) {
            $settings->add(new admin_setting_configtext(
                'local_aifeedback/' . $p . 'apiurl',
                new lang_string('server_apiurl', 'local_aifeedback'),
                new lang_string('server_apiurl_help', 'local_aifeedback'),
                '',
                PARAM_URL
            ));
            $settings->add(new admin_setting_configtext(
                'local_aifeedback/' . $p . 'model',
                new lang_string('server_model', 'local_aifeedback'),
                new lang_string('server_model_help', 'local_aifeedback'),
                '',
                PARAM_TEXT
            ));
            $settings->add(new \local_aifeedback\admin\encrypted_password(
                'local_aifeedback/' . $p . 'apikey',
                new lang_string('server_apikey', 'local_aifeedback'),
                new lang_string('server_apikey_help', 'local_aifeedback'),
                ''
            ));
        }

        $settings->add(new admin_setting_configtext(
            'local_aifeedback/' . $p . 'maxconcurrency',
            new lang_string('server_maxconcurrency', 'local_aifeedback'),
            new lang_string('server_maxconcurrency_help', 'local_aifeedback'),
            1,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_aifeedback/' . $p . 'use_feedback',
            new lang_string('server_use_feedback', 'local_aifeedback'),
            new lang_string('server_use_feedback_help', 'local_aifeedback'),
            ($slot === 1) ? 1 : 0
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_aifeedback/' . $p . 'use_tutor',
            new lang_string('server_use_tutor', 'local_aifeedback'),
            new lang_string('server_use_tutor_help', 'local_aifeedback'),
            ($slot === 1) ? 1 : 0
        ));
    }

    $settings->add(new admin_setting_configcheckbox(
        'local_aifeedback/stream_usage',
        new lang_string('stream_usage', 'local_aifeedback'),
        new lang_string('stream_usage_help', 'local_aifeedback'),
        0
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

    // Réduction des images avant envoi (tokens + robustesse des backends).
    $settings->add(new admin_setting_configtext(
        'local_aifeedback/imagemaxdimension',
        new lang_string('imagemaxdimension', 'local_aifeedback'),
        new lang_string('imagemaxdimension_help', 'local_aifeedback'),
        \local_aifeedback\content_extractor::DEFAULT_IMAGE_MAX_DIMENSION,
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
