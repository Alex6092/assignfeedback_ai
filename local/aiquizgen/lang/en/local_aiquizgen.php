<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']         = 'AI test generator';
$string['generate_menu']      = 'Generate an AI test';
$string['comingsoon']         = 'The generation form will be available in the next development step. The plumbing (capability, navigation, database) is already in place.';

// Capability
$string['aiquizgen:generate'] = 'Generate an AI test from a lesson or a PDF';

// Privacy
$string['privacy:metadata']   = 'The local_aiquizgen plugin does not store personal data directly. Sources provided by the teacher (Moodle lesson, PDF) are forwarded to the LLM through local_aifeedback.';
