<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
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
