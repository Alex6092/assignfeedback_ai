<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(

    // Utiliser le tuteur IA sur une activité où il est activé.
    'local/aichat:use' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => array(
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

    // Activer et paramétrer le tuteur sur une activité (brief compris).
    'local/aichat:configure' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

    // Lire les conversations des élèves avec le tuteur.
    'local/aichat:viewconversations' => array(
        'captype'      => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'riskbitmask'  => RISK_PERSONAL,
        'archetypes'   => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
);
