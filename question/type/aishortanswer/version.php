<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version    = 2026103000;
$plugin->requires   = 2023042400; // Moodle 4.2
$plugin->component  = 'qtype_aishortanswer';
$plugin->maturity   = MATURITY_ALPHA;
$plugin->release    = '0.2.0';      // Correction via le load balancer d'inférence
$plugin->dependencies = array(
    // >= 2.0.0 : quiz_grader en handler asynchrone (load balancer).
    'local_aifeedback' => 2026103000,
);
