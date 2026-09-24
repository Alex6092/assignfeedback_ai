<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'MoodleSearch';

// Capabilities.
$string['moodlesearch:use']            = 'Search with MoodleSearch';
$string['moodlesearch:viewreport']     = 'View the searches of the course\'s students';
$string['moodlesearch:viewsitereport'] = 'View the searches of the whole site';
$string['moodlesearch:manageaccess']   = 'Enable or disable MoodleSearch per cohort';

// Search page.
$string['guest']              = 'MoodleSearch is not available to guests.';
$string['search_placeholder'] = 'Search the web';
$string['search_button']      = 'Search';
$string['period']             = 'Period';
$string['period_any']         = 'Any time';
$string['period_day']         = 'Past 24 hours';
$string['period_week']        = 'Past week';
$string['period_month']       = 'Past month';
$string['period_year']        = 'Past year';
$string['tab_web']            = 'Web';
$string['tab_news']           = 'News';
$string['notice_monitoring']  = 'Your searches and the sites you open are recorded and can be viewed by your teachers.';
$string['noresults']          = 'No results for "{$a}".';
$string['moreresults']        = 'More results';
$string['badge_blocked']      = 'Blocked by the school';
$string['badge_blocked_help'] = 'This site is blacklisted: opening it will not unblock access.';
$string['poweredby']          = 'Results provided by Tavily, with no AI-generated answer';
$string['keyusage']           = 'Searches made with your key over the last 31 days: {$a->used} (cap: {$a->cap})';
$string['recent']             = 'My recent searches';

// Refusals and errors.
$string['reason_disabled']             = 'MoodleSearch is not enabled on this site.';
$string['reason_capability']           = 'You do not have access to MoodleSearch.';
$string['reason_cohort']               = 'MoodleSearch is currently disabled for your class.';
$string['reason_exam']                 = 'An exam mode is active in your class: MoodleSearch is closed.';
$string['reason_nokey']                = 'To search with MoodleSearch, add your personal Tavily key (free): <a href="{$a}">my search keys</a>.';
$string['reason_ratelimit']            = 'You have made many searches: try again in a while (at most {$a} searches per hour).';
$string['reason_emptyquery']           = 'Type what you are looking for.';
$string['reason_auth_error']           = 'Your Tavily key is refused: check it in your search keys.';
$string['reason_provider_quota']       = 'Your Tavily key has no credits left this month.';
$string['reason_quota_exhausted']      = 'Your key has reached its search cap for the last 31 days.';
$string['reason_budget_busy']          = 'MoodleSearch is very busy: try again in a few seconds.';
$string['reason_provider_unavailable'] = 'The search engine is temporarily unavailable: try again in a minute.';
$string['reason_provider_error']       = 'The search engine did not answer properly: try again in a minute.';
$string['reason_timeout']              = 'The search engine took too long to answer: try again.';
$string['reason_rate_limited']         = 'Too many searches at once with your key: try again in a few seconds.';
$string['reason_invalid_query']        = 'This search was not accepted by the engine: rephrase it.';
$string['reason_other']                = 'The search failed: try again in a while.';

// Click: opening the site (OPNsense).
$string['opnsense_reason']  = 'Result opened from MoodleSearch';
$string['click_notfound']   = 'This result no longer exists: run the search again.';
$string['open_title']       = 'Opening {$a}';
$string['open_wait']        = 'Opening access to {$a} for your class…';
$string['open_link']        = 'Go to {$a}';
$string['open_declaremac']  = 'Declare my computer';
$string['open_back']        = 'Back to results';
$string['open_pending']     = 'Your request to access this site was sent to your teacher. The site will open once it is accepted.';
$string['open_blocked']     = 'This site is blacklisted: access is not opened.';
$string['open_exam']        = 'An exam mode is active in your class: no site is opened.';
$string['open_invalid']     = 'This address cannot be opened.';
$string['open_wildcard']    = 'This address cannot be opened.';
$string['open_nomac']       = 'Your computer is not declared: the site cannot be opened for you. Declare your computer, then try again.';
$string['open_error']       = 'Access could not be opened for now: the site may be blocked.';

// Click status (report).
$string['click_opened']        = 'opened for the class';
$string['click_already']       = 'already open';
$string['click_pending']       = 'request pending';
$string['click_blocked']       = 'blacklisted';
$string['click_exam']          = 'exam mode';
$string['click_nomac']         = 'computer not declared';
$string['click_error']         = 'opening failed';
$string['click_none']          = 'no firewall';
$string['click_waiting']       = 'in progress';
$string['click_nocohort']      = 'no class';
$string['click_notconfigured'] = 'class not configured';
$string['click_invalid']       = 'invalid address';

