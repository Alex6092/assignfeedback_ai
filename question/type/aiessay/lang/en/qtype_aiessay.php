<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']          = 'Essay (AI graded)';
$string['pluginname_help']     = 'In response to a question (which may include an image or a file), the student writes a long-form answer. The mark and feedback are produced automatically by a local LLM via the shared AI feedback library.';
$string['pluginnameadding']    = 'Adding an AI Essay question';
$string['pluginnameediting']   = 'Editing an AI Essay question';
$string['pluginnamesummary']   = 'Essay-style question, automatically graded by an LLM (text only for now, optional vision).';
$string['privacy:metadata']    = 'AI Essay questions forward the student response text to an external LLM via the local_aifeedback plugin, and store the returned feedback in the database.';

// Form
$string['responseoptions']         = 'Response options';
$string['responseformat']          = 'Response format';
$string['responserequired']        = 'Require text response';
$string['responseisrequired']      = 'Text response is required';
$string['responsenotrequired']     = 'Text response is optional';
$string['responsefieldlines']      = 'Input box size';
$string['minwordlimit']            = 'Minimum word count';
$string['maxwordlimit']            = 'Maximum word count';
$string['maxlessthanmin']          = 'Maximum must be greater than or equal to minimum.';
$string['allowattachments']        = 'Allow attachments';
$string['attachmentsrequired']     = 'Required attachments';
$string['attachmentsoptional']     = 'None (optional)';
$string['unlimited']               = 'Unlimited';
$string['formateditor']            = 'HTML editor';
$string['formateditorfilepicker']  = 'HTML editor with file picker';
$string['formatplain']             = 'Plain text';
$string['formatmonospaced']        = 'Plain text, monospaced font';
$string['formatnoinline']          = 'No inline input (attachments only)';

$string['aiconfig_heading']        = 'AI grading configuration';
$string['aioverrides_heading']     = 'Site config overrides';
$string['systemprompt']            = 'System prompt';
$string['systemprompt_help']       = 'Instructions sent to the LLM as the system role. Leave empty to use the site default.';
$string['expectedanswer']          = 'Expected answer / marking scheme';
$string['expectedanswer_help']     = 'Reference answer or key points the AI will use to evaluate the response.';
$string['competencies']            = 'Evaluated competencies';
$string['competencies_help']       = 'Skills or criteria the AI must evaluate, one per line.';
$string['apiurl']                  = 'External API URL';
$string['model']                   = 'Model';
$string['apikey']                  = 'External API key';
$string['vision_enabled']          = 'Send images to the model';
$string['apiurl_override']         = 'Grade with an external API (bypass the load balancer)';
$string['model_override']          = 'Force a model';
$string['apikey_override']         = 'Set the external API key';
$string['apiurl_help'] = 'By default, responses are graded through the school AI load balancer. If the box is ticked and the URL filled in, this question is graded directly by this OpenAI Chat Completions compatible API (e.g. https://api.openai.com/v1/chat/completions), bypassing the load balancer. Then also fill in the model and the key of this API.';
$string['model_help'] = 'Without an external API: identifier of another model of the load balancer catalogue (it must be declared there). With an external API: model of that API (e.g. gpt-4o-mini). Without override: the site AI grading model.';
$string['apikey_help'] = 'Secret key of the external API. Ignored without an external API: the load balancer uses the site key. The value is encrypted at rest.';
$string['vision_enabled_override'] = 'Override the site vision setting';

// Renderer
$string['attachments']             = 'Attachments';
$string['pleaseinputatleastsomething'] = 'Please enter some text or add at least one attachment.';
$string['emptyresponse']           = '(No response provided)';
$string['feedback_pending']        = 'AI feedback is being generated. Come back in a few moments to see it.';
$string['feedback_failed']         = 'AI feedback generation failed. A teacher needs to grade this response manually.';
$string['feedback_error']          = 'Error reading AI feedback.';
$string['strengths']               = 'Strengths';
$string['improvements']            = 'Areas for improvement';
$string['detailedfeedback']        = 'Detailed feedback';
$string['competency_scores']       = 'Competency scores';
$string['competency']              = 'Competency';
$string['mastery_level']           = 'Mastery level';
$string['commentary']              = 'Commentary';

// Internal errors
$string['questionattemptmissing']  = 'The question attempt linked to this AI job cannot be found.';
