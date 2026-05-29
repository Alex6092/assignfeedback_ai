<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']         = 'AI Feedback';
$string['privacy:metadata']   = 'The AI Feedback plugin stores student submission text sent to an external LLM and the resulting feedback JSON.';

$string['enabled']      = 'AI Feedback';
$string['enabled_help'] = 'If enabled, the assignment is corrected by an AI that can leave feedback comments for each submission.';

$string['default']                  = 'Enabled by default';
$string['default_help']             = 'If set, AI Feedback will be active for all new assignments.';
$string['sharedconfignote']         = 'Shared settings (API URL, model, API key, vision, binaries) are managed in the <em>AI feedback (shared library)</em> plugin — see <strong>Site administration → Plugins → Local plugins → AI feedback (shared library)</strong>.';
$string['apiurl']                   = 'LM Studio API URL';
$string['apiurl_help']              = 'OpenAI-compatible endpoint. Default: http://localhost:1234/v1/chat/completions';
$string['model']                    = 'Model name';
$string['model_help']               = 'Model identifier used in API calls, e.g. qwen3.5-9b-instruct.';
$string['apikey']                   = 'API key';
$string['apikey_help']              = 'Secret key sent as the HTTP header "Authorization: Bearer ...". Leave empty if the server does not require one (LM Studio default). The value is encrypted at rest.';
$string['apiurl_override']          = 'Override the site API URL';
$string['model_override']           = 'Override the site model name';
$string['apikey_override']          = 'Override the site API key';
$string['defaultsystemprompt']      = 'Default system prompt';
$string['defaultsystemprompt_help'] = 'Used when no assignment-level prompt is defined.';
$string['pdftotextpath']            = 'Path to pdftotext binary';
$string['pdftotextpath_help']       = 'Absolute path to the pdftotext executable (poppler-utils package). Leave empty to auto-detect in /usr/bin, /usr/local/bin, etc.';

// === Vision ===
$string['vision_heading']               = 'Vision (image reading)';
$string['vision_enabled']               = 'Send images to the model';
$string['vision_enabled_help']          = 'If enabled, images found in submissions (PDFs with illustrations, embedded images in the online editor, PNG/JPEG attachments, etc.) are sent to the LLM together with the text. Requires a multimodal model in LM Studio.';
$string['vision_enabled_override']      = 'Override the site vision setting';
$string['maximagespersubmission']       = 'Maximum images per submission';
$string['maximagespersubmission_help']  = 'Global cap on images sent to the LLM for a single student submission. Controls context size and generation time. Default: 5.';
$string['imagemindimension']            = 'Minimum image dimension (pixels)';
$string['imagemindimension_help']       = 'Images extracted from a PDF whose width OR height is below this value are skipped (filters out decorative thumbnails). Default: 200.';
$string['pdftoppmpath']                 = 'Path to pdftoppm binary';
$string['pdftoppmpath_help']            = 'Absolute path to the pdftoppm executable (poppler-utils), used to rasterise PDF pages that contain images. Leave empty for auto-detection.';
$string['pdfimagespath']                = 'Path to pdfimages binary';
$string['pdfimagespath_help']           = 'Absolute path to the pdfimages executable (poppler-utils), used to detect pages containing images. Leave empty for auto-detection.';

$string['systemprompt']           = 'System prompt';
$string['systemprompt_help']      = 'Instructions sent to the AI as the system role.';
$string['exercise']               = 'Exercise statement';
$string['exercise_help']          = 'The exercise text the student had to answer.';
$string['expectedanswer']         = 'Expected answer / marking scheme';
$string['expectedanswer_help']    = 'Reference answer the AI uses to evaluate submissions.';
$string['competencies']           = 'Evaluated competencies';
$string['competencies_help']      = 'Skills or criteria the AI must evaluate, one per line.';

$string['nofeedback']            = 'No AI feedback generated yet.';
$string['feedbackerror']         = 'Error reading AI feedback. Please regenerate.';
$string['generate']              = 'Generate AI feedback';
$string['regenerate']            = 'Regenerate AI feedback';
$string['strengths']             = 'Strengths';
$string['improvements']          = 'Areas for improvement';
$string['detailedfeedback']      = 'Detailed feedback';
$string['competency_scores']     = 'Competency scores';
$string['competency']            = 'Competency';
$string['mastery_level']         = 'Mastery level';
$string['commentary']            = 'Commentary';
$string['score']                 = 'Score';

$string['nosubmissiontext']      = '(No submission text found)';
$string['noconfiguration']       = 'AI Feedback is not configured for this assignment.';
$string['generationerror']       = 'Error calling the AI. Check your LM Studio connection.';
$string['generationsuccess']     = 'AI feedback generated successfully.';
$string['generationpending']     = 'AI feedback generation in progress…';
$string['generationfailed']      = 'Generation failed';
$string['queued']                = 'Feedback added to the generation queue.';
$string['queuedcount']           = '{$a} generation(s) added to the queue.';
$string['lockcontention']        = 'Another AI call is in progress, will retry later.';

$string['taskname']              = 'Deferred AI feedback generation';

// Manage page
$string['manage']                = 'Manage';
$string['managepagetitle']       = 'AI feedback management';
$string['student']               = 'Student';
$string['status']                = 'Status';
$string['attempts']              = 'Attempts';
$string['lasterror']             = 'Last error';
$string['lastupdate']            = 'Last update';
$string['actions']               = 'Actions';
$string['retry']                 = 'Retry';
$string['statuspending']         = 'In progress';
$string['statusgenerated']       = 'Generated';
$string['statusfailed']          = 'Failed';
$string['nofbrow']               = 'No row';
$string['nosubmissions']         = 'No submitted submissions for this assignment.';
$string['requeuefailed']         = 'Retry failed';
$string['requeueall']            = 'Regenerate all';
$string['requeuemissing']        = 'Generate for submissions without feedback';
$string['countgenerated']        = '{$a} generated';
$string['countpending']          = '{$a} in progress';
$string['countfailed']           = '{$a} failed';
