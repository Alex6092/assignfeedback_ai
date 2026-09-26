<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Attempt check';
$string['menu'] = 'Attempt check';
$string['attemptcheck:view'] = 'View the course attempt check';
$string['attemptcheck:delete'] = 'Delete an attempt or erase a submission from the attempt check';
$string['attemptcheck:notify'] = 'Receive notifications of attempts to review';
$string['messageprovider:suspicious'] = 'Attempt to review (attempt check)';
$string['task_analyse'] = 'Attempt check: analyse a submitted attempt';

// Privacy.
$string['privacy:metadata:review'] = 'Attempts and submissions judged legitimate by a teacher.';
$string['privacy:metadata:review:userid'] = 'Student who made the attempt.';
$string['privacy:metadata:review:itemtype'] = 'Activity type (quiz or assignment).';
$string['privacy:metadata:review:itemid'] = 'Attempt or submission concerned.';
$string['privacy:metadata:review:status'] = 'Teacher decision.';
$string['privacy:metadata:review:reviewerid'] = 'Teacher who made the decision.';
$string['privacy:metadata:review:timemodified'] = 'Date of the decision.';
$string['privacy:metadata:message'] = 'Teachers receive a notification when an attempt triggers an indicator.';

// Settings.
$string['settings_intro'] = 'Indicators point out attempts to review; they do not prove cheating. The report is available in each course ("Attempt check").';
$string['setting_notify'] = 'Notify teachers';
$string['setting_notify_desc'] = 'Each time a submission triggers an indicator, the course teachers receive a notification with a link to the report.';
$string['setting_fastratio'] = 'Speed: threshold (% of the median)';
$string['setting_fastratio_desc'] = 'An attempt (or an assignment, from first access to submission) shorter than this percentage of the other students\' median duration is flagged.';
$string['setting_questionratio'] = 'Per question: threshold (% of the median)';
$string['setting_questionratio_desc'] = 'A written question answered in less than this percentage of the other students\' median time is flagged.';
$string['setting_minrefs'] = 'Reference students required';
$string['setting_minrefs_desc'] = 'Minimum number of other students who finished, to compare with the class.';
$string['setting_wpm'] = 'Maximum writing speed (words/min)';
$string['setting_wpm_desc'] = 'Text that appeared faster than this speed was most likely not typed (pasted). For reference, a student types 20 to 40 words per minute.';
$string['setting_minwords'] = 'Minimum size of a text addition (words)';
$string['setting_minwords_desc'] = 'Shorter text additions are not assessed (too few words to measure a speed).';
$string['setting_textqtypes'] = 'Written question types';
$string['setting_textqtypes_desc'] = 'Question types whose writing is analysed (technical names separated by commas).';

// Indicators.
$string['duration_s'] = '{$a} s';
$string['duration_min'] = '{$a->m} min {$a->s} s';
$string['duration_h'] = '{$a->h} h {$a->m} min';
$string['signal_offslot'] = 'Outside class hours';
$string['signal_fast'] = 'Fast';
$string['signal_question'] = 'Fast question';
$string['signal_typing'] = 'Writing';
$string['signal_minduration'] = 'Minimum duration';
$string['text_offslot_start'] = 'started outside class hours ({$a})';
$string['text_offslot_end'] = 'finished outside class hours ({$a})';
$string['text_offslot_submit'] = 'submitted outside class hours ({$a})';
$string['text_fast'] = '{$a->duration} against a median of {$a->median} ({$a->refs} students)';
$string['text_question'] = 'question {$a->number}: {$a->time} against a median of {$a->median}';
$string['text_typing'] = '{$a->words} words appeared in {$a->time} ({$a->wpm} words/min)';
$string['text_typing_question'] = 'question {$a->number}: {$a->words} words appeared in {$a->time} ({$a->wpm} words/min)';
$string['text_minduration'] = '{$a->duration}, expected minimum {$a->minimum}';

// Notification.
$string['notify_subject'] = 'Attempt to review: {$a->student} — {$a->activity}';
$string['notify_body'] = 'The attempt by {$a->student} on "{$a->activity}" ({$a->course}) triggers indicators:
{$a->signals}

This is not proof: review it in the attempt check.
{$a->url}';
$string['notify_small'] = 'Attempt to review: {$a->student} — {$a->activity}';
$string['notify_linkname'] = 'Attempt check';

// Report.
$string['intro'] = 'Quiz attempts and assignment submissions to review. An indicator is not proof: a student may be fast, may have prepared the text or be dictating it. Look at the attempt before deciding.';
$string['info_noschedule'] = '"Outside class hours" indicator inactive: no slot is configured in this course\'s class hours.';
$string['info_nologs'] = 'The standard log is not enabled: first access to assignments and writing speed are estimated more roughly.';
$string['allactivities'] = 'All quizzes and assignments';
$string['filter_flagged'] = 'Flagged attempts';
$string['filter_all'] = 'All attempts';
$string['scope_restricted'] = 'Outside class hours: restricted activities';
$string['scope_all'] = 'Outside class hours: all activities';
$string['showlegit'] = 'Show attempts judged legitimate';
$string['filter'] = 'Filter';
$string['minduration_label'] = 'Expected minimum duration:';
$string['minduration_help'] = 'A shorter attempt is flagged, even without other reference students. 0: no minimum.';
$string['minduration_saved'] = 'Minimum duration saved.';
$string['nothing_found'] = 'No attempt to show.';
$string['count_shown'] = '{$a} attempt(s) shown.';
$string['student'] = 'Student';
$string['attempt'] = 'Attempt';
$string['attempt_number'] = 'no. {$a}';
$string['timestart'] = 'Start';
$string['timeend'] = 'End';
$string['duration'] = 'Duration';
$string['signals'] = 'Indicators';
$string['legit'] = 'Legitimate';
$string['action_legit'] = 'Legitimate';
$string['action_unlegit'] = 'To review';
$string['bulk_legit'] = 'Mark selection legitimate';
$string['bulk_delete'] = 'Delete selection…';
$string['legit_done'] = '{$a} attempt(s) marked legitimate.';
$string['unlegit_done'] = '{$a} attempt(s) back to review.';
$string['type_quiz'] = 'quiz';
$string['type_assign'] = 'assignment';

// Deletion.
$string['nothing_selected'] = 'No attempt selected.';
$string['confirm_heading'] = 'Delete these attempts?';
$string['confirm_warning'] = 'Quiz: the attempt is deleted and the grade recalculated. Assignment: the submission content, the grade and the AI feedback are erased; the student can submit again. The erased grade is pushed to the gradebook (and to EFE, which receives an empty grade). This cannot be undone.';
$string['confirm_delete'] = 'Delete ({$a})';
$string['cannotdelete'] = 'You are not allowed to delete it';
$string['delete_done'] = '{$a} attempt(s) deleted.';
$string['delete_failed'] = '{$a} could not be deleted.';
$string['error_notfound'] = 'Attempt not found in this course.';
$string['error_teamsubmission'] = 'Group submissions are not supported.';
