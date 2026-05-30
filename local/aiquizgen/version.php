<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026053016;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'local_aiquizgen';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.6.0';
$plugin->dependencies = array(
    'local_aifeedback' => 2026052905, // content_extractor, file d'attente, api::call
);
