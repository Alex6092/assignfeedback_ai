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
$string['privacy:path_supervised'] = 'Supervised activities';
$string['privacy:metadata:gate'] = 'Openings of supervised activities decided by teachers.';
$string['privacy:metadata:gate:scopeid'] = 'The student concerned, for an individual opening.';
$string['privacy:metadata:gate:openedby'] = 'The teacher who opened the activity.';
$string['privacy:metadata:gate:timeopened'] = 'When it was opened.';
$string['privacy:metadata:gate:closeat'] = 'The scheduled automatic closing.';
$string['privacy:metadata:gate:closedby'] = 'The teacher who closed the activity.';
$string['privacy:metadata:gate:timeclosed'] = 'When it was closed.';

// Supervised activities (opened by the teacher, in class).
$string['classhours:supervise'] = 'Open and close supervised activities';
$string['task_collect_supervised'] = 'Supervised activities: collect work in progress on closing';
$string['task_close_supervised'] = 'Supervised activities: automatic closing at the scheduled time';
$string['submit_closed_supervised'] = 'This activity is done in class: you can only submit when the teacher opens it.';
$string['supervised_menu'] = 'Supervised activities';
$string['supervised_intro'] = 'A supervised activity stays closed to students until you open it. Open it in class for the whole course, a group or a student, with or without a duration; on closing, quiz attempts in progress are submitted and assignment drafts are handed in. Once closed, a student who has been graded can reopen the activity to read the grade and feedback, without being able to submit.';
$string['supervised_conditiondisabled'] = 'The "Supervised activity" access condition is not installed or not enabled: no activity can be supervised (Site administration → Plugins → Availability restrictions).';
$string['supervised_live'] = 'Live';
$string['supervised_none'] = 'No supervised activity in this course. Choose them below.';
$string['supervised_open'] = 'Open';
$string['supervised_closed'] = 'Closed';
$string['supervised_until'] = 'until {$a}';
$string['supervised_untilclosed'] = 'until closed';
$string['supervised_open_button'] = 'Open';
$string['supervised_close'] = 'Close the activity';
$string['supervised_close_one'] = 'Close';
$string['supervised_closeall'] = 'Close all';
$string['supervised_confirm_close'] = 'Close now? Quiz attempts in progress will be submitted and assignment drafts handed in.';
$string['supervised_confirm_closeall'] = 'Close all open activities of the course? Quiz attempts in progress will be submitted and assignment drafts handed in.';
$string['supervised_scope'] = 'For';
$string['supervised_scope_students'] = 'Students';
$string['supervised_duration'] = 'Duration';
$string['supervised_duration_manual'] = 'Until I close it';
$string['supervised_duration_slot'] = 'Until the end of the class slot';
$string['supervised_noslot'] = 'No class-hours slot is in progress for these students: choose another duration.';
$string['supervised_counters_quiz'] = '{$a->active} attempt(s) in progress, {$a->done} submitted since opening';
$string['supervised_counters_assign'] = '{$a->active} draft(s) in progress, {$a->done} submitted since opening';
$string['supervised_collect_help'] = 'Work is collected within a minute of closing; the activity itself is closed to students immediately. Students with extra time keep their longer time, unless you close for them too.';
$string['supervised_opened_done'] = '"{$a}" is open.';
$string['supervised_closed_done'] = '"{$a}" is closed: work in progress will be collected within a minute.';
$string['supervised_closedall_done'] = '{$a} activity(ies) closed: work in progress will be collected within a minute.';
$string['supervised_notsupervised'] = 'This activity is not supervised.';
$string['supervised_selection'] = 'Choose supervised activities';
$string['supervised_selection_help'] = 'A ticked activity gets the "Supervised activity" restriction: it stays closed until you open it. Unticking removes the restriction; the activity becomes free again.';
$string['supervised_supervise'] = 'Supervised';
$string['supervised_history'] = 'History';
$string['supervised_openedby'] = 'Opened by';
$string['block_none'] = 'No supervised activity is open right now.';
$string['block_opennow'] = 'Open now';
$string['block_start'] = 'Start';
$string['block_teacher_open'] = 'Your open activities';
$string['block_manage'] = 'Manage';
$string['block_moreoptions'] = 'More options (group, student, duration)…';
$string['block_nosupervised'] = 'No supervised activity in this course.';

