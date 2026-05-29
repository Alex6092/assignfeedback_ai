<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version    = 2026052902;
$plugin->requires   = 2023042400; // Moodle 4.2
$plugin->component  = 'qtype_aiessay';
$plugin->maturity   = MATURITY_ALPHA;
$plugin->release    = '0.2.1';
$plugin->dependencies = array(
    'local_aifeedback' => 2026052900,
);
