<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Réglages de MoodleSearch. Le moteur (clés, plafond par clé, cache, délai)
 * est celui du Tuteur IA : voir ses réglages « Recherche Web ».
 */
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_moodlesearch', get_string('pluginname', 'local_moodlesearch'));

    $settings->add(new admin_setting_heading('local_moodlesearch/intro', '',
        get_string('settings_intro', 'local_moodlesearch')));

    $settings->add(new admin_setting_configcheckbox('local_moodlesearch/enabled',
        get_string('setting_enabled', 'local_moodlesearch'),
        get_string('setting_enabled_desc', 'local_moodlesearch'), 0));

    $settings->add(new admin_setting_configcheckbox('local_moodlesearch/defaultaccess',
        get_string('setting_defaultaccess', 'local_moodlesearch'),
        get_string('setting_defaultaccess_desc', 'local_moodlesearch'), 1));

    $settings->add(new admin_setting_configselect('local_moodlesearch/perpage',
        get_string('setting_perpage', 'local_moodlesearch'),
        get_string('setting_perpage_desc', 'local_moodlesearch'), 10,
        array(5 => 5, 10 => 10, 15 => 15, 20 => 20)));

    $settings->add(new admin_setting_configtext('local_moodlesearch/maxperhour',
        get_string('setting_maxperhour', 'local_moodlesearch'),
        get_string('setting_maxperhour_desc', 'local_moodlesearch'), 30, PARAM_INT));

    $settings->add(new admin_setting_configtext('local_moodlesearch/country',
        get_string('setting_country', 'local_moodlesearch'),
        get_string('setting_country_desc', 'local_moodlesearch'), 'france', PARAM_TEXT));

    $settings->add(new admin_setting_configtextarea('local_moodlesearch/excludedomains',
        get_string('setting_excludedomains', 'local_moodlesearch'),
        get_string('setting_excludedomains_desc', 'local_moodlesearch'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('local_moodlesearch/retentiondays',
        get_string('setting_retentiondays', 'local_moodlesearch'),
        get_string('setting_retentiondays_desc', 'local_moodlesearch'), 365, PARAM_INT));

    $ADMIN->add('localplugins', $settings);
}

// Pages de gestion, visibles selon leur capacité (gestionnaires compris).
$ADMIN->add('localplugins', new admin_externalpage('local_moodlesearch_cohorts',
    get_string('cohorts_title', 'local_moodlesearch'),
    new moodle_url('/local/moodlesearch/cohorts.php'), 'local/moodlesearch:manageaccess'));
$ADMIN->add('localplugins', new admin_externalpage('local_moodlesearch_sitereport',
    get_string('report_sitetitle', 'local_moodlesearch'),
    new moodle_url('/local/moodlesearch/report.php'), 'local/moodlesearch:viewsitereport'));
