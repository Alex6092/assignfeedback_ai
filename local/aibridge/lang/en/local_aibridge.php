<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI bridge (Claude Code, Codex)';

// Capabilities.
$string['aibridge:read'] = 'Read a course through the AI bridge (settings, contents, answer keys)';
$string['aibridge:write'] = 'Create and edit courses and activities through the AI bridge (sandbox)';

// Settings.
$string['settings_intro'] = 'The bridge lets an AI agent (Claude Code, Codex), through the local MCP server <code>tools/moodle-mcp</code>, read a course in full detail, including plugin settings. It never returns personal student data or secrets. To use it: enable web services and the REST protocol, enable the "AI bridge (Claude Code, Codex)" service, authorise a service account on it and create a token for that account. Every call is recorded in the <a href="{$a}">bridge log</a>.';
$string['setting_enablewrites'] = 'Allow writing';
$string['setting_enablewrites_desc'] = 'Course and activity creation through the bridge, only in the sandbox categories below. Everything created stays hidden until a teacher publishes it.';
$string['setting_sandboxcategories'] = 'Sandbox categories';
$string['setting_sandboxcategories_desc'] = 'The only categories (and their subcategories) where the bridge may create or edit anything.';
$string['setting_logretentiondays'] = 'Log retention (days)';
$string['setting_logretentiondays_desc'] = 'Older calls are deleted every night. 0: keep forever.';

// Log.
$string['log_title'] = 'AI bridge log';
$string['log_time'] = 'Date';
$string['log_user'] = 'Account';
$string['log_function'] = 'Function';
$string['log_target'] = 'Target';
$string['log_status'] = 'Result';
$string['log_duration'] = 'Duration';
$string['log_params'] = 'Parameters';
$string['log_course'] = 'course {$a}';
$string['log_all'] = 'All courses';
$string['log_empty'] = 'No call recorded.';

// Tasks.
$string['task_purge_log'] = 'AI bridge log cleanup';

// Errors.
$string['error_json'] = 'Cannot encode the response as JSON: {$a}';
$string['error_export'] = 'Moodle XML export failed.';
$string['error_writesdisabled'] = 'Writing through the bridge is disabled (bridge setting "Allow writing").';
$string['error_notsandbox'] = 'Category {$a} is not a bridge sandbox: writing refused.';
$string['error_coursenames'] = 'The course full name and short name are required.';
$string['error_sectionnumber'] = 'Invalid section number: {$a}.';
$string['error_modname'] = 'Activity type not supported by the bridge: {$a}.';
$string['error_settings'] = 'Settings must be a JSON object (named fields of the activity form).';
$string['error_date'] = 'Invalid date (expected format YYYY-MM-DDTHH:MM): {$a}.';
$string['error_scale'] = 'Scale not found: {$a}.';
$string['error_efenocompetence'] = 'EFE reporting enabled without a competency: at least one code is required.';
$string['error_efecode'] = 'EFE code refused: {$a} (level N2 at least, for example C04.3 or C04.3.D4.T4).';
$string['error_drafttype'] = 'Unknown object type: {$a} (cm, section, course or questioncategory).';
$string['error_categoryforeign'] = 'Category "{$a}" contains a question created or edited by an account other than the bridge: deletion refused.';
$string['error_categoryinuse'] = 'Questions of category "{$a}" are still in use (quiz, attempts): delete the quiz that uses them first.';
$string['error_notcreated'] = 'This object was not created by the bridge: deletion refused ({$a}).';
$string['error_draftgone'] = 'Object not found, removed from the bridge registry ({$a}).';
$string['error_draftvisible'] = 'Object is visible: a teacher published it, the bridge will not delete it ({$a}).';
$string['error_sectionzero'] = 'Section 0 of a course cannot be deleted.';
$string['error_sectionnotempty'] = 'Section {$a} still contains activities: delete them first.';
$string['error_beforecmid'] = 'Position not possible: activity {$a} is not in this section.';
$string['error_lessonpages'] = 'Lesson pages expected: a non-empty JSON list of {title, contents}.';
$string['error_lessonpage'] = 'Lesson page {$a} has no title or no contents.';
$string['error_lessonimages'] = 'Lesson images expected: a JSON list of {filename, content}, content in base64.';
$string['error_lessonimagecount'] = 'Too many images for one lesson: at most {$a} per call.';
$string['error_lessonimagename'] = 'Image name refused: "{$a}" (letters, digits, dot, hyphen and underscore; extension png, jpg, jpeg, gif or svg).';
$string['error_lessonimagetwice'] = 'Image "{$a}" sent twice: within a lesson, each image has a unique name.';
$string['error_lessonimagecontent'] = 'Image "{$a}" unreadable: invalid base64, or contents not matching its extension.';
$string['error_lessonimagesvg'] = 'SVG image "{$a}" refused: it contains script, an event handler, an embedded object or an XML entity.';
$string['error_lessonimagesize'] = 'Image "{$a->name}" too large: {$a->size}, at most {$a->max}.';
$string['error_lessonimagestotal'] = 'Lesson images too large: {$a->size} in total, at most {$a->max} per call.';
$string['error_categoryname'] = 'A question category name is required.';
$string['error_noquestions'] = 'No question in the Moodle XML file.';
$string['error_categorynotempty'] = 'Category "{$a}" already contains questions: choose another category, or empty this one in Moodle.';
$string['error_import'] = 'Moodle XML import refused: {$a}';
$string['error_quiznotempty'] = 'This quiz already contains questions: the bridge only fills an empty quiz.';
$string['error_quizattempts'] = 'This quiz already has attempts: its structure can no longer be changed.';
$string['error_randomcategory'] = 'Random question category not found in the course banks: {$a}.';
$string['error_randomcount'] = 'Not enough questions for the random draw ({$a}).';
$string['error_questionidnumber'] = 'Question not found in the course banks: idnumber "{$a}".';
$string['error_quizlayout'] = 'Quiz structure expected: {"slots": [...]} with at least one slot.';
$string['error_extras'] = 'Settings expected: a JSON object (supervised, requires, minduration, avancement).';
$string['error_requires'] = 'Activity to complete first not found in this course (or the activity itself): {$a}.';
$string['error_coursenotdraft'] = 'The course contains an activity that is published or not created by the bridge (activity {$a}): deletion refused.';

// Privacy.
$string['privacy:metadata:log'] = 'Log of calls to the AI bridge.';
$string['privacy:metadata:log:userid'] = 'Account that called the bridge.';
$string['privacy:metadata:log:function'] = 'Function called.';
$string['privacy:metadata:log:courseid'] = 'Target course.';
$string['privacy:metadata:log:params'] = 'Call parameters.';
$string['privacy:metadata:log:timecreated'] = 'Time of the call.';
$string['privacy:metadata:created'] = 'Objects created by the AI bridge.';
$string['privacy:metadata:created:userid'] = 'Account that created the object.';
$string['privacy:metadata:created:objecttype'] = 'Type of object created.';
$string['privacy:metadata:created:timecreated'] = 'Creation time.';
