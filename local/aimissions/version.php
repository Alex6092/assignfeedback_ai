<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Générateur de missions client (cahiers des charges) progressives et
 * individualisées, déposées comme devoirs Moodle et corrigées par l'IA.
 *
 * Réutilise :
 *   - local_aifeedback : appels LLM (api::call) + file de jobs (run_job).
 *   - assignfeedback_ai (runtime) : correction automatique des devoirs générés.
 *   - local_efenotes  (runtime, OPTIONNEL) : report des compétences vers EFE.
 */
$plugin->version   = 2026060504;       // YYYYMMDDXX
$plugin->requires  = 2023042400;       // Moodle 4.2
$plugin->component = 'local_aimissions';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.5.0';
$plugin->dependencies = array(
    // Dépendance DURE : couche LLM partagée + file de jobs asynchrones.
    'local_aifeedback' => 2026052908,
    // Dépendances OPTIONNELLES (détectées au runtime, PAS listées ici) :
    //   - assignfeedback_ai : correction IA des devoirs générés.
    //   - local_efenotes    : report des compétences évaluées vers EFE.
);
