<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Leçons imprimables : une version imprimable (et enregistrable en PDF par le
 * navigateur) de chaque leçon, avec la mise en page du thème, pour garder une
 * trace hors ligne (panne de Moodle, fin de la formation).
 *
 * Activable cours par cours. Respecte la visibilité, les restrictions d'accès
 * et le mode examen des activités surveillées (tout passe par require_login).
 */
$plugin->component = 'local_lessonexport';
$plugin->version   = 2026101100;       // YYYYMMDDXX
$plugin->requires  = 2025041400;       // Moodle 5.0
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
