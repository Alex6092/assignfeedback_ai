<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'assignfeedback/ai:generate' => array(
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_MODULE,
        'archetypes'  => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
);
