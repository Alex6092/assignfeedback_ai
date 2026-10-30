<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026103000;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'assignfeedback_ai';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '2.0.0';      // Correction via le load balancer d'inférence (jobs asynchrones)
$plugin->dependencies = array(
    // >= 2.0.0 : file adossée au load balancer (async_handler, jobs, request).
    'local_aifeedback' => 2026103000,
);
