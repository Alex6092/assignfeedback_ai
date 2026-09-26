<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_classhours\observer::course_deleted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\group_deleted',
        'callback'  => '\local_classhours\observer::group_deleted',
        'internal'  => false,
    ),
    array(
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_classhours\observer::course_module_deleted',
        'internal'  => false,
    ),
);
