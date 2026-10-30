<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI feedback (shared library)';
// Privacy
$string['privacy:metadata:qgrading']             = 'AI gradings of quiz questions.';
$string['privacy:metadata:qgrading:userid']      = 'The user whose response is graded.';
$string['privacy:metadata:qgrading:status']      = 'Grading status (pending, generated, failed).';
$string['privacy:metadata:qgrading:aifeedback']  = 'The feedback produced by the AI for this response.';
$string['privacy:metadata:qgrading:mark']        = 'The mark awarded by the AI.';
$string['privacy:metadata:qgrading:timecreated'] = 'When the grading was created.';
$string['privacy:metadata:job']                  = 'Tracking of the generations handed over to the inference load balancer.';
$string['privacy:metadata:job:userid']           = 'The user the generation is made for.';
$string['privacy:metadata:job:component']        = 'The plugin that made the request.';
$string['privacy:metadata:job:model']            = 'The model used.';
$string['privacy:metadata:job:status']           = 'Job status (sent, received, done, failed).';
$string['privacy:metadata:job:result']           = 'The model response, kept only until it is applied.';
$string['privacy:metadata:job:timecreated']      = 'When the request was made.';
$string['privacy:metadata:llm']                  = 'Content sent for analysis to the inference load balancer of the school (or to an external API imposed by an activity).';
$string['privacy:metadata:llm:content']          = 'The content sent to the model (student response, question, conversation message).';
$string['privacy:path:qgrading']                 = 'AI gradings';
$string['privacy:path:jobs']                     = 'AI generations';

$string['taskname'] = 'AI generation (shared queue)';
// AI server (inference load balancer)
$string['lb_heading']               = 'AI server (load balancer)';
$string['lb_heading_desc']          = 'All AI generations (grading, tutor, quiz generation, missions...) are handed over to the inference load balancer, which queues them and spreads them across the AI servers. Only activities configured with their own URL (external API) call it directly. In the load balancer administration, create an application for this Moodle with the callback base URL <code>{$a->base}</code>, then an API key allowed in async, stream and sync modes. Results arrive at <code>{$a->callback}</code>: the load balancer must be able to reach this address.';
$string['lb_url']                   = 'Load balancer URL';
$string['lb_url_help']              = 'Base address of the service, without "/v1", e.g. https://ai.my-school.org.';
$string['lb_apikey']                = 'Application API key';
$string['lb_apikey_help']           = 'Key created in the load balancer administration for the Moodle application (shown only once). Sent as "Authorization: Bearer ...". The value is encrypted at rest.';
$string['lb_callbacksecret']        = 'Callback signing secret';
$string['lb_callbacksecret_help']   = 'Callback secret of the application, given by the load balancer when the application is created (or rotated). It is used to check that received results really come from the load balancer. The value is encrypted at rest.';
$string['lb_callbackurl']           = 'Callback URL (optional)';
$string['lb_callbackurl_help']      = 'Address the load balancer sends results to. Leave empty to use {$a}. Only set it if the load balancer reaches Moodle through another address (internal network, for example).';
$string['model_feedback']           = 'AI grading model';
$string['model_feedback_help']      = 'Model of the load balancer catalogue used to grade assignments and AI quiz questions. It is also the model used by the other AI plugins (quiz generation, missions, tutor moderation...) that have no model of their own.';
$string['defaultsystemprompt']      = 'Default system prompt';
$string['defaultsystemprompt_help'] = 'Used when no prompt is defined at assignment or question level.';
$string['lb_notconfigured']         = 'The AI load balancer is not configured (URL and API key, in the local_aifeedback settings).';
$string['lb_nomodel']               = 'No model is configured for this generation ("AI grading model" setting of local_aifeedback).';
$string['lb_error']                 = 'AI server: {$a}';
$string['lb_jobfailed']             = 'Generation {$a->status} on the load balancer: {$a->message}';
$string['lbmodel_choose']           = 'Choose a model...';
$string['lbmodel_missing']          = '{$a} (not in the load balancer catalogue)';
$string['lbmodel_unavailable']      = 'Model catalogue unavailable ({$a}): type the exact model identifier.';
$string['lbmodel_label']            = '{$a->id}{$a->vision} · context {$a->context} · max output {$a->output}';
$string['lbmodel_vision']           = 'vision';
$string['lbmodel_default']          = 'AI grading model (default)';
$string['task_process']             = 'Apply a received AI result';
$string['task_reconcile']           = 'Reconcile AI jobs with the load balancer';

// Model usages (status page)
$string['usage_feedback']           = 'grading';
$string['usage_tutor']              = 'tutor';
$string['usage_moderation']         = 'moderation / brief';
$string['usage_quizgen']            = 'quiz generation';
$string['usage_missions']           = 'missions';
$string['usage_assignoverride']     = '{$a} assignment(s)';
$string['usage_questionoverride']   = '{$a} question(s)';