// Supervised activities v2: timeline, catch-up, planned dates, linked activities, code, extra time, exam mode.
$string['messageprovider:catchuprequest'] = 'Catch-up requests for a supervised activity';
$string['msg_catchup_subject'] = '{$a->student} asks to catch up on "{$a->activity}"';
$string['msg_catchup_body'] = '{$a->student} ({$a->course}) asks to catch up on the supervised activity "{$a->activity}". Open it for them from the Supervised activities page ("To catch up", then "Open for them").';
$string['exam_redirect'] = 'Assessment in progress: the rest of the course is closed until the session ends.';
$string['exam_locked'] = 'Assessment in progress: the rest of the course is closed until the session ends.';
$string['code_heading'] = 'Session code';
$string['code_intro'] = 'Enter the code shown in class to start "{$a}".';
$string['code_label'] = 'Session code';
$string['code_submit'] = 'Start';
$string['code_ok'] = 'Code accepted: you can start.';
$string['code_wrong'] = 'This code does not match the current session.';
$string['code_wait'] = 'Too many attempts: try again in {$a} seconds.';
$string['catchup_notneeded'] = 'This activity does not need catching up.';
$string['catchup_sent'] = 'Catch-up request sent for "{$a}": your teacher will open it for you.';
$string['catchup_list'] = 'To catch up: {$a} student(s)';
$string['catchup_requests'] = '{$a} request(s)';
$string['catchup_requested'] = 'request';
$string['catchup_openfor'] = 'Open for them';
$string['supervised_extratime'] = 'Extra time running';
$string['supervised_extratime_until'] = 'closed, extra time until {$a}';
$string['supervised_code'] = 'Session code';
$string['supervised_close_force'] = 'Close for extra time too';
$string['supervised_confirm_force'] = 'Close now for everyone, extra time included? Their attempts in progress will be submitted and their drafts handed in.';
$string['supervised_present'] = '{$a} present (code entered)';
$string['frise_today'] = 'Today, {$a}';
$string['frise_nodate'] = 'Date to come';
$string['frise_entercode'] = 'Enter the code';
$string['frise_due'] = 'To catch up';
$string['frise_requested'] = 'Catch-up requested';
$string['frise_request'] = 'Request a catch-up';
$string['frise_done'] = 'Submitted';
$string['frise_past'] = 'Session over';
$string['frise_next'] = 'Next';
$string['frise_upcoming'] = 'Upcoming';
$string['frise_planned'] = 'Planned: {$a}';
$string['block_today'] = 'Today';
$string['block_requests'] = '{$a} catch-up request(s)';
$string['option_sessioncode'] = 'Session code';
$string['option_sessioncode_help'] = 'At each opening, a 4-character code is shown here (and full screen): students must enter it to start. An absent student cannot take it from home when you open for the whole course; the entered code counts as attendance.';
$string['option_exammode'] = 'Exam mode';
$string['option_exammode_help'] = 'During the session, the rest of the course is closed to students taking it (they are sent back to this activity), and the AI tutor is off in the whole course.';
$string['settings_heading'] = 'Settings: planned date, options, activities closed during the session';
$string['settings_planned'] = 'Planned date';
$string['settings_planned_group'] = 'Planned date for {$a}';
$string['settings_links'] = 'Activities closed during this activity';
$string['settings_links_help'] = 'Lessons, pages or other activities not to be consulted during the session: they are closed to students for whom this activity is open, and reopened on closing.';
$string['display_link'] = 'Full screen (projector)';
$string['display_left'] = 'Time left';
$string['extratime_heading'] = 'Extra time';
$string['extratime_help'] = 'For a student with extra time, a supervised activity lasts longer ({$a} % by default): opened for 30 min, it stays open 40 min for them; closed by hand, they keep the same share of the elapsed time.';
$string['extratime_count'] = '{$a} student(s) with extra time';
$string['extratime_on'] = 'Extra time';
$string['extratime_percent'] = 'Increase';
$string['setting_extratimedefault'] = 'Extra time: default increase (%)';
$string['setting_extratimedefault_desc'] = 'Increase offered for a student with extra time on the Supervised activities page (33 %: a third more time).';
$string['privacy:metadata:catchup'] = 'Catch-up requests for a supervised activity.';
$string['privacy:metadata:catchup:userid'] = 'The requesting student.';
$string['privacy:metadata:catchup:status'] = 'The request state (pending, done).';
$string['privacy:metadata:catchup:timecreated'] = 'When it was requested.';
$string['privacy:metadata:extratime'] = 'Students with extra time in a course.';
$string['privacy:metadata:extratime:userid'] = 'The student.';
$string['privacy:metadata:extratime:percent'] = 'The duration increase.';
$string['privacy:metadata:present'] = 'Session codes entered (attendance in class).';
$string['privacy:metadata:present:userid'] = 'The student.';
$string['privacy:metadata:present:timecreated'] = 'When the code was entered.';
