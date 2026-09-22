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

$string['moderation_heading']             = 'Moderation';
$string['moderation_heading_desc']        = 'Each student message is analysed separately, afterwards, by a distinct call going through the job queue (grading server): the tutor is not slowed down and streaming is unaffected. A fresh context reads the message as data to classify, which makes it much harder to manipulate than the tutor itself. Cost: one small LLM call (about 300 tokens) per message.';
$string['setting_moderation']             = 'Analyse student messages';
$string['setting_moderation_help']        = 'Flags to the teacher attempts to manipulate the tutor, threats, blackmail, insults, inappropriate content and signs of serious distress. Flags appear on the activity "AI tutor" page.';
$string['setting_moderationnotify']       = 'Notify teachers';
$string['setting_moderationnotify_help']  = 'Sends a Moodle notification to the activity teachers when a message is flagged (at most one per conversation per hour). The notification does not include the student text: only the category, the reason and a link to the conversation.';
$string['messageprovider:flagged']        = 'Student message flagged by the AI tutor';

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
$string['form_includeintro_help']   = 'The activity instructions are added to the tutor context, so it knows what the student is working on: the description and, for an assignment, the "Activity instructions". Untick it if they contain parts of the answer.';
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
$string['col_flags']            = 'Flags';
$string['filter_flagged']       = 'Show the {$a} flagged conversation(s)';
$string['filter_all']           = 'Show all conversations';

// Moderation
$string['flag_label']            = 'Flagged: {$a}';
$string['flag_pending']          = 'Moderation analysis in progress...';
$string['flag_failed']           = 'Moderation analysis failed (server unavailable).';
$string['moderation_reanalyse']  = 'Retry failed analyses';
$string['moderation_requeued']   = '{$a} message(s) queued for analysis again.';
$string['flagcat_manipulation']        = 'manipulation attempt';
$string['flagcat_menace']              = 'threat';
$string['flagcat_chantage']            = 'blackmail';
$string['flagcat_insulte']             = 'insult or harassment';
$string['flagcat_contenu_inapproprie'] = 'inappropriate content';
$string['flagcat_detresse']            = 'signs of distress';
$string['notify_subject']  = 'AI tutor: flagged message ({$a->category}) — {$a->student}';
$string['notify_body']     = 'A message from {$a->student} to the AI tutor was flagged.

Course: {$a->course}
Activity: {$a->activity}
Category: {$a->category}
Reason: {$a->reason}

View the conversation: {$a->url}

This flag is produced automatically by an AI and may be wrong: check the conversation before taking any decision.';
$string['notify_small']    = 'AI tutor: message from {$a->student} flagged ({$a->category})';
$string['notify_linkname'] = 'View the conversation';

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
$string['widget_resize']       = 'Resize the window (arrow keys, Home to reset)';
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
$string['privacy:metadata:message:flagstatus']       = 'Result of the moderation analysis of the message.';
$string['privacy:metadata:message:flagcategory']     = 'Category of the flag, if any.';
$string['privacy:metadata:message:flagreason']       = 'Reason for the flag, written by the moderation AI.';
$string['privacy:metadata:message:timecreated']      = 'When the message was sent.';
$string['privacy:metadata:llm']                      = 'Messages are sent to an external LLM service to produce the answer.';
$string['privacy:metadata:llm:message']              = 'The student message and the activity context.';
$string['privacy:path:conversations']                = 'AI tutor';
$string['privacy:path:conversation']                 = 'Conversation {$a}';
$string['privacy:metadata:message:websearches']      = 'Number of web searches made by the tutor for this answer.';
$string['privacy:metadata:message:toolcalls']        = 'The web search queries written by the tutor for this answer, and their outcome.';
$string['privacy:metadata:websearch']  = 'If web search is enabled and the student has entered their own keys, the tutor queries an external search engine (Tavily, then Brave Search as a fallback) with the student\'s key, and therefore under the student\'s own account. Only the query written by the model is sent, after removing the student\'s first name, last name, username and email.';
$string['privacy:metadata:websearch:query']          = 'The text of the search query.';
$string['privacy:metadata:message:pagereads']        = 'Number of pages or documents read by the tutor for this answer.';
$string['privacy:metadata:pagereader']               = 'In "material search" mode, the server downloads the pages and documents the tutor reads, from the site publishing them. Only the page address is sent; no student data.';
$string['privacy:metadata:pagereader:url']           = 'The address of the page or document read.';

