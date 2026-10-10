<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    // Lire un cours par le pont : réglages, contenus, banques de questions
    // (corrigés compris). Jamais de donnée d'élève.
    'local/aibridge:read' => array(
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),

    // Créer et modifier des cours et des activités par le pont (phase 3),
    // seulement dans les catégories bac à sable et si l'écriture est activée.
    'local/aibridge:write' => array(
        'riskbitmask'  => RISK_CONFIG | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes'   => array(
            'manager' => CAP_ALLOW,
        ),
    ),
);
