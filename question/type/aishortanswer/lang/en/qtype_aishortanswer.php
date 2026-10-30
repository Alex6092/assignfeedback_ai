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
$string['apiurl']              = 'External API URL';
$string['model']               = 'Model';
$string['apikey']              = 'External API key';
$string['apiurl_override']     = 'Grade with an external API (bypass the load balancer)';
$string['model_override']      = 'Force a model';
$string['apikey_override']     = 'Set the external API key';
$string['apiurl_help'] = 'By default, responses are graded through the school AI load balancer. If the box is ticked and the URL filled in, this question is graded directly by this OpenAI Chat Completions compatible API (e.g. https://api.openai.com/v1/chat/completions), bypassing the load balancer. Then also fill in the model and the key of this API.';
$string['model_help'] = 'Without an external API: identifier of another model of the load balancer catalogue (it must be declared there). With an external API: model of that API (e.g. gpt-4o-mini). Without override: the site AI grading model.';
$string['apikey_help'] = 'Secret key of the external API. Ignored without an external API: the load balancer uses the site key. The value is encrypted at rest.';

// Input
$string['pleaseenteranswer']   = 'Please enter an answer.';
