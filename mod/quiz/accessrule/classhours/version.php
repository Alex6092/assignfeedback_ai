<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Règle d'accès des tests restreints aux heures de cours, et des tests
 * surveillés (ouverts par l'enseignant) : compte à rebours
 * jusqu'à la fin du créneau, envoi automatique de la tentative, et pas de
 * nouvelle tentative pendant la tolérance après le créneau.
 */
$plugin->component = 'quizaccess_classhours';
$plugin->version   = 2026093001;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.3.0';          // Tests surveillés
$plugin->dependencies = array(
    'local_classhours'        => 2026093001,
    'availability_classhours' => 2026093000,
);
