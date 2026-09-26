<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Contrôle des tentatives : repère les tentatives de test et les remises de
 * devoir à examiner (faites hors des heures de cours, trop vite par rapport à
 * la classe, texte apparu trop vite pour avoir été tapé...) et permet de
 * supprimer celles que l'enseignant juge non légitimes.
 *
 * Aucune dépendance dure. Détectés à l'exécution :
 *   - local_classhours : indicateur « hors créneau » ;
 *   - local_aifeedback : corrections IA en attente annulées à la suppression ;
 *   - assignfeedback_ai : feedback IA effacé avec la remise.
 */
$plugin->component = 'local_attemptcheck';
$plugin->version   = 2026092700;       // YYYYMMDDXX
$plugin->requires  = 2024042200;       // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
