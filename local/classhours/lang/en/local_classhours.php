<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Class hours';
$string['privacy:metadata'] = 'The Class hours plugin only stores course timetables and activity settings, no personal data.';
$string['classhours:manage'] = 'Manage the course class hours and restricted activities';
$string['task_sync_efe'] = 'Class hours: automatic restriction of activities reporting to EFE';

// Site settings.
$string['settings_intro'] = 'The timetable is set in each course, from the "Class hours" link in the course navigation. Times are always in the server timezone.';
$string['setting_graceminutes'] = 'Grace period after a slot ends (minutes)';
$string['setting_graceminutes_desc'] = 'During these few minutes the activity stays accessible, so the automatic submission of a quiz and the last save of an assignment go through. No new quiz attempt can start during the grace period.';
$string['setting_efeauto_default'] = 'EFE option enabled by default';
$string['setting_efeauto_default_desc'] = 'Value of the "Automatically restrict activities reporting to EFE" option for courses that never set it. Without any configured slot, the option has no effect in a course.';

// Course page.
$string['menu'] = 'Class hours';
$string['intro'] = 'Activities restricted to class hours are only available to students during their slots. Times are the school\'s ({$a} timezone).';
$string['warn_availabilitydisabled'] = 'Access restrictions are disabled on this site: class hours do not apply. An administrator must turn on "Enable restricted access".';
$string['warn_conditiondisabled'] = 'The "Class hours" access condition (availability_classhours) is not installed or is disabled: no activity can be restricted.';
$string['allcourse'] = 'Whole course';
$string['unknowngroup'] = 'deleted group';
$string['strftimeslot'] = '%A %d %B at %H:%M';

$string['status_heading'] = 'Right now';
$string['status'] = 'Status';
$string['status_everyone'] = 'All students';
$string['status_nogroup'] = 'Students without a group';
$string['status_open'] = 'Open until {$a}';
$string['status_closed'] = 'Closed';
$string['status_next'] = 'next slot: {$a}';
$string['status_nonext'] = 'no slot in the next 60 days';
$string['offslot_link'] = 'See attempts made outside class hours';

$string['slots_heading'] = 'Weekly timetable';
$string['slots_none'] = 'No weekly slot yet.';
$string['weekday'] = 'Day';
$string['hours'] = 'Time';
$string['starttime'] = 'Start';
$string['endtime'] = 'End';
$string['slot_add'] = 'Add slot';
$string['slot_added'] = 'Slot added.';
$string['error_endbeforestart'] = 'The end must be after the start.';

$string['open_heading'] = 'Extra openings';
$string['open_help'] = 'A dated slot in addition to the timetable: catch-up session, moved lesson. It applies even during a closed period.';
$string['open_add'] = 'Add opening';
$string['timestart'] = 'Start';
$string['timeend'] = 'End';
$string['periodname'] = 'Label';

$string['closed_heading'] = 'Closed periods';
$string['closed_help'] = 'Holidays, work placement: during these days the weekly slots do not apply.';
$string['closed_add'] = 'Add period';
$string['datestart'] = 'First day';
$string['dateend'] = 'Last day';
$string['period_added'] = 'Period added.';
$string['deleted'] = 'Deleted.';

$string['activities_heading'] = 'Restricted activities';
$string['activities_help'] = 'Tick "Restrict" to limit an activity to class hours. You can also add the "During class hours" condition from the activity\'s access restrictions.';
$string['activities_none'] = 'No quiz or assignment in this course.';
$string['efeauto'] = 'Automatically restrict activities reporting to EFE';
$string['efeauto_help'] = 'Every activity with EFE reporting enabled is restricted to class hours, unless it is excluded below. The restriction is added and removed automatically when EFE reporting changes.';
$string['efeauto_noslots'] = 'The option is on but has no effect until a slot is defined.';
$string['sync_now'] = 'Synchronise now';
$string['sync_done'] = 'Synchronisation done: {$a} activity(ies) updated.';
$string['saved'] = 'Changes saved.';
$string['efe'] = 'EFE';
$string['restrict'] = 'Restrict';
$string['exclude'] = 'Exclude from the EFE option';
$string['state_manual'] = 'Restricted';
$string['state_auto'] = 'Restricted (auto EFE)';
$string['state_nested'] = 'Restricted (custom rule)';
$string['state_excluded'] = 'Excluded';
$string['state_free'] = 'Free';
