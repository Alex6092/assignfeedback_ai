<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI client missions';

// Capabilities.
$string['aimissions:generate']  = 'Generate AI client missions';
$string['aimissions:review']    = 'Review and publish generated missions';
$string['aimissions:askclient'] = 'Ask the AI client a question';

// Privacy.
$string['privacy:metadata'] = 'The local_aimissions plugin forwards the project dossier (a fictional company and the history of generated missions) and student questions to the LLM through local_aifeedback. Student questions to the AI client are stored to display the conversation thread.';
$string['privacy:metadata:ticket'] = 'Questions a student asks the AI client, and the AI answers.';
$string['privacy:metadata:ticket:userid'] = 'The student who asked the question.';
$string['privacy:metadata:ticket:question'] = 'The question asked to the AI client.';
$string['privacy:metadata:ticket:answer'] = 'The AI client answer.';
$string['privacy:metadata:llm'] = 'Data sent to the LLM to generate missions and answer client questions.';
$string['privacy:metadata:llm:dossier'] = 'The project dossier (fictional company, sprint history, technologies).';
$string['privacy:metadata:llm:question'] = 'The student question forwarded to the LLM.';

// Settings.
$string['settings_heading']       = 'AI client missions';
$string['settings_intro']         = 'This plugin reuses the global LLM connection configured in the AI Feedback plugin (local_aifeedback). The settings below only tune mission generation.';
$string['setting_model']          = 'Generation model (optional)';
$string['setting_model_desc']     = 'Model used for mission generation. Leave empty to use the global model from local_aifeedback. A cheaper model is usually enough for generation.';
$string['setting_maxsprints']     = 'Maximum sprints per project';
$string['setting_maxsprints_desc'] = 'Upper bound on the number of sprints a single project can accumulate (safety cap).';

// Persona profiles.
$string['persona_neutre']       = 'Neutral / cooperative';
$string['persona_exigeant']     = 'Demanding';
$string['persona_imprecis']     = 'Vague / imprecise';
$string['persona_versatile']    = 'Changes their mind often';
$string['persona_lent']         = 'Slow to respond';
$string['persona_nontechnique'] = 'Non-technical';

// Event types.
$string['event_besoin'] = 'Change of requirement';
$string['event_bug']    = 'Critical bug reported';
$string['event_rgpd']   = 'New regulation (GDPR)';
$string['event_budget'] = 'Budget cut';

// Mission / job status.
$string['status_pending'] = 'Pending';
$string['status_running'] = 'Running';
$string['status_done']    = 'Done';
$string['status_failed']  = 'Failed';
$string['mission_draft']     = 'Draft (hidden)';
$string['mission_published'] = 'Published';
$string['mission_archived']  = 'Archived';

// EFE bridge.
$string['efe_unavailable'] = 'The EFE notes plugin (local_efenotes) is not installed; competency reporting is disabled for generated missions.';
$string['efe_attached']    = 'Competency {$a} attached to the assignment for EFE reporting.';

// Generation errors.
$string['error_maxsprints']      = 'The maximum number of sprints for this project has been reached.';
$string['error_llm_invalid']     = 'The LLM did not return a usable mission (missing title, request or rubric).';
$string['error_assign_creation'] = 'Assignment creation failed.';

// Generation form.
$string['form_intro']            = 'Pick the competency to work on, the level and the groups. The AI generates a client request (brief) for each group, posts it as a hidden assignment, and grades it automatically on submission. Review then publish from the "Generated missions" screen.';
$string['form_target']           = 'Learning target';
$string['form_module']           = 'Module / subject';
$string['form_module_help']      = 'The module or subject concerned (e.g. "Web development", "Networks", "Cybersecurity"). Used to steer the client request context.';
$string['form_competency']       = 'Assessed competency (EFE)';
$string['form_competency_help']  = 'The EFE referential competency to work on. Its label guides generation (without being named in the brief) and its code is attached to the assignment for automatic reporting to EFE on grading.';
$string['form_competency_choose'] = 'Choose a competency…';
$string['form_competencylabel']  = 'Competency to work on';
$string['form_competencylabel_help'] = 'Describe the targeted competency (e.g. "Design a relational database"). The LLM will craft a business need that exercises it without naming it. (EFE unavailable: no competency reporting.)';
$string['form_level']            = 'Level';
$string['form_complexity']       = 'Complexity';
$string['complexity_easy']       = 'Introductory';
$string['complexity_medium']     = 'Intermediate';
$string['complexity_hard']       = 'Advanced';
$string['form_constraints']      = 'Number of constraints';
$string['form_persona']          = 'Client profile';
$string['form_persona_help']     = 'The personality of the fictional client. It colours the tone of the requests (and later their answers to questions). The profile is fixed when a group\'s project is created.';
$string['form_groups_heading']   = 'Target groups';
$string['form_groups_help']      = 'A distinct project (fictional company) is generated per group: missions differ between groups (anti-cheating individualisation).';
$string['form_nogroups']         = 'No group in this course. Create groups first (Participants → Groups): each group gets its own client company.';
$string['form_submit']           = 'Generate missions';
$string['error_nogroup']         = 'Select at least one group.';
$string['error_nocompetency']    = 'Provide a competency (EFE referential or free-text label).';
$string['jobs_queued']           = '{$a} generation(s) queued. They will be processed at the next cron run.';

