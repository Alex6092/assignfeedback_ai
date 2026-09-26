<?php
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    // Clôture des tentatives dont le créneau est terminé.
    array(
        'classname' => '\quizaccess_classhours\task\close_expired_attempts',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ),
);