// === Web search ===
$string['websearch_heading']          = 'Web search';
$string['websearch_heading_desc']      = 'Lets the tutor search the Internet when the information it needs is recent or external. The model decides to search; the Moodle server runs the search. Each student uses THEIR OWN API keys (Preferences > "AI tutor: my search keys"): Tavily first (1,000 free searches per month, no credit card), then Brave Search as a fallback. Without a key, the tutor does not go on the Internet for that student. The school pays nothing and each key is capped. Teachers must then allow search activity by activity.';
$string['websearch_link']             = 'View status, usage and tests.';
$string['setting_websearch']          = 'Enable web search';
$string['setting_websearch_help']      = 'Site switch. Once enabled, students can enter their keys in their Preferences, and each teacher can allow search in the tutor settings of their activity. Before enabling it, run the "tool call" test on each tutor server: LM Studio does not recognise every model correctly.';
$string['setting_wsapikey']            = 'Brave test key';
$string['setting_wsapikey_help']       = 'Brave Search API key used ONLY by the diagnostics page ("Test Brave"), never for students, who use their own keys. Encrypted at rest, never shown again.';
$string['setting_wscap']               = 'Cap per Brave key (31 days)';
$string['setting_wscap_help']          = 'Maximum number of searches per Brave key (a student\'s or the test key) over a ROLLING 31-day window. Brave charges the key owner\'s card beyond its monthly credits ($5, about 1,000 searches) instead of refusing: this cap protects students from an invoice for searches made through Moodle. With a rolling 31-day window, no billing cycle can exceed this number. 0 = Brave is never used. Default: 900.';
$string['setting_wsperuser']          = 'Searches per student per window';
$string['setting_wsperuser_help']     = 'Maximum number of searches per student over the student quota window (4 h by default, see above), across all activities. Prevents a single student from using up the whole school budget. 0 = no limit. Default: 10.';
$string['setting_wsmaxcalls']         = 'Maximum searches per answer';
$string['setting_wsmaxcalls_help']    = 'Maximum number of searches for a single tutor answer (protection against loops). Once the limit is reached, the tool is no longer offered and the model must answer. Each search adds a generation round (the server re-reads the prompt and the results). Between 1 and 5. Default: 2.';
$string['setting_wsmaxresults']       = 'Results per search';
$string['setting_wsmaxresults_help']  = 'Number of results (title, URL, snippet) passed to the model for each search. More results = more context to read. Between 1 and 10. Default: 5.';
$string['setting_wstimeout']          = 'Search timeout (seconds)';
$string['setting_wstimeout_help']     = 'Beyond this, the search is abandoned and the tutor answers without the Web. The student waits meanwhile. Between 2 and 20. Default: 6.';
$string['setting_wscachedays']        = 'Search cache duration (days)';
$string['setting_wscachedays_help']   = 'The results of a successful search are kept and served again to any student asking the same query (case and spacing ignored): free, instant, and available even when the engine is down. A search served from the cache counts neither against the site cap nor against the student quota. The model is told how old the results are. Shorter = fresher information; longer = more savings. 0 = no cache. Between 0 and 90. Default: 7.';
$string['cachedef_searchcache']       = 'AI tutor web search results';
$string['cachedef_pagecache']         = 'Pages and datasheets read by the AI tutor';
$string['material_heading']           = 'Material search (page reading)';
$string['material_heading_desc']      = 'In activities set to "Material search", the tutor can read manufacturer pages and PDF datasheets found by its searches, to pick out precise characteristics. Reading is free (no Brave budget); only addresses found by a search, documents linked from a page already read and addresses given by the student can be read. Internal addresses are refused by the Moodle HTTP security policy. PDFs use pdftotext (shared library settings).';
$string['setting_wstoolcalls']        = 'Tool calls per answer';
$string['setting_wstoolcalls_help']   = 'Searches and reads in total for a single tutor answer in material mode (searches remain limited by the "Maximum searches per answer" setting too). Beyond that, the model must answer. Each call adds a generation round. Between 2 and 8. Default: 5.';
$string['setting_wsreadsperuser']     = 'Page reads per student per window';
$string['setting_wsreadsperuser_help'] = 'Maximum number of pages or documents read for a student over the student quota window, across all activities. Protects the server (download, extraction). 0 = no limit. Default: 30.';
$string['setting_wsmaxmb']            = 'Maximum document size (MB)';
$string['setting_wsmaxmb_help']       = 'Beyond this, the page or PDF is not downloaded. Between 1 and 50. Default: 8.';
$string['setting_wsreadtimeout']      = 'Page read timeout (seconds)';
$string['setting_wsreadtimeout_help'] = 'Maximum download time. The student waits meanwhile. Between 3 and 30. Default: 10.';
$string['setting_wspagecachehours']   = 'Page cache duration (hours)';
$string['setting_wspagecachehours_help'] = 'The text extracted from a page or datasheet is kept and served again: a class reading the same datasheet only downloads it once. 0 = no cache. Between 0 and 720. Default: 24.';

