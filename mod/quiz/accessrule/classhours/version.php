<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Règle d'accès des tests restreints aux heures de cours : compte à rebours
 * jusqu'à la fin du créneau, envoi automatique de la tentative, et pas de
 * nouvelle tentative pendant la tolérance après le créneau.
 */
$plugin->component = 'quizaccess_classhours';
$plugin->version   = 2026093000;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.2.0';
$plugin->dependencies = array(
    'local_classhours'        => 2026093000,
    'availability_classhours' => 2026093000,
);
