<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = ['local/notebook:manage' => [
    'riskbitmask' => RISK_XSS, 'captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
    'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
]];
