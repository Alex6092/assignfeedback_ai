<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']        = 'Short answer (AI graded)';
$string['pluginname_help']   = 'The student types a brief answer (a word, a definition, a sentence). The mark and a short feedback are produced automatically by a local LLM via the shared AI feedback library.';
$string['pluginnameadding']  = 'Adding an AI Short answer question';
$string['pluginnameediting'] = 'Editing an AI Short answer question';
$string['pluginnamesummary'] = 'Short-answer question, automatically graded by an LLM with brief feedback.';
$string['privacy:metadata']  = 'AI Short answer questions forward the student response text to an external LLM via the local_aifeedback plugin, and store the returned feedback in the database.';

// Form
$string['responseoptions']      = 'Response options';
$string['responsefieldlines']   = 'Input box size';
$string['responsefieldlines_help'] = 'Number of lines of the answer box. Choosing 1 shows a single-line text field (ideal for a word or a formula); a higher value shows a multi-line text area.';

$string['aiconfig_heading']    = 'AI grading configuration';
$string['aioverrides_heading'] = 'Site config overrides';
$string['systemprompt']        = 'System prompt';
$string['systemprompt_help']   = 'Instructions sent to the LLM as the system role. Leave empty to use the default prompt (which already enforces short feedback).';
$string['expectedanswer']      = 'Expected answer';
$string['expectedanswer_help'] = 'Reference answer the AI will compare the student response against.';
$string['apiurl']              = 'API URL';
$string['model']               = 'Model name';
$string['apikey']              = 'API key';
$string['apiurl_override']     = 'Override the site API URL';
$string['model_override']      = 'Override the site model name';
$string['apikey_override']     = 'Override the site API key';

// Input
$string['pleaseenteranswer']   = 'Please enter an answer.';
