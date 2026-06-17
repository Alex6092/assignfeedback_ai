<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Observateurs d'événements de cycle de vie.
 *
 *   - course_module_deleted : si on supprime le devoir d'une mission depuis la
 *     page du cours, on réconcilie (suppression de la ligne mission + rollback
 *     du projet) pour ne JAMAIS laisser de ligne orpheline.
 *   - course_deleted : purge de toutes les données du plugin pour le cours.
 */
$observers = array(
    array(
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_aimissions\observer::course_module_deleted',
        'internal'  => false, // hors transaction : la suppression du cm est déjà committée
    ),
    array(
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_aimissions\observer::course_deleted',
        'internal'  => false,
    ),
);
