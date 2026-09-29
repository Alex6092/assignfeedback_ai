<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Class hours';
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
$string['status_now'] = 'Server time: <strong>{$a->time}</strong> ({$a->tz} timezone).';
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
$string['closed_course_heading'] = 'Periods specific to this course';
$string['useglobal'] = 'Use the site-wide closed periods';
$string['global_none'] = 'No site-wide closed period is defined.';
$string['global_manage'] = 'Manage site-wide closed periods';

// Site-wide closed periods (administration).
$string['global_heading'] = 'Site-wide closed periods';
$string['global_menu'] = 'Class hours: site-wide closed periods';
$string['global_intro'] = 'Holidays and other closures shared by the whole school. They apply to the courses that tick "Use the site-wide closed periods" on their Class hours page; each course can add others. Dates in the {$a} timezone.';
$string['setting_global_desc'] = 'Holidays and closures shared by all courses are entered on the <a href="{$a}">Site-wide closed periods</a> page.';
$string['setting_useglobal_default'] = 'Site-wide periods applied by default';
$string['setting_useglobal_default_desc'] = 'Value of the "Use the site-wide closed periods" box for courses that never set it.';
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

// Reading outside class hours and submission lock.
$string['submit_closed'] = 'Submissions are only possible during class hours. You can read your assignment and your feedback, but not change them now.';

// Exceptional access: capabilities and notifications.
$string['classhours:requestaccess'] = 'Request exceptional access to a closed activity';
$string['classhours:grantaccess'] = 'Accept, refuse or grant exceptional access';
$string['messageprovider:accessrequest'] = 'Exceptional access request from a student';
$string['messageprovider:accessdecision'] = 'Reply to an exceptional access request';

// Exceptional access: student page.
$string['request_heading'] = 'Request exceptional access';
$string['request_intro'] = 'The activity "{$a}" is closed outside class hours. You can ask your teacher to open it for a moment, for instance to submit your assignment.';
$string['request_reason'] = 'Message for your teacher (optional)';
$string['request_reason_help'] = 'Explain briefly why you need to access the activity now.';
$string['request_submit'] = 'Send the request';
$string['request_sent'] = 'Your request for "{$a}" was sent to your teacher.';
$string['request_already'] = 'A request for "{$a}" is already waiting for a reply.';
$string['request_notneeded'] = 'This activity is not closed to you right now.';

// Exceptional access: teacher page.
$string['requests_menu'] = 'Access requests';
$string['requests_heading'] = 'Exceptional access requests';
$string['requests_intro'] = 'Students can ask for an assignment or quiz that is closed outside class hours to be opened, for instance to submit an assignment. Granted access opens the activity to the student for the chosen duration, then it closes again by itself.';
$string['requests_pending'] = 'Pending requests';
$string['requests_nopending'] = 'No pending requests.';
$string['requests_active'] = 'Current access';
$string['requests_noactive'] = 'No current access.';
$string['requests_grant'] = 'Grant access without a request';
$string['requests_grant_submit'] = 'Grant';
$string['requests_grant_none'] = 'No activity is restricted to class hours, or no student is enrolled.';
$string['requests_history'] = 'History';
$string['requests_nohistory'] = 'No decisions yet.';
$string['requests_comment'] = 'Comment';
$string['requests_accepted'] = 'Access granted.';
$string['requests_refused'] = 'Request refused.';
$string['requests_revoked'] = 'Access withdrawn.';
$string['requests_granted'] = 'Access granted.';
$string['requests_alreadydecided'] = 'Another teacher has already replied to this request.';
$string['student'] = 'Student';
$string['activity'] = 'Activity';
$string['requestedat'] = 'Requested on';
$string['decision'] = 'Decision';
$string['until'] = 'Until';
$string['accept'] = 'Accept';
$string['refuse'] = 'Refuse';
$string['revoke'] = 'Withdraw access';
$string['decidedby'] = 'Decided by';
$string['decidedat'] = 'On';
$string['deletedactivity'] = 'deleted activity';
$string['duration_tonight'] = 'Until tonight';
$string['grantstatus_pending'] = 'Pending';
$string['grantstatus_accepted'] = 'Granted';
$string['grantstatus_refused'] = 'Refused';
$string['grantstatus_cancelled'] = 'Withdrawn';

// Exceptional access: messages.
$string['msg_request_subject'] = 'Access request: {$a->student} — {$a->activity}';
$string['msg_request_body'] = '{$a->student} requests exceptional access to "{$a->activity}" ({$a->course}).

Message: {$a->reason}';
$string['msg_noreason'] = '(no message)';
$string['msg_accepted_subject'] = 'Access granted: {$a->activity}';
$string['msg_accepted_body'] = 'Your teacher opened "{$a->activity}" for you until {$a->until}.';
$string['msg_refused_subject'] = 'Request refused: {$a->activity}';
$string['msg_refused_body'] = 'Your teacher did not accept your request to access "{$a->activity}".';
$string['msg_comment'] = 'Teacher\'s comment: {$a}';

// Privacy.
$string['privacy:path'] = 'Exceptional access';
$string['privacy:metadata:grant'] = 'Requests for exceptional access to a closed activity, and the teachers\' decisions.';
$string['privacy:metadata:grant:userid'] = 'The student requesting access.';
$string['privacy:metadata:grant:reason'] = 'The student\'s message.';
$string['privacy:metadata:grant:status'] = 'The request state (pending, granted, refused, withdrawn).';
$string['privacy:metadata:grant:requestedat'] = 'The request date.';
$string['privacy:metadata:grant:decidedby'] = 'The teacher who decided.';
$string['privacy:metadata:grant:decidedat'] = 'The decision date.';
$string['privacy:metadata:grant:decisioncomment'] = 'The teacher\'s comment.';
$string['privacy:metadata:grant:timestart'] = 'The start of the granted access.';
$string['privacy:metadata:grant:timeend'] = 'The end of the granted access.';
$string['privacy:metadata:messages'] = 'Requests and decisions are sent as Moodle notifications.';
