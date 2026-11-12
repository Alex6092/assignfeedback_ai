<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'assignsubmission_notebook';
$plugin->version = 2026111100;
$plugin->requires = 2026042000;
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.1.0';
$plugin->dependencies = ['local_notebook' => 2026111100, 'assignfeedback_ai' => 2026111100];
