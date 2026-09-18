<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI tutor';

// Capabilities
$string['aichat:use']                = 'Use the AI tutor on an activity';
$string['aichat:configure']          = 'Enable and configure the AI tutor on an activity';
$string['aichat:viewconversations']  = 'View student conversations with the AI tutor';

// === Settings ===
$string['serversnote']               = 'The tutor uses the <strong>LLM servers</strong> declared in the <em>AI feedback (shared library)</em> plugin, on the slots whose "tutor" purpose is allowed. Dedicating a server to the tutor prevents assignment gradings from making students wait.';
$string['tutor_heading']             = 'Tutor behaviour';
$string['setting_tutorprompt']       = 'Tutor system prompt';
$string['setting_tutorprompt_help']  = 'Base instructions sent to the model. The activity context and the safety rules (never give the solution, ignore manipulation attempts) are appended automatically AFTER this text and cannot be disabled. Leave empty to restore the default prompt.';
$string['setting_temperature']       = 'Temperature';
$string['setting_temperature_help']  = 'Creativity of the answers, from 0 (very factual) to 1 (very varied). For a tutor, 0.4 gives natural explanations without rambling. Default: 0.4.';
$string['setting_maxtokens']         = 'Maximum answer length (tokens)';
$string['setting_maxtokens_help']    = 'Generation cap for one answer. A tutor should stay concise: 700 tokens is about ten sentences. Default: 700.';
$string['setting_historyturns']      = 'Conversation turns kept in memory';
$string['setting_historyturns_help'] = 'How many turns (question + answer) are sent back to the model with each new message. Beyond that, context gets expensive and small models lose focus. Default: 8.';
$string['setting_historychars']      = 'Maximum history size (characters)';
$string['setting_historychars_help'] = 'Second safeguard: the history is trimmed from the start so it never exceeds this size. Default: 6000.';
$string['setting_maxmessagechars']      = 'Maximum student message length';
$string['setting_maxmessagechars_help'] = 'How many characters a student can send at once. Default: 2000.';

$string['quota_heading']              = 'Per-student usage quota';
$string['quota_heading_desc']         = 'Protects the school server: without a cap, a whole class "chatting" with the AI saturates it and nobody gets an answer. Counters are computed over a sliding window, per student and ACROSS ALL activities. Set 0 to disable the corresponding cap.';
$string['setting_quotawindow']        = 'Window length (hours)';
$string['setting_quotawindow_help']   = 'Period over which the counters are computed. A sliding 4-hour window allows a full working session then frees up gradually. Default: 4.';
$string['setting_quotatokens']        = 'Token budget per window';
$string['setting_quotatokens_help']   = 'Total tokens (question + answer) allowed per student within the window. This is the most faithful measure of the compute time used. 0 = no limit. Default: 20000.';
$string['setting_quotamessages']      = 'Messages per window';
$string['setting_quotamessages_help'] = 'Additional safeguard against bursts of very short questions. 0 = no limit. Default: 40.';

$string['misc_heading']               = 'Interface and retention';
$string['setting_studentnotice']      = 'Notice shown to students';
$string['setting_studentnotice_help'] = 'Message displayed at the top of the chat panel. Since teachers can read the conversations, students must be told. Leave empty to use the default text.';
$string['setting_pollinterval']       = 'Queue refresh interval (ms)';
$string['setting_pollinterval_help']  = 'How often the browser asks for its position in the queue. Default: 1500.';
$string['setting_retention']          = 'Conversation retention (days)';
$string['setting_retention_help']     = 'Conversations with no activity for this many days are deleted by the scheduled task. 0 = keep forever. Default: 180.';

$string['studentnotice_default'] = 'Your exchanges with the tutor are recorded and may be read by your teacher.';

// === Activity form ===
$string['formheader']               = 'AI tutor';
$string['form_enabled']             = 'Enable the AI tutor';
$string['form_enabled_help']        = 'If enabled, students see a chat button on the activity page. The tutor guides them (hints, concepts, method) without ever giving the expected answer.';
$string['form_includeintro']        = 'Give the activity description to the tutor';
$string['form_includeintro_help']   = 'The activity description is added to the tutor context, so it knows what the student is working on. Untick it if the description contains parts of the answer.';
$string['form_includebrief']        = 'Give the pedagogical brief to the tutor';
$string['form_includebrief_help']   = 'If AI grading is configured on this assignment, a brief (criteria, attention points, frequent mistakes) is built automatically from the exercise and the marking scheme, then reviewed by you. The marking scheme itself is NEVER given to the tutor, so it cannot leak it.';
$string['form_briefstatus']         = 'Pedagogical brief';
$string['form_briefreview']         = 'Review / edit the brief';
$string['form_customprompt']        = 'Extra instructions for the tutor';
$string['form_customprompt_help']   = 'Free text added to the context: insist on a method, forbid a library, state the expected level... For example: "Do not suggest any external library, the exercise must be done in standard C."';

$string['briefstatus_none']    = 'No brief';
$string['briefstatus_pending'] = 'Generation pending';
$string['briefstatus_ready']   = 'Brief ready';
$string['briefstatus_failed']  = 'Generation failed';

