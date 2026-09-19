<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Tuteur IA : chat pédagogique en streaming pour les élèves, sur la page des
 * devoirs (et, à terme, des tentatives de test).
 *
 * Réutilise :
 *   - local_aifeedback : appels LLM (api::call / api::stream), pool de serveurs,
 *     file de jobs (run_job) pour la génération du brief pédagogique.
 *   - assignfeedback_ai (runtime, OPTIONNEL) : compétences du devoir et corrigé
 *     servant de base au brief — le corrigé lui-même n'est JAMAIS transmis au
 *     tuteur.
 */
$plugin->version   = 2026091906;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4 (hook before_footer_html_generation)
$plugin->component = 'local_aichat';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.5.1';
$plugin->dependencies = array(
    // Dépendance DURE : couche LLM partagée + pool de serveurs + streaming
    // (>= 1.11.0 pour l'appel d'outils dans api::stream).
    'local_aifeedback' => 2026091900,
    // Dépendance OPTIONNELLE (détectée au runtime, PAS listée ici) :
    //   - assignfeedback_ai : compétences + corrigé source du brief pédagogique.
);