$string['form_websearch']             = 'Tutor searches';
$string['form_websearch_help']        = '<strong>None</strong>: the tutor answers from its own knowledge only.<br><strong>Occasional web search</strong>: it may search the Internet when recent or external information is needed (software version, official documentation...) and cites its sources.<br><strong>Material search</strong>: for an activity where the student chooses equipment from a specification (I/O board, sensor...). The tutor searches for references, reads manufacturer pages and datasheets to pick out their characteristics, suggests at most 3 leads per answer, but never fills in the comparative study and never chooses for the student.<br>In every case, it never searches for the solution of the activity. Leave on "None" if the activity is not suited to it (exercise whose solution is easy to find online, assessment).';
$string['form_websearch_none']        = 'None';
$string['form_websearch_web']         = 'Occasional web search';
$string['form_websearch_material']    = 'Material search (web + reading pages and datasheets)';
$string['form_websearchsites']        = 'Reference sites';
$string['form_websearchsites_help']   = 'Optional, one domain per line (e.g. teracomsystems.com, hw-group.com, icpdas-europe.com, gotronic.fr, raspberrypi.com). The tutor favours these sites in its searches (site: operator): manufacturer and usual distributor pages rather than marketplaces or blogs.';
$string['widget_searching']            = 'Searching the Web: "{$a}"...';
$string['widget_reading']             = 'Reading a page: {$a}...';
$string['ws_sources_heading']          = 'Sources consulted (web search: {$a}):';

$string['ws_transcript']              = 'Web searches:';
$string['ws_results']                 = '{$a} result(s)';
$string['ws_cached']                  = '(cache)';
$string['ws_cache_hits']              = '{$a->hits} search(es) served from the cache over the last 31 days, counted against nothing (cache duration: {$a->days} day(s)).';
$string['ws_unavailable']             = 'not performed ({$a})';
$string['ws_noquery']                 = 'invalid query';
$string['ws_read']                    = 'Read:';
$string['ws_read_done']               = '{$a->type}, {$a->chars} characters kept';
$string['ws_reason_url_not_allowed']      = 'address not allowed';
$string['ws_reason_fetch_error']          = 'page unreachable';
$string['ws_reason_too_large']            = 'document too large';
$string['ws_reason_unsupported_type']     = 'unreadable format';
$string['ws_reason_empty_content']        = 'unreadable content (JavaScript, scanned PDF...)';
$string['ws_reason_pdftotext_missing']    = 'PDF reading unavailable (pdftotext)';
$string['ws_reason_reads_exhausted']      = 'student page-read quota reached';
$string['ws_offered_unused']          = 'search offered to the model, not used';
$string['ws_notoffered']              = 'search not offered to the model ({$a})';
$string['ws_reason_not_configured']       = 'API key missing';
$string['ws_reason_provider_unavailable'] = 'engine suspended';
$string['ws_reason_quota_exhausted']      = 'site cap reached';
$string['ws_reason_user_quota_exhausted'] = 'student quota reached';
$string['ws_reason_tool_call_limit']      = 'searches-per-answer limit reached';
$string['ws_reason_invalid_query']        = 'empty or invalid query';
$string['ws_reason_unknown_tool']         = 'unknown tool requested by the model';
$string['ws_reason_budget_busy']          = 'counter temporarily unavailable';
$string['ws_reason_rate_limited']         = 'too many requests per second';
$string['ws_reason_provider_quota']       = 'engine plan quota used up';
$string['ws_reason_auth_error']           = 'API key rejected';
$string['ws_reason_provider_error']       = 'engine or network error';
$string['ws_reason_timeout']              = 'timed out';

