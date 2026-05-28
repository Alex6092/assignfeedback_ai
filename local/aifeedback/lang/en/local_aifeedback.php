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
$string['max_attempts_to_grade_help']    = 'Maximum number of attempts by a single student on a single question that will be graded by the LLM. Beyond this, the question stays awaiting manual grading. Prevents abuse on quizzes with unlimited attempts. Default: 3.';

// Errors
$string['apicallfailed'] = 'The AI API call failed.';
