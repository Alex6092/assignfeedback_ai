<?php
defined('MOODLE_INTERNAL') || die();

$functions = array(
    // Rafraîchissement du bloc et de la page des activités surveillées.
    'local_classhours_supervised_refresh' => array(
        'classname'   => 'local_classhours\external\supervised_refresh',
        'description' => 'État des activités surveillées (bloc, page de pilotage).',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
    ),
);