// Status page.
$string['status_title']          = 'Mission generation — status';
$string['status_newgeneration']  = '+ New generation';
$string['status_managemissions'] = 'Generated missions';
$string['status_nojobs']         = 'No generation yet.';
$string['status_col_created']    = 'Started';
$string['status_col_status']     = 'Status';
$string['status_col_result']     = 'Mission';
$string['status_col_log']        = 'Log';

// Manage / publish page.
$string['manage_title']          = 'Generated missions';
$string['manage_noprojects']     = 'No mission generated for this course.';
$string['manage_nogroup']        = 'No group';
$string['manage_col_sprint']     = 'Sprint';
$string['manage_col_title']      = 'Mission';
$string['manage_col_status']     = 'Status';
$string['manage_col_actions']    = 'Actions';
$string['manage_publish']        = 'Publish';
$string['manage_hide']           = 'Hide';
$string['manage_delete']         = 'Delete';
$string['manage_delete_confirm'] = 'Delete the mission "{$a}"? The associated assignment will be removed from the course, along with any student submissions and grades. The group project will roll back to the previous sprint (you can regenerate it). This action cannot be undone.';
$string['manage_deleted']        = 'Mission deleted.';
$string['manage_orphan']         = 'Assignment deleted (orphan)';

// Job deletion on the status page.
$string['status_col_actions']    = 'Actions';
$string['status_deletejob']      = 'Remove';

// Tickets — questions to the AI client.
$string['ticket_title']          = 'Questions to the client';
$string['ticket_navlabel']       = 'Contact the client';
$string['ticket_noproject']      = 'No project is associated with your group yet. The client will appear once a first mission has been published.';
$string['ticket_chooseproject']  = 'You belong to several projects. Choose the one to view:';
$string['ticket_empty']          = 'No question yet. Ask the client the first one!';
$string['ticket_pending']        = 'The client is thinking…';
$string['ticket_failed']         = 'The client could not answer right now. Please try again later.';
$string['ticket_yourquestion']   = 'Your question to the client';
$string['ticket_send']           = 'Send to the client';
$string['ticket_readonly']       = 'Read-only view (teacher).';
$string['manage_viewtickets']    = 'Client questions ({$a})';

// Privacy (additional fields).
$string['privacy:metadata:ticket:timecreated'] = 'The date of the question.';
$string['privacy:metadata:job']         = 'Mission generation jobs started by a teacher.';
$string['privacy:metadata:job:userid']  = 'The teacher who started the generation.';
$string['privacy:metadata:job:timecreated'] = 'The date the generation was started.';
$string['privacy:path:tickets']  = 'Questions to the AI client';
$string['privacy:path:jobs']     = 'Mission generations';

// Events (the client reaches out).
$string['error_event_noproject'] = 'The project targeted by the event could not be found.';
$string['event_title']           = 'Inject a client event';
$string['event_intro']           = 'The client reaches out with an unexpected situation. A communication consistent with the project is generated for each target, pending your publication.';
$string['event_noprojects']      = 'No project in this course. Generate at least one mission first.';
$string['event_type']            = 'Event type';
$string['event_target']          = 'Target';
$string['event_target_all']      = 'All groups / projects';
$string['event_hint']            = 'Detail (optional)';
$string['event_hint_ph']         = 'E.g. "the new constraint concerns minors\' data"';
$string['event_submit']          = 'Generate the event';
$string['status_eventjob']       = 'Event';
$string['manage_injectevent']    = '+ Inject an event';
$string['manage_events']         = 'Client events';
$string['event_published']       = 'Published';
$string['event_pending']         = 'Pending';
