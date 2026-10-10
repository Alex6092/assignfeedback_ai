<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    // Imprimer (ou enregistrer en PDF) les leçons auxquelles on a accès.
    'local/lessonexport:view' => array(
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

    // Activer ou désactiver les leçons imprimables d'un cours.
    'local/lessonexport:manage' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
);
