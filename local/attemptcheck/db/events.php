<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    // Analyse (et notification) après chaque remise.
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_attemptcheck\observer::attempt_submitted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => '\local_attemptcheck\observer::assessable_submitted',
        'internal'  => false,
    ),
    // Nettoyage.
    array(
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback'  => '\local_attemptcheck\observer::attempt_deleted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_attemptcheck\observer::course_module_deleted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_attemptcheck\observer::course_deleted',
        'internal'  => false,
    ),
);
