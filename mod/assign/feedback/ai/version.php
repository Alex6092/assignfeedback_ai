<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026052902;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'assignfeedback_ai';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '1.6.1';
$plugin->dependencies = array(
    'local_aifeedback' => 2026052906,
);
