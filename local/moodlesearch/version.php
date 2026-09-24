<?php
defined('MOODLE_INTERNAL') || die();

/**
 * MoodleSearch : moteur de recherche Web du lycée, SANS réponse générée par
 * l'IA — seulement des résultats, comme les moteurs d'avant.
 *
 * Réutilise :
 *   - local_aichat : clé Tavily personnelle de l'élève, client Tavily
 *     (safe_search imposé), plafond par clé et cache des recherches, partagés
 *     avec le Tuteur IA ;
 *   - block_opnsenseaccess (OPTIONNEL, détecté à l'exécution) : un clic sur un
 *     résultat demande l'ouverture du site pour la classe, sauf liste noire.
 */
$plugin->component = 'local_moodlesearch';
$plugin->version   = 2026092300;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4 (hook primary_extend)
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
$plugin->dependencies = array(
    // Clés personnelles, client Tavily (options de recherche), cache partagé.
    'local_aichat' => 2026092300,
);