// Job statuses
$string['jobstatus_submitting']     = 'Sending';
$string['jobstatus_submitted']      = 'At the load balancer';
$string['jobstatus_received']       = 'Received';
$string['jobstatus_processing']     = 'Applying';
$string['jobstatus_done']           = 'Done';
$string['jobstatus_failed']         = 'Failed';
$string['jobstatus_cancelled']      = 'Cancelled';

// AI server status page
$string['servers_link']             = 'View the AI server status.';
$string['servers_page']             = 'AI server status';
$string['servers_intro']            = 'What the inference load balancer reports through its API, and the Moodle jobs in progress. The page refreshes every 15 seconds.';
$string['servers_notconfigured']    = 'The load balancer is not configured. Enter its URL, the API key and the callback secret in the <a href="{$a}">settings</a>.';
$string['servers_health']           = 'Connection';
$string['servers_reachable']        = 'Service reachable';
$string['servers_unreachable']      = 'Service unreachable';
$string['servers_release']          = 'version {$a}';
$string['servers_authok']           = 'API key accepted';
$string['servers_autherror']        = 'API key rejected';
$string['servers_auth401']          = 'key missing, unknown or disabled.';
$string['servers_auth403']          = 'insufficient rights for this key.';
$string['servers_auth403ip']        = 'the IP address of this Moodle server is not allowed for this key.';
$string['servers_lbadmin']          = 'Open the load balancer administration (per AI server details, jobs, per-student quotas)';
$string['servers_capacity']         = 'Capacity and load per model';
$string['servers_capacity_note']    = 'Figures aggregated per model over the active AI servers. An occupied slot is a running generation, or an interrupted generation whose stop is not confirmed yet.';
$string['servers_noworker']         = 'no server available';
$string['servers_workers_value']    = '{$a->available} / {$a->total} available';
$string['servers_busy_value']       = '{$a->occupied} / {$a->capacity}';
$string['servers_catalog']          = 'Model catalogue';
$string['servers_modelmissing']     = 'Model "{$a->model}" is configured in Moodle ({$a->usages}) but missing from the load balancer catalogue: the matching generations will be rejected.';
$string['servers_usage']            = 'Application usage';
$string['servers_nousage']          = 'No usage over the last 7 days.';
$string['servers_tokens_value']     = '{$a->total} ({$a->prompt} input, {$a->completion} output)';
$string['servers_quotas']           = 'Remaining budgets:';
$string['servers_noquota']          = 'No active token quota for the application or the key.';
$string['servers_quota_credit']     = '{$a->scope}: {$a->available} / {$a->limit} credit tokens';
$string['servers_quota_window']     = '{$a->scope}: {$a->available} / {$a->limit} tokens over {$a->hours} h';
$string['servers_scope_application'] = 'application';
$string['servers_scope_key']        = 'key';
$string['servers_moodle']           = 'Moodle jobs (last 24 hours)';
$string['servers_nojobs']           = 'No job submitted to the load balancer in the last 24 hours.';
$string['servers_moodletokens']     = 'Tokens used by these jobs: {$a->prompt} input, {$a->completion} output.';
$string['servers_active']           = 'Jobs in progress (oldest first)';
$string['servers_errors']           = 'Latest errors';
$string['servers_attempt']          = 'submission #{$a}';
$string['servers_reconcile']        = 'Run reconciliation now';
$string['servers_reconcile_help']   = 'Asks the load balancer about jobs without news for more than 10 minutes, applies pending results and re-queues orphan work. The scheduled task does it automatically every 5 minutes.';
$string['servers_reconciled']       = 'Reconciliation done.';
$string['servers_col_model']        = 'Model';
$string['servers_col_usages']       = 'Used in Moodle for';
$string['servers_col_workers']      = 'AI servers';
$string['servers_col_busy']         = 'Occupied slots';
$string['servers_col_vision']       = 'Vision';
$string['servers_col_context']      = 'Context (tokens)';
$string['servers_col_output']       = 'Max output (tokens)';
$string['servers_col_tokens24h']    = 'Tokens 24 h';
$string['servers_col_tokens7d']     = 'Tokens 7 days';
$string['servers_col_attempts7d']   = 'Generations 7 days';
$string['servers_col_component']    = 'Plugin';
$string['servers_col_state']        = 'State';
$string['servers_col_lbjob']        = 'Load balancer job';
$string['servers_col_since']        = 'Since';
$string['servers_col_when']         = 'Date';
$string['servers_col_error']        = 'Error';

$string['reasoning_budget']          = 'Reasoning budget (tokens)';
$string['reasoning_budget_help']     = 'For reasoning models (Qwen3, gpt-oss, DeepSeek-R1, Magistral…). Their reasoning counts against the same maximum number of tokens as the answer, which is sized for the answer alone (700 for the tutor, 2048 for grading): the reasoning uses it up and the answer comes back empty. This number is added to the maximum of every call (grading, tutor, question generation, Missions…); when above 0, the timeout of non-streamed calls also goes from 3 to 10 minutes. 0 = no extra (non-reasoning models). Recommended: 4096 to 8192. The reasoning is never shown or stored. With vLLM, the model context must fit the prompt plus this maximum.';

