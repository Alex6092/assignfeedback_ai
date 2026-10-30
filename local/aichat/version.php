<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Tuteur IA : chat pédagogique en streaming pour les élèves, sur la page des
 * devoirs (et, à terme, des tentatives de test).
 *
 * Réutilise :
 *   - local_aifeedback : appels LLM (api::stream via le load balancer
 *     d'inférence), file de jobs asynchrones pour la modération et le brief
 *     pédagogique.
 *   - assignfeedback_ai (runtime, OPTIONNEL) : compétences du devoir et corrigé
 *     servant de base au brief — le corrigé lui-même n'est JAMAIS transmis au
 *     tuteur.
 */
$plugin->version   = 2026103000;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4 (hook before_footer_html_generation)
$plugin->component = 'local_aichat';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.8.0';          // File d'attente et streaming via le load balancer
$plugin->dependencies = array(
    // Dépendance DURE : couche LLM partagée + load balancer + streaming
    // (>= 2.0.0 : X-Job-ID, rang dans la file, jobs asynchrones).
    'local_aifeedback' => 2026103000,
    // Dépendance OPTIONNELLE (détectée au runtime, PAS listée ici) :
    //   - assignfeedback_ai : compétences + corrigé source du brief pédagogique.
);
