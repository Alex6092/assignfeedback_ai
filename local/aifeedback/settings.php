<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_aifeedback',
        new lang_string('pluginname', 'local_aifeedback'));

    // === Serveur IA (load balancer d'inférence) ===
    // Toutes les générations (corrections, tuteur, quiz, missions…) passent
    // par le load balancer, qui les met en file et les répartit entre les
    // serveurs IA. Seules les activités configurées sur une API externe
    // (surcharge d'URL) l'appellent directement.
    $settings->add(new admin_setting_heading(
        'local_aifeedback/lb_heading',
        new lang_string('lb_heading', 'local_aifeedback'),
        get_string('lb_heading_desc', 'local_aifeedback', (object)array(
            'callback' => \local_aifeedback\lb\client::default_callback_url(),
            'base'     => dirname(\local_aifeedback\lb\client::default_callback_url()) . '/',
        )) . ' ' . html_writer::link(new moodle_url('/local/aifeedback/servers.php'),
            get_string('servers_link', 'local_aifeedback'))
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/lb_url',
        new lang_string('lb_url', 'local_aifeedback'),
        new lang_string('lb_url_help', 'local_aifeedback'),
        '',
        PARAM_URL
    ));

    $settings->add(new \local_aifeedback\admin\encrypted_password(
        'local_aifeedback/lb_apikey',
        new lang_string('lb_apikey', 'local_aifeedback'),
        new lang_string('lb_apikey_help', 'local_aifeedback'),
        ''
    ));

    $settings->add(new \local_aifeedback\admin\encrypted_password(
        'local_aifeedback/lb_callbacksecret',
        new lang_string('lb_callbacksecret', 'local_aifeedback'),
        new lang_string('lb_callbacksecret_help', 'local_aifeedback'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aifeedback/lb_callbackurl',
        new lang_string('lb_callbackurl', 'local_aifeedback'),
        get_string('lb_callbackurl_help', 'local_aifeedback',
            \local_aifeedback\lb\client::default_callback_url()),
        '',
        PARAM_URL
    ));

    // Modèle des corrections IA : devoirs et questions de quiz, et repli de
    // tous les plugins IA qui n'ont pas leur propre modèle.
    $settings->add(new \local_aifeedback\admin\lb_model_setting(
        'local_aifeedback/model_feedback',
        new lang_string('model_feedback', 'local_aifeedback'),
        new lang_string('model_feedback_help', 'local_aifeedback')
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aifeedback/defaultsystemprompt',
        new lang_string('defaultsystemprompt', 'local_aifeedback'),
        new lang_string('defaultsystemprompt_help', 'local_aifeedback'),
        '',
        PARAM_TEXT
    ));

    // Supplément de tokens pour les modèles à raisonnement : leur réflexion
    // est décomptée du même max_tokens que la réponse.
    $settings->add(new admin_setting_configtext(
        'local_aifeedback/reasoning_budget',
        new lang_string('reasoning_budget', 'local_aifeedback'),
        new lang_string('reasoning_budget_help', 'local_aifeedback'),
        0,
        PARAM_INT
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

    // Page d'état du serveur IA (load balancer : santé, capacité par modèle,
    // consommation ; jobs Moodle en cours).
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_aifeedback_servers',
        new lang_string('servers_page', 'local_aifeedback'),
        new moodle_url('/local/aifeedback/servers.php'),
        'moodle/site:config'
    ));
}
