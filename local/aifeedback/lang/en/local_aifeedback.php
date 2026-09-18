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
$string['privacy:metadata:slot']                 = 'Queue tickets of the LLM server pool (no content, only technical scheduling information).';
$string['privacy:metadata:slot:userid']          = 'The user who made the request.';
$string['privacy:metadata:slot:purpose']         = 'The requested purpose (tutor or grading).';
$string['privacy:metadata:slot:component']       = 'The plugin that made the request.';
$string['privacy:metadata:slot:status']          = 'Ticket status (queued, reserved, running, finished).';
$string['privacy:metadata:slot:timecreated']     = 'When the request was made.';
$string['privacy:metadata:llm']                  = 'Content sent to an external LLM service for analysis.';
$string['privacy:metadata:llm:content']          = 'The content sent to the model (student response, question, conversation message).';
$string['privacy:path:qgrading']                 = 'AI gradings';
$string['privacy:path:pool']                     = 'AI queue';

$string['taskname'] = 'AI generation (shared queue)';

// API
$string['api_heading']              = 'LLM API';
$string['apiurl']                   = 'API URL';
$string['apiurl_help']              = 'OpenAI-compatible Chat Completions endpoint. Default: http://localhost:1234/v1/chat/completions';
$string['model']                    = 'Model name';
$string['model_help']               = 'Model identifier used in API calls, e.g. qwen3.5-9b-instruct.';
$string['apikey']                   = 'API key';
$string['apikey_help']              = 'Secret key sent as the HTTP header "Authorization: Bearer ...". Leave empty if the server does not require one (LM Studio default). The value is encrypted at rest.';
$string['defaultsystemprompt']      = 'Default system prompt';
$string['defaultsystemprompt_help'] = 'Used when no assignment-level or question-level prompt is defined.';

// LLM server pool
$string['pool_heading']              = 'LLM servers (load balancing)';
$string['pool_heading_desc']         = 'You can declare up to 3 LLM servers. Regulated calls (the student AI tutor, and eventually the grading queue) are spread across the servers that accept the requested purpose and have a free slot. Tutor requests are admitted FIRST: a student is waiting in front of their screen, a deferred grading is not. Leaving a slot URL empty disables it.';
$string['server_heading']            = 'Server {$a}';
$string['server1_desc']              = 'This slot uses the API URL, model and key entered above. It is always enabled.';
$string['server_apiurl']             = 'API URL';
$string['server_apiurl_help']        = 'OpenAI-compatible Chat Completions endpoint of this server, e.g. http://192.168.1.42:1234/v1/chat/completions. Leave empty to disable this slot.';
$string['server_model']              = 'Model name';
$string['server_model_help']         = 'Model to use on this server. Leave empty to fall back to the global model.';
$string['server_apikey']             = 'API key';
$string['server_apikey_help']        = 'Secret key for this server (sent as "Authorization: Bearer ..."). Leave empty if the server does not require one. The value is encrypted at rest.';
$string['server_maxconcurrency']     = 'Maximum concurrent requests';
$string['server_maxconcurrency_help'] = 'How many generations this server can handle AT THE SAME TIME. LM Studio usually serves one request at a time: leave 1 unless you enabled parallel processing. Beyond that, requests wait in the queue. Default: 1.';
$string['server_use_feedback']       = 'Allow assignment and question grading';
$string['server_use_feedback_help']  = 'If enabled, this server can process deferred gradings (job queue).';
$string['server_use_tutor']          = 'Allow the AI tutor (student chat)';
$string['server_use_tutor_help']     = 'If enabled, this server can process AI tutor conversations. Dedicating a server to the tutor prevents gradings from making students wait.';
$string['stream_usage']              = 'Request token counts when streaming';
$string['stream_usage_help']         = 'If enabled, streaming calls ask the server for the exact number of tokens used ("stream_options") so quotas are accurate. Some older servers reject this option with an HTTP 400 error: leave it disabled in that case (tokens are then estimated). No effect on OpenAI, where the option is always sent.';

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
$string['retry_queued']         = 'AI grading re-queued — it will be processed shortly (next cron run).';
$string['retry_notfound']       = 'Grading row not found.';
$string['retry_pagetitle']      = 'Re-run an AI grading';
$string['retry_confirm_body']   = 'Confirm re-running the AI grading for this attempt. It will be re-queued and processed at the next cron run.';
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
