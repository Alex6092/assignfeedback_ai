<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_aichat\observer::course_module_deleted',
        'internal'  => false,
    ),
);
