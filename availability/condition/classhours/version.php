<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Condition d'accès « Pendant les heures de cours » : l'activité n'est
 * accessible que pendant les créneaux de classe de l'élève, réglés par cours
 * dans local_classhours.
 */
$plugin->component = 'availability_classhours';
$plugin->version   = 2026093000;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.2.0';
$plugin->dependencies = array(
    // Emploi du temps et calcul des créneaux.
    'local_classhours' => 2026093000,
);
