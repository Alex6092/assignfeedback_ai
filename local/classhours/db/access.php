<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    // Régler les heures de cours du cours et choisir les activités restreintes.
    'local/classhours:manage' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
        'clonepermissionsfrom' => 'moodle/course:update',
    ),

    // Demander un accès exceptionnel à une activité fermée (élève).
    'local/classhours:requestaccess' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'student' => CAP_ALLOW,
        ),
    ),

    // Accepter, refuser ou accorder un accès exceptionnel (enseignant).
    'local/classhours:grantaccess' => array(
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
);
