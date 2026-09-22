<?php
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    // Purge des recherches et des clics plus anciens que la durée de conservation.
    array(
        'classname' => '\local_moodlesearch\task\purge_logs',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ),
);
