<?php
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    // Option EFE : rattrapage des activités configurées hors formulaire
    // (programme, restauration, duplication).
    array(
        'classname' => '\local_classhours\task\sync_efe',
        'blocking'  => 0,
        'minute'    => '*/10',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ),
    // Activités surveillées ouvertes avec une durée : fermeture et ramassage.
    array(
        'classname' => '\local_classhours\task\close_supervised',
        'blocking'  => 0,
        'minute'    => '*',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ),
);
