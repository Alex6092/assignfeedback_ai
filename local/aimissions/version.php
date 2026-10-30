<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Générateur de missions client (cahiers des charges) progressives et
 * individualisées, déposées comme devoirs Moodle et corrigées par l'IA.
 *
 * Réutilise :
 *   - local_aifeedback : file de jobs asynchrones confiés au load balancer
 *     d'inférence (une génération par job).
 *   - assignfeedback_ai (runtime) : correction automatique des devoirs générés.
 *   - local_efenotes  (runtime, OPTIONNEL) : report des compétences vers EFE.
 */
$plugin->version   = 2026103000;       // YYYYMMDDXX
$plugin->requires  = 2023042400;       // Moodle 4.2
$plugin->component = 'local_aimissions';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.9.0';          // Générations asynchrones via le load balancer, évaluations de communication en file
$plugin->dependencies = array(
    // Dépendance DURE : couche LLM partagée + file de jobs asynchrones
    // (>= 2.0.0 : async_handler, jobs adossés au load balancer).
    'local_aifeedback' => 2026103000,
    // Dépendances OPTIONNELLES (détectées au runtime, PAS listées ici) :
    //   - assignfeedback_ai : correction IA des devoirs générés.
    //   - local_efenotes    : report des compétences évaluées vers EFE.
);
