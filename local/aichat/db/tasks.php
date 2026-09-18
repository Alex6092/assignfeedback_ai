<?php
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    array(
        'classname' => 'local_aichat\task\purge',
        'blocking'  => 0,
        'minute'    => 'R',
        'hour'      => '3',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ),
);
