<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Réglages d'administration de local_aimissions.
 *
 * La connexion LLM (URL, clé, modèle global) est gérée par local_aifeedback :
 * on ne duplique rien ici. Seuls quelques réglages propres à la génération
 * de missions sont exposés.
 */
if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_aimissions',
        get_string('settings_heading', 'local_aimissions')
    );

    $settings->add(new admin_setting_heading(
        'local_aimissions/intro',
        '',
        get_string('settings_intro', 'local_aimissions')
    ));

    // Modèle de génération (vide = modèle global local_aifeedback).
    $settings->add(new admin_setting_configtext(
        'local_aimissions/model',
        get_string('setting_model', 'local_aimissions'),
        get_string('setting_model_desc', 'local_aimissions'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Plafond de sprints par projet.
    $settings->add(new admin_setting_configtext(
        'local_aimissions/maxsprints',
        get_string('setting_maxsprints', 'local_aimissions'),
        get_string('setting_maxsprints_desc', 'local_aimissions'),
        '20',
        PARAM_INT
    ));

    // Code de la compétence EFE « Communiquer » (ex. C01) pour l'évaluation de
    // la communication client. Laisser vide pour désactiver cette évaluation.
    $settings->add(new admin_setting_configtext(
        'local_aimissions/communication_competency',
        get_string('setting_commcomp', 'local_aimissions'),
        get_string('setting_commcomp_desc', 'local_aimissions'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);
}