// Report.
$string['report_title']          = 'MoodleSearch searches';
$string['report_sitetitle']      = 'MoodleSearch: site searches';
$string['report_intro']          = 'Students\' searches and the sites they opened, with the firewall\'s response. Searches are not tied to any course: all of the students\' searches are listed here.';
$string['report_allstudents']    = 'All students';
$string['report_days']           = 'Last {$a} days';
$string['report_alltime']        = 'All';
$string['report_filter']         = 'Filter';
$string['report_none']           = 'No searches for these criteria.';
$string['report_col_time']       = 'Date';
$string['report_col_student']    = 'Student';
$string['report_col_query']      = 'Search';
$string['report_col_results']    = 'Results';
$string['report_col_opened']     = 'Opened sites';
$string['report_status_refused'] = 'refused';
$string['report_status_error']   = 'error';

// Cohorts.
$string['cohorts_title']          = 'MoodleSearch: access per cohort';
$string['cohorts_intro']          = 'A cohort follows the default setting ({$a}), or is enabled, or disabled. A disabled cohort always wins: to close MoodleSearch for a class during an assessment, disable its cohort. The change is immediate. An OPNsense exam mode also closes MoodleSearch for that class.';
$string['cohorts_default_open']   = 'allowed';
$string['cohorts_default_closed'] = 'closed';
$string['cohorts_none']           = 'No cohort on this site.';
$string['cohorts_saved']          = 'Access updated.';
$string['cohorts_col_cohort']     = 'Cohort';
$string['cohorts_col_context']    = 'Context';
$string['cohorts_col_members']    = 'Members';
$string['cohorts_col_access']     = 'MoodleSearch access';
$string['cohorts_col_actions']    = 'Change';
$string['cohorts_state_on']       = 'Enabled';
$string['cohorts_state_off']      = 'Disabled';
$string['cohorts_state_default']  = 'Default ({$a})';
$string['cohorts_exam']           = 'OPNsense exam mode';
$string['cohorts_set_on']         = 'Enable';
$string['cohorts_set_off']        = 'Disable';
$string['cohorts_set_default']    = 'Default';

// Settings.
$string['settings_intro']               = 'MoodleSearch is a web search engine without AI: it only shows results. Everyone searches with their personal Tavily key, managed in their Preferences ("my search keys"); usage, the per-key cap and the search cache are those of the AI tutor (AI tutor "Web search" settings). Clicking a result asks the OPNsense block to open the site for the class, unless it is blacklisted.';
$string['setting_enabled']              = 'Enable MoodleSearch';
$string['setting_enabled_desc']         = 'Adds "MoodleSearch" to the primary navigation.';
$string['setting_defaultaccess']        = 'Default access';
$string['setting_defaultaccess_desc']   = 'Ticked: open to everyone except disabled cohorts. Unticked: only for enabled cohorts.';
$string['setting_perpage']              = 'Results per page';
$string['setting_perpage_desc']         = '"More results" shows 20 (a new search, unless cached).';
$string['setting_maxperhour']           = 'Searches per person per hour';
$string['setting_maxperhour_desc']      = 'Searches actually sent to the engine; those served from the cache do not count.';
$string['setting_country']              = 'Preferred country';
$string['setting_country_desc']         = 'Country name in English, lower case (e.g. france), to favour its results. Empty: none.';
$string['setting_excludedomains']       = 'Domains excluded from results';
$string['setting_excludedomains_desc']  = 'One domain per line (150 at most), never shown: ready-made answer sites, for instance. Sites blacklisted in OPNsense are still shown, with a badge.';
$string['setting_retentiondays']        = 'Log retention (days)';
$string['setting_retentiondays_desc']   = 'Older searches and clicks are deleted every night. 0: kept without limit.';
$string['task_purge']                   = 'MoodleSearch: purge old searches';

// Privacy.
$string['privacy:metadata:search']          = 'Searches made in MoodleSearch, viewable by the student\'s teachers.';
$string['privacy:metadata:search:userid']   = 'The person who searched.';
$string['privacy:metadata:search:query']    = 'The search text.';
$string['privacy:metadata:search:results']  = 'The results shown (address, title).';
$string['privacy:metadata:click']           = 'Results opened from MoodleSearch.';
$string['privacy:metadata:click:userid']    = 'The person who opened the result.';
$string['privacy:metadata:click:url']       = 'The opened address.';
$string['privacy:metadata:click:opnstatus'] = 'The firewall\'s response (opened, pending, blacklisted…).';
$string['privacy:metadata:timecreated']     = 'The date.';
$string['privacy:metadata:tavily']          = 'The search is sent to the Tavily engine, under the person\'s own account (their personal key).';
$string['privacy:metadata:tavily:query']    = 'The search text.';
$string['privacy:metadata:opnsense']        = 'The domain of an opened result is sent to the school firewall (OPNsense block) to open it for the class.';
$string['privacy:metadata:opnsense:domain'] = 'The site\'s domain.';
