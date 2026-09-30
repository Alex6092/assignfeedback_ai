<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    // Ajouter le bloc à son tableau de bord.
    'block/supervised:myaddinstance' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => array('user' => CAP_ALLOW),
        'clonepermissionsfrom' => 'moodle/my:manageblocks',
    ),
    // Ajouter le bloc à une page de cours.
    'block/supervised:addinstance' => array(
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => array('editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW),
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ),
);
