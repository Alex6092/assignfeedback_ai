<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026053018;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'local_aiquizgen';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.8.0';
$plugin->dependencies = array(
    'local_aifeedback'     => 2026052905, // content_extractor, file d'attente, api::call
    'qtype_aishortanswer'  => 2026052901, // génération de questions à réponse courte IA
    'qtype_aiessay'        => 2026052902, // génération de compositions IA
);