// Accessibility
$string['accessibility_heading']    = 'Accessibility';
$string['spelling_tolerance']       = 'Spelling tolerance (SpLD students)';
$string['spelling_tolerance_help']  = 'If enabled, the AI is instructed never to assess spelling, grammar or syntax, and to grade only the substance (concepts, reasoning, knowledge). Recommended so as not to penalise students with specific learning difficulties (dyslexia, dysorthographia). Applies to all AI grading (assignments and quiz questions). Enabled by default.';

// Vision
$string['vision_heading']               = 'Vision (image reading)';
$string['vision_enabled']               = 'Send images to the model';
$string['vision_enabled_help']          = 'If enabled, images found in submissions and responses (PDFs with illustrations, embedded images, PNG/JPEG attachments, etc.) are sent to the LLM together with the text. Requires a multimodal model.';
$string['maximagespersubmission']       = 'Maximum images per submission/response';
$string['maximagespersubmission_help']  = 'Global cap on images sent to the LLM. Controls context size and generation time. Default: 5.';
$string['imagemindimension']            = 'Minimum image dimension (pixels)';
$string['imagemindimension_help']       = 'Images extracted from a PDF whose width OR height is below this value are skipped (filters out decorative thumbnails). Default: 200.';
$string['imagemaxdimension']            = 'Maximum image size sent (pixels)';
$string['imagemaxdimension_help']       = 'Images sent to the LLM (attachments, editor images, images inside a ZIP, rasterised PDF pages, lesson images) whose longest side exceeds this value are downscaled and re-encoded as JPEG before sending. Cuts token usage and prevents crashes on some backends (Gemma under llama.cpp requires the whole image to fit in one evaluation batch). 0 = send images at their original size. Default: 1024.';

// Binaries
$string['binaries_heading']    = 'External binaries (poppler-utils)';
$string['pdftotextpath']       = 'Path to pdftotext binary';
$string['pdftotextpath_help']  = 'Absolute path to the pdftotext executable (poppler-utils). Leave empty for auto-detection.';
$string['pdftoppmpath']        = 'Path to pdftoppm binary';
$string['pdftoppmpath_help']   = 'Absolute path to the pdftoppm executable (poppler-utils), used to rasterise PDF pages to images. Leave empty for auto-detection.';
$string['pdfimagespath']       = 'Path to pdfimages binary';
$string['pdfimagespath_help']  = 'Absolute path to the pdfimages executable (poppler-utils), used to detect pages containing images. Leave empty for auto-detection.';
$string['pdftotextmissing']    = 'pdftotext binary not found on the server. Install poppler-utils or set the path in the plugin settings.';
$string['pdftotexterror']      = 'PDF text extraction error.';

// Quiz / questions
$string['quiz_heading']                  = 'AI questions (quiz)';
$string['max_attempts_to_grade']         = 'Max attempts graded by AI';
$string['max_attempts_to_grade_help']    = 'Maximum number of AI gradings per student per question, within a single quiz. Beyond this, the question stays awaiting manual grading. <strong>0 = unlimited (recommended)</strong>: in that case, prefer the quiz\'s native "Enforced delay between attempts" setting to throttle the LLM. Default: 0.';

// Retry a grading
$string['retry_button']         = 'Re-run AI grading';
$string['retry_queued']         = 'AI grading re-queued — it will be processed shortly.';
$string['retry_notfound']       = 'Grading row not found.';
$string['retry_pagetitle']      = 'Re-run an AI grading';
$string['retry_confirm_body']   = 'Confirm re-running the AI grading for this attempt. It will be re-queued on the AI server and processed shortly.';
$string['retry_confirm_button'] = 'Confirm re-run';
$string['retry_lasterror']      = 'Last error:';

// Question grading (shared quiz_grader / feedback_card base)
$string['emptyresponse']          = '(No response provided)';
$string['questionattemptmissing'] = 'The question attempt linked to this AI job cannot be found.';

// Feedback card
$string['feedback_pending']  = 'AI feedback is being generated. Come back in a few moments to see it.';
$string['feedback_failed']   = 'AI feedback generation failed. A teacher needs to grade this response manually.';
$string['feedback_error']    = 'Error reading AI feedback.';
$string['strengths']         = 'Strengths';
$string['improvements']      = 'Areas for improvement';
$string['detailedfeedback']  = 'Detailed feedback';
$string['competency_scores'] = 'Competency scores';
$string['competency']        = 'Competency';
$string['mastery_level']     = 'Mastery level';
$string['commentary']        = 'Commentary';

// Errors
$string['apicallfailed'] = 'The AI API call failed.';
