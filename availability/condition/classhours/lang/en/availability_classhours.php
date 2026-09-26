<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Restriction by class hours';
$string['title'] = 'Class hours';
$string['description'] = 'Access only during the course class slots ("Class hours" page).';
$string['privacy:metadata'] = 'The Restriction by class hours plugin does not store any personal data.';

// Description shown to users.
$string['desc'] = 'During <strong>class hours</strong>';
$string['desc_slots'] = 'During class hours: <strong>{$a}</strong>';
$string['desc_not'] = 'Outside <strong>class hours</strong>';
$string['desc_not_slots'] = 'Outside class hours: <strong>{$a}</strong>';
$string['desc_noslots'] = '(no slot is configured yet)';
$string['desc_next'] = '— next slot: <strong>{$a}</strong>';

// Access restriction form.
$string['form_label'] = 'During class hours';
$string['configure'] = 'Set up the timetable';
$string['noslots_form'] = 'No slot is configured yet: the activity will stay closed to students.';
$string['efe_form'] = 'Added automatically for EFE reporting. To free this activity, tick "Exclude" on the Class hours page: otherwise the restriction comes back.';
