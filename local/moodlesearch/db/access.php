<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    // Chercher avec MoodleSearch (avec sa propre clé Tavily).
    'local/moodlesearch:use' => array(
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => array('user' => CAP_ALLOW),
    ),
    // Voir les recherches et les clics des élèves inscrits au cours.
    'local/moodlesearch:viewreport' => array(
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => array(
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ),
    ),
    // Voir les recherches de tout le site.
    'local/moodlesearch:viewsitereport' => array(
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => array('manager' => CAP_ALLOW),
    ),
    // Activer ou désactiver MoodleSearch par cohorte.
    'local/moodlesearch:manageaccess' => array(
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => array('manager' => CAP_ALLOW),
    ),
);
