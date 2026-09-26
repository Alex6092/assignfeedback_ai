<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    // Consulter le contrôle des tentatives du cours, marquer une tentative
    // légitime, régler la durée minimale d'une activité.
    'local/attemptcheck:view' => array(
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
    // Supprimer une tentative de test ou effacer une remise de devoir depuis le
    // contrôle (la capacité Moodle de l'activité est exigée en plus).
    'local/attemptcheck:delete' => array(
        'riskbitmask'  => RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
    // Recevoir la notification d'une tentative à vérifier.
    'local/attemptcheck:notify' => array(
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ),
    ),
);
