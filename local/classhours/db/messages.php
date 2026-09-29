<?php
defined('MOODLE_INTERNAL') || die();

$messageproviders = array(
    // Un élève demande un accès exceptionnel : prévient les enseignants.
    'accessrequest' => array(
        'capability' => 'local/classhours:grantaccess',
        'defaults'   => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ),
    ),
    // Décision de l'enseignant : prévient l'élève.
    'accessdecision' => array(
        'capability' => 'local/classhours:requestaccess',
        'defaults'   => array(
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
        ),
    ),
);
