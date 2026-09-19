<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    // Brief à (re)générer après l'enregistrement d'une activité. Priorité plus
    // basse que l'observateur de assignfeedback_ai (9999) : à la création d'un
    // devoir, c'est lui qui enregistre le corrigé, après la validation de la
    // transaction ; le brief doit être évalué ensuite.
    array(
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_aichat\observer::course_module_saved',
        'priority'  => 0,
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_aichat\observer::course_module_saved',
        'priority'  => 0,
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_aichat\observer::course_module_deleted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_aichat\observer::course_deleted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_reset_ended',
        'callback'  => '\local_aichat\observer::course_reset_ended',
        'internal'  => false,
    ),
);