$string['ws_page']                    = 'AI tutor: web search';
$string['ws_page_intro']              = 'Search engine status, budget usage and compatibility tests.';
$string['ws_state_heading']           = 'Status';
$string['ws_state_disabled']          = 'Web search is disabled for the site (AI tutor settings). Tests remain available.';
$string['ws_state_blocked']           = 'Search suspended: {$a->reason}, until {$a->until}. Meanwhile the tutor answers without the Web.';
$string['ws_until_manual']            = 'manually put back in service';
$string['ws_unblock']                 = 'Put back in service';
$string['ws_unblocked']               = 'Web search put back in service.';
$string['ws_budget_heading']          = 'Budget';
$string['ws_budget_explain']           = 'Each key is capped over a rolling 31-day window (see the table). Per student: {$a->peruser} search(es) per {$a->hours} h window (0 = no limit). Per answer: at most {$a->maxcalls}.';
$string['ws_ratelimit']               = 'Latest limits announced by {$a->provider} ({$a->time}): {$a->persecond} request(s)/s, {$a->permonth} per plan period, {$a->remaining} of them left; reset in {$a->reset}.';
$string['ws_ratelimit_note']           = 'These figures are those of the Brave plan of the last key used, not its free credits: Brave bills beyond the credits instead of refusing. Only the per-key cap protects.';
$string['ws_lasterror']               = 'Last failure ({$a->time}): {$a->reason} — {$a->detail}';
$string['ws_testsearch_explain']       = '"Test" runs a real search ("Moodle LMS") with the engine\'s TEST key (plugin settings), counted against that key\'s cap. A success lifts any suspension of the engine. Students use their own keys.';
$string['ws_testsearch_ok']           = 'Search succeeded: {$a} result(s).';
$string['ws_testsearch_failed']       = 'Search failed: {$a}.';
$string['ws_testtools_heading']       = 'Test tool calling on the tutor servers';
$string['ws_testtools_explain']       = 'Asks the model of each server a question that requires a search, then sends it back a fake result (no call to the engine, nothing counted). Checks that LM Studio recognises the tool call when streaming (otherwise it would show up as raw text to the student) and that the model then writes its answer. The test bypasses the queue: preferably run it outside class hours. It may take one or two minutes.';
$string['ws_col_server']              = 'Server';
$string['ws_globalmodel']             = 'global model';
$string['ws_testtools']               = 'Test tool calling';
$string['ws_notutorserver']           = 'No server accepts the tutor (shared library settings).';
$string['ws_check_ok']                = 'OK';
$string['ws_check_failed']            = 'Failed';
$string['ws_check_info']              = 'Info';
$string['ws_testtools_result']        = 'Result for server {$a->id} ({$a->seconds} s)';
$string['ws_testtools_verdict_ok']    = 'This server handles web search correctly.';
$string['ws_testtools_verdict_bad']   = 'This server does not handle tool calling correctly with this model. Do not enable web search while it serves the tutor: update LM Studio, try another model, or remove its "tutor" purpose.';
$string['ws_answer1']                 = 'First-round text (should be empty or short, without any call tag):';
$string['ws_answer2']                 = 'Final answer after the fake result:';
$string['ws_top_activities']          = 'Activities using the most web searches (31 days):';
$string['ws_activity_used']            = '{$a} search(es)';
$string['ws_testread_heading']        = 'Test reading a page';
$string['ws_testread_explain']        = 'Reads a page or PDF through the same path as the tutor read_page tool (download, extraction, selection of the passages relevant to the keywords), without cache and without calling the search engine. The result shown is exactly what the model would receive.';
$string['ws_testread_nopdftotext']    = 'pdftotext cannot be found on this server: PDF datasheets cannot be read (see the shared library settings, poppler-utils binaries).';
$string['ws_testread_url']            = 'Page or PDF address';
$string['ws_testread_focus']          = 'Keywords (focus)';
$string['ws_testread']                = 'Test reading';
$string['ws_testread_ok']             = 'Read succeeded.';
$string['ws_testread_failed']         = 'Could not read: {$a}.';
$string['ws_testread_output']         = 'Text sent to the model:';
$string['diag_step_request']          = 'Request accepted by the server';
$string['diag_step_toolcall']         = 'web_search tool call recognised in the stream (tool_calls)';
$string['diag_step_noleak']           = 'No tool call tag in the first-round text';
$string['diag_step_arguments']        = 'Valid JSON arguments with a query';
$string['diag_step_final']            = 'Answer written after the result, without a new call';
$string['diag_step_noleak2']          = 'No tool call tag in the final answer';

