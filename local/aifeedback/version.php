<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026103000;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'local_aifeedback';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '2.0.0';      // Load balancer d'inférence (jobs asynchrones + callbacks)
