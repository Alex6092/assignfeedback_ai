<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Règle d'accès des tests restreints aux heures de cours, et des tests
 * surveillés (ouverts par l'enseignant) : compte à rebours
 * jusqu'à la fin du créneau, envoi automatique de la tentative, et pas de
 * nouvelle tentative pendant la tolérance après le créneau.
 */
$plugin->component = 'quizaccess_classhours';
$plugin->version   = 2026100100;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.3.1';          // Tests surveillés (verrous d'activités liées ignorés)
$plugin->dependencies = array(
    'local_classhours'        => 2026100100,
    'availability_classhours' => 2026093000,
);
