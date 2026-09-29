<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Heures de cours : certaines activités ne sont accessibles que pendant les
 * créneaux de classe du cours (emploi du temps par cours et par groupe,
 * ouvertures exceptionnelles, périodes fermées).
 *
 * Ce plugin porte les données et le calcul des créneaux. La restriction
 * elle-même est une condition d'accès (availability_classhours) ; la fin du
 * créneau pendant un test est gérée par quizaccess_classhours.
 *
 * Pont OPTIONNEL vers local_efenotes (détecté à l'exécution) : restriction
 * automatique des activités qui ont une remontée EFE.
 */
$plugin->component = 'local_classhours';
$plugin->version   = 2026093000;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4 (\core\clock via \core\di)
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.3.0';          // Lecture hors créneau, verrou de remise, accès ponctuel sur demande
