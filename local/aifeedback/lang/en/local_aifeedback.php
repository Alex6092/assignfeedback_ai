<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI feedback (shared library)';
$string['privacy:metadata'] = 'The local_aifeedback plugin does not store personal data directly. It forwards to an external LLM the content provided by consumer plugins (assignfeedback_ai, qtype_aiessay, ...).';

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

// Binaries
$string['binaries_heading']    = 'External binaries (poppler-utils)';
$string['pdftotextpath']       = 'Path to pdftotext binary';
$string['pdftotextpath_help']  = 'Absolute path to the pdftotext executable (poppler-utils). Leave empty for auto-detection.';
$string['pdftoppmpath']        = 'Path to pdftoppm binary';
$string['pdftoppmpath_help']   = 'Absolute path to the pdftoppm executable (poppler-utils), used to rasterise PDF pages to images. Leave empty for auto-detection.';
$string['pdfimagespath']       = 'Path to pdfimages binary';
$string['pdfimagespath_help']  = 'Absolute path to the pdfimages executable (poppler-utils), used to detect pages containing images. Leave empty for auto-detection.';

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
