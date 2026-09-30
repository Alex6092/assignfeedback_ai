<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Bloc « Activités surveillées » : l'élève y trouve l'activité que
 * l'enseignant vient d'ouvrir en classe (tableau de bord, page du cours) ;
 * l'enseignant y ouvre et ferme les activités du cours en un clic, et voit sur
 * son tableau de bord celles qu'il a laissées ouvertes.
 */
$plugin->component = 'block_supervised';
$plugin->version   = 2026100100;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.2.0';          // Frise : faites, à rattraper, ouverte, à venir
$plugin->dependencies = array(
    // Ouvertures, affichage et service web de rafraîchissement.
    'local_classhours' => 2026100100,
);
