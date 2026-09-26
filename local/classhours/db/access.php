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
);
