<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Condition d'accès « Activité surveillée » : l'activité n'est ouverte que
 * lorsque l'enseignant l'ouvre en classe (pour tout le cours, un groupe ou un
 * élève). Les ouvertures, la page de pilotage et le ramassage du travail en
 * cours sont dans local_classhours.
 */
$plugin->component = 'availability_supervised';
$plugin->version   = 2026093001;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
$plugin->dependencies = array(
    // Ouvertures (gate), lecture du travail noté (access).
    'local_classhours' => 2026093001,
);
