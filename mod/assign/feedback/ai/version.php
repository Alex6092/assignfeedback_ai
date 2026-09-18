<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026091800;
$plugin->requires  = 2023042400; // Moodle 4.2
$plugin->component = 'assignfeedback_ai';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '1.8.2';
$plugin->dependencies = array(
    // >= 1.8.0 : prompt::score_scale_suffix / integrity_suffix + scoring::reconcile.
    'local_aifeedback' => 2026091500,
);
