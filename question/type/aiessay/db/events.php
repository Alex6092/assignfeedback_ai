<?php
defined('MOODLE_INTERNAL') || die();

$observers = array(
    array(
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\qtype_aiessay\observer::attempt_submitted',
        'priority'  => 9999,
        'internal'  => false,
    ),
);
