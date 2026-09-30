<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Supervised activity';
$string['title'] = 'Supervised activity';
$string['description'] = 'Access only when the teacher opens the activity in class ("Supervised activities" page).';
$string['privacy:metadata'] = 'The Supervised activity condition does not store any personal data.';

// Description shown to users.
$string['desc_closed'] = 'Open only when the teacher starts it <strong>in class</strong>';
$string['desc_open'] = '<strong>Opened</strong> by the teacher';
$string['desc_open_until'] = '<strong>Opened</strong> by the teacher until <strong>{$a}</strong>';
$string['desc_not'] = 'Outside a <strong>supervised session</strong>';
$string['desc_full'] = '<strong>Supervised activity</strong>: opened by the teacher in class';
$string['state_closed'] = 'currently closed';
$string['state_open'] = 'currently open';
$string['state_open_until'] = 'open until {$a}';
$string['manage_link'] = 'Open / close';

// Access restriction form.
$string['form_label'] = 'Supervised activity';
$string['form_help'] = 'Closed until the teacher opens it.';

// Session code and linked activities.
$string['desc_code'] = '<strong>Open</strong>: enter the session code shown in class to start.';
$string['code_link'] = 'Enter the code';
$string['desc_lock'] = 'Unavailable during the supervised activity {$a}';
$string['desc_lock_not'] = 'Only during the supervised activity {$a}';
$string['desc_lock_missing'] = 'Linked to a deleted supervised activity (no effect)';
$string['form_lock'] = 'Closed during the supervised activity "{$a}"';