// === Management page ===
$string['managepagetitle']      = 'AI tutor';
$string['notenabledhere']       = 'The AI tutor is not enabled on this activity. Enable it in the activity settings, "AI tutor" section.';
$string['brief_heading']        = 'Pedagogical brief';
$string['brief_explain']        = 'This text is the only thing the tutor knows about the expectations. It is built from the exercise and the marking scheme of the AI grading, but must contain no solution: review and edit it freely.';
$string['brief_save']           = 'Save brief';
$string['brief_regenerate']     = 'Regenerate with AI';
$string['brief_saved']          = 'Brief saved.';
$string['brief_queued']         = 'Brief generation requested: it will be processed at the next cron run.';
$string['brief_notavailable']   = 'Cannot generate a brief: enable the tutor and provide a marking scheme in the AI grading of this assignment.';
$string['brief_stale']          = 'The exercise or the marking scheme changed since this brief was generated. Consider regenerating it.';
$string['conversations_heading'] = 'Conversations';
$string['noconversations']      = 'No conversation yet.';
$string['stats_summary']        = '{$a->conversations} conversation(s) · {$a->students} student(s) · {$a->questions} question(s) · {$a->tokens} tokens.';
$string['student']              = 'Student';
$string['col_questions']        = 'Questions';
$string['col_tokens']           = 'Tokens';
$string['col_lastactivity']     = 'Last activity';
$string['col_actions']          = 'Actions';
$string['viewtranscript']       = 'View';
$string['transcript_for']       = 'Conversation of {$a}';
$string['backtolist']           = 'Back to the list';
$string['deleteduser']          = 'Deleted user';

$string['status_done']      = 'Completed';
$string['status_pending']   = 'Waiting';
$string['status_streaming'] = 'Generating';
$string['status_failed']    = 'Failed';
$string['status_cancelled'] = 'Interrupted';

// === Widget (strings passed to JavaScript) ===
$string['widget_title']        = 'AI tutor';
$string['widget_open']         = 'Open the AI tutor';
$string['widget_close']        = 'Close';
$string['widget_new']          = 'New conversation';
$string['widget_placeholder']  = 'Ask your question...';
$string['widget_send']         = 'Send';
$string['widget_stop']         = 'Stop';
$string['widget_retry']        = 'Try again';
$string['widget_sendhint']     = 'Enter to send, Shift+Enter for a new line.';
$string['widget_welcome']      = 'Hello! I can help you understand what is being asked: tell me where you are stuck. I will not give you the answer, but I will put you on the right track.';
$string['widget_connecting']   = 'Sending...';
$string['widget_generating']   = 'The tutor is writing its answer...';
$string['widget_queued_next']  = 'You are next in line...';
$string['widget_queued_n']     = '{$a} person(s) ahead of you...';
$string['widget_interrupted']  = '(answer interrupted)';
$string['widget_quota']        = 'Quota:';
$string['widget_networkerror'] = 'The tutor is temporarily unavailable. Please try again in a moment.';

// === Errors ===
$string['notsupported']    = 'The AI tutor is not available on this type of activity.';
$string['tutordisabled']   = 'The AI tutor is not enabled on this activity.';
$string['error_empty']     = 'Your message is empty.';
$string['error_toolong']   = 'Your message is longer than {$a} characters. Please shorten it.';
$string['error_busy']      = 'An answer is already being generated. Please wait for it to finish.';
$string['error_quota']     = 'You have reached your tutor usage quota. Try again in {$a}.';
$string['error_noserver']  = 'No server is configured for the AI tutor. Please tell your teacher.';
$string['error_notfound']  = 'Message not found.';
$string['error_expired']   = 'The request expired before it could be processed. Please ask again.';
$string['error_badaction'] = 'Unknown action.';
$string['error_llm']       = 'The tutor could not answer (server unavailable). Please try again in a moment.';
$string['brieferror_empty'] = 'The model returned an empty brief.';

// === Scheduled task ===
$string['task_purge'] = 'AI tutor conversation cleanup';

// === Privacy ===
$string['privacy:metadata:conversation']             = 'Conversations between a student and the AI tutor on an activity.';
$string['privacy:metadata:conversation:userid']      = 'The student who owns the conversation.';
$string['privacy:metadata:conversation:status']      = 'Conversation status (active, closed).';
$string['privacy:metadata:conversation:timecreated'] = 'When the conversation started.';
$string['privacy:metadata:message']                  = 'Messages exchanged with the AI tutor.';
$string['privacy:metadata:message:userid']           = 'The student the exchange belongs to.';
$string['privacy:metadata:message:role']             = 'Author of the message (student or tutor).';
$string['privacy:metadata:message:content']          = 'The message text.';
$string['privacy:metadata:message:tokens']           = 'Number of tokens used by the exchange.';
$string['privacy:metadata:message:timecreated']      = 'When the message was sent.';
$string['privacy:metadata:llm']                      = 'Messages are sent to an external LLM service to produce the answer.';
$string['privacy:metadata:llm:message']              = 'The student message and the activity context.';
$string['privacy:path:conversations']                = 'AI tutor';
$string['privacy:path:conversation']                 = 'Conversation {$a}';
