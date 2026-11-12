<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026111100;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'assignfeedback_ai';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '2.1.0';      // Notebook snapshots and combined tests/AI scores.
$plugin->dependencies = array(
    // >= 2.0.0 : file adossée au load balancer (async_handler, jobs, request).
    'local_aifeedback' => 2026103000,
);
