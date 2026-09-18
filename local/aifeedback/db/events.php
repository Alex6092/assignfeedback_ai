<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Observer partagé : capte la soumission d'une tentative de quiz pour tous les
 * types de question corrigés par IA (découverte automatique via quiz_grader).
 */
$observers = array(
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_aifeedback\observer::attempt_submitted',
        'priority'  => 9999,
        'internal'  => false,
    ),
    // Nettoyage des corrections IA dont la tentative a été effacée.
    array(
        'eventname' => '\core\event\course_reset_ended',
        'callback'  => '\local_aifeedback\observer::purge_orphan_gradings',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_aifeedback\observer::purge_orphan_gradings',
        'internal'  => false,
    ),
);
