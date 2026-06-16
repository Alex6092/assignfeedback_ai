<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Capacités du plugin local_aimissions.
 *
 *   - generate : lancer une génération de missions (enseignant éditeur).
 *   - review   : relire / publier les missions générées (enseignant éditeur).
 *   - askclient: poser une question au client IA via un ticket (étudiant).
 */
$capabilities = array(

    'local/aimissions:generate' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

    'local/aimissions:review' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

    'local/aimissions:askclient' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
);