// === Clés de recherche personnelles ===
$string['ws_sources_heading_plain']    = 'Sources consulted:';
$string['ws_fallback']                 = 'fallback engine';
$string['setting_wstavilykey']         = 'Tavily test key';
$string['setting_wstavilykey_help']    = 'Tavily key used ONLY by the diagnostics page ("Test Tavily"), never for students. Encrypted at rest, never shown again. Free account, no credit card, at app.tavily.com.';
$string['setting_wstavilycap']         = 'Cap per Tavily key (31 days)';
$string['setting_wstavilycap_help']    = 'Maximum number of searches per Tavily key over a rolling 31-day window. Tavily\'s free plan gives 1,000 credits per month and never bills (no card): beyond that, Tavily refuses and the tutor falls back to Brave if the student has a Brave key. This cap only avoids calls bound to fail. 0 = no local cap. Default: 1000.';
$string['widget_needkeys']             = 'For the tutor to search the Web, add your personal search key (free).';
$string['widget_needkeys_link']        = 'Add my key';
$string['ws_state_userkeys']           = 'Students search with their own keys; the test keys of the settings are only used by the tests of this page.';
$string['ws_col_engine']               = 'Engine';
$string['ws_col_users']                = 'Students with a key';
$string['ws_col_used']                 = 'Searches (31 d)';
$string['ws_col_keycap']               = 'Cap per key';
$string['ws_col_state']                = 'Status';
$string['ws_col_test']                 = 'Test (test key)';
$string['ws_state_inservice']          = 'In service';
$string['ws_notestkey']                = 'no test key';
$string['ws_testsearch_engine']        = 'Test {$a}';
$string['privacy:metadata:preference:key'] = 'The student\'s personal web search API key (encrypted).';
$string['privacy:metadata:preference:keystate'] = 'Status of the search key (rejected, credits used up) and end of suspension.';
$string['privacy:keyconfigured']       = 'key configured ({$a})';
$string['mykeys_page']                 = 'AI tutor: my search keys';
$string['mykeys_guest']                = 'Guests cannot save a key.';
$string['mykeys_loggedinas']           = 'Search keys are personal: they can neither be viewed nor changed while logged in as another user.';
$string['mykeys_disabled']             = 'Tutor web search is not enabled on this site.';
$string['mykeys_intro']                = 'The AI tutor can search the Internet to help you, in the activities where the teacher allowed it. It uses YOUR own search keys for that: without a key, it does not go on the Internet.<br><strong>Tavily (recommended, free)</strong>: create an account at <a href="https://app.tavily.com" target="_blank" rel="noopener noreferrer">app.tavily.com</a> (no credit card required), copy your key (it starts with "tvly-") and paste it below. The free plan gives 1,000 searches per month.<br><strong>Brave Search (optional, fallback)</strong>: used only if Tavily does not answer or if its monthly credits are used up. Warning: Brave requires a credit card and bills beyond $5 of credits per month; the tutor stops at {$a} searches over 31 days with this key.';
$string['mykeys_status']               = 'Status';
$string['mykeys_newkey']               = 'New key';
$string['mykeys_tavily']               = 'Tavily key';
$string['mykeys_tavily_help']          = 'Paste the key of your Tavily account (app.tavily.com dashboard, it starts with "tvly-"). Leave empty to keep the saved key.';
$string['mykeys_brave']                = 'Brave Search key';
$string['mykeys_brave_help']           = 'Optional. Paste the key of your Brave Search API account (api-dashboard.search.brave.com). Leave empty to keep the saved key.';
$string['mykeys_remove']               = 'Remove this key';
$string['mykeys_invalidformat']        = 'This key does not look valid (8 to 200 characters: letters, digits, hyphens, dots or underscores).';
$string['mykeys_none']                 = 'No key';
$string['mykeys_saved']                = 'Key saved (ends with {$a})';
$string['mykeys_suspended']            = 'Key suspended: {$a->reason}, until {$a->until}.';
$string['mykeys_until_change']         = 'the key is changed or a test succeeds';
$string['mykeys_used']                 = 'Tutor searches with this key over 31 days: {$a->used} (cap: {$a->cap}).';
$string['mykeys_savedmsg']             = 'Your keys have been saved.';
$string['mykeys_test']                 = 'Test my {$a} key';
$string['mykeys_test_brave_note']      = 'Testing the Brave key runs a real search (1 request counted).';
$string['mykeys_test_ok_tavily']       = 'Tavily key valid. Credits used this month: {$a->usage} of {$a->limit}.';
$string['mykeys_test_ok_brave']        = 'Brave key valid: search succeeded ({$a} result(s)).';
$string['mykeys_test_failed']          = 'The key could not be verified: {$a}.';
$string['mykeys_privacy']              = 'Your keys are encrypted in Moodle and never shown again, nor sent to the browser or to the AI. The tutor\'s searches go out under YOUR account with the engine (Tavily, Brave): only the search text is sent, without your name or email. You can remove a key at any time.';
