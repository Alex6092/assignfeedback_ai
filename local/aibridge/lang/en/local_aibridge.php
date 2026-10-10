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
