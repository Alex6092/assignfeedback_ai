<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']         = 'AI test generator';
$string['generate_menu']      = 'Generate an AI test';

// Capability
$string['aiquizgen:generate'] = 'Generate an AI test from a lesson or a PDF';

// Privacy
$string['privacy:metadata']   = 'The local_aiquizgen plugin does not store personal data directly. Sources provided by the teacher (Moodle lesson, PDF) are forwarded to the LLM through local_aifeedback.';

// -----------------------------------------------------------
//  Generation form
// -----------------------------------------------------------
$string['form_intro']       = 'Pick a content source (a PDF you upload, or a Moodle lesson already in this course) and the number of questions you want generated. The LLM will produce the questions, they will be saved in a new question bank category for the quiz, and added to a ready-to-use quiz.';

$string['source_heading']      = 'Source';
$string['source_type']         = 'Source type';
$string['source_type_help']    = 'Where the content the LLM uses to write the questions comes from.';
$string['source_type_pdf']     = 'PDF document (uploaded)';
$string['source_type_lesson']  = 'Moodle lesson from the course';

$string['source_pdf']       = 'PDF document';
$string['source_pdf_help']  = 'PDF the LLM will mine for question material (course chapter, handout, lecture notes, etc.). A single file.';

$string['source_lesson']        = 'Lesson';
$string['source_lesson_help']   = 'Lesson from this course whose contents (page titles + texts, and significant illustrations if vision is enabled in global settings) will be forwarded to the LLM.';
$string['source_lesson_choose'] = 'Choose a lesson';
$string['source_lesson_none']   = 'No lesson is available in this course.';

$string['counts_heading']        = 'Types and counts';
$string['mcqcount']              = 'Standard Moodle multiple choice';
$string['mcqcount_help']         = 'Number of multiple-choice questions (standard Moodle qtype) to generate from the source. Between 0 and 50. Set 0 to only generate AI short-answer questions.';
$string['shortanswercount']      = 'AI-graded short answers';
$string['shortanswercount_help'] = 'Number of short-answer questions (1–3 sentences) auto-graded by the LLM (qtype_aishortanswer). Between 0 and 50. Use sparingly: each student submission will trigger an LLM call to grade it.';
$string['essaycount']            = 'AI-graded essays';
$string['essaycount_help']       = 'Number of essay prompts (1–2 pages written by the student) auto-graded by the LLM (qtype_aiessay). Between 0 and 10. Use VERY sparingly: grading an essay takes several seconds per submission, and long open-ended questions are harder to grade consistently by an LLM.';

$string['answersselectcount']        = 'MCQ with random-answer pool';
$string['answersselectcount_help']   = 'Number of pool-based MCQ (third-party qtype_answersselect plugin by Joseph Rézeau). Each question holds a large pool of correct answers and a large pool of distractors; every attempt draws X correct + Y incorrect at random. Between 0 and 50. Combined with the per-attempt random draw of questions, gives strong per-student variation.';
$string['answersselect_plugin_missing'] = 'The third-party qtype_answersselect plugin is not installed on this site. To enable random-pool MCQ generation, install it from https://moodle.org/plugins/qtype_answersselect.';

$string['coderunnercount']             = 'CodeRunner programming exercises';
$string['coderunnercount_help']        = 'Number of programming exercises (third-party qtype_coderunner plugin by Richard Lobb). Each exercise contains a problem statement, a reference solution and 3 to 5 automated tests. Student code is executed in a sandbox (Jobe) at submission time. Between 0 and 20 (token-hungry generation). <strong>Important:</strong> the LLM-generated solution may contain errors; click "Check" in each question editor to verify the tests pass against the reference solution before publishing.';
$string['coderunnerlanguage']          = 'Target language';
$string['coderunnerlanguage_help']     = 'Programming language used by ALL CodeRunner exercises in this generation. The "function / method" variants ask the student to write only a function (CodeRunner generates the driver/main), which is more pedagogical for foundation-year students. The "full program" variants ask the student to write a complete program that reads from standard input and writes to standard output (each test feeds a stdin and checks the produced stdout).';
$string['coderunnerlang_python3']      = 'Python 3 (function)';
$string['coderunnerlang_nodejs']       = 'JavaScript / Node.js (function)';
$string['coderunnerlang_c_function']   = 'C (function)';
$string['coderunnerlang_cpp_function'] = 'C++ (function)';
$string['coderunnerlang_java_method']  = 'Java (static method)';
$string['coderunnerlang_c_program']    = 'C (full program, stdin → stdout)';
$string['coderunnerlang_cpp_program']  = 'C++ (full program, stdin → stdout)';
$string['coderunner_plugin_missing']   = 'The third-party qtype_coderunner plugin is not installed on this site. To enable auto-graded programming exercise generation, install it from https://moodle.org/plugins/qtype_coderunner. Note: this plugin also requires a sandbox server (Jobe) to execute student code.';

$string['variation_heading']         = 'Per-student variation';
$string['variation_mode']            = 'Variation mode';
$string['variation_mode_help']       = 'In <strong>fixed</strong> mode, every student sees the same questions in the same order. In <strong>random</strong> mode, each attempt draws a random subset of the generated questions (per-student variation). The random mode remains compatible with random-pool multiple-choice plugins on top.';
$string['variation_mode_fixed']      = 'Fixed quiz (same questions for everyone)';
$string['variation_mode_random']     = 'Random draw per attempt';
$string['randomperattempt']          = 'Questions drawn per attempt';
$string['randomperattempt_help']     = 'Number of questions drawn at random from the generated category for each attempt. Must be less than or equal to the total number of generated questions (a larger pool gives more variation).';
$string['error_randomperattempt_min'] = 'At least 1 question per attempt.';
$string['error_randomperattempt_max'] = 'The draw cannot exceed the total number of generated questions.';

$string['dest_heading']     = 'Destination';
$string['quizname']         = 'Title of the quiz to create';
$string['quizname_help']    = 'The quiz will be created in the general section of the course with this title. You can move and adjust it afterwards.';

$string['generate_button']  = 'Generate the test';

// Validation errors
$string['error_total_min']                 = 'Request at least one question (MCQ or short answer).';
$string['error_mcqcount_negative']         = 'The MCQ count cannot be negative.';
$string['error_mcqcount_min']              = 'At least 1 MCQ if you ask for any.';
$string['error_mcqcount_max']              = 'Maximum 50 MCQ per generation.';
$string['error_shortanswercount_negative'] = 'The short-answer count cannot be negative.';
$string['error_shortanswercount_max']      = 'Maximum 50 short answers per generation.';
$string['error_essaycount_negative']       = 'The essay count cannot be negative.';
$string['error_essaycount_max']            = 'Maximum 10 essays per generation (expensive to grade).';
$string['error_answersselectcount_negative'] = 'The random-pool MCQ count cannot be negative.';
$string['error_answersselectcount_max']      = 'Maximum 50 random-pool MCQ per generation.';
$string['error_coderunnercount_negative']    = 'The CodeRunner count cannot be negative.';
$string['error_coderunnercount_max']         = 'Maximum 20 CodeRunner exercises per generation (token-hungry).';
$string['error_source_required']        = 'You must provide a source PDF file.';
$string['error_source_lesson_required'] = 'You must pick a lesson.';
$string['error_source_lesson_invalid']  = 'The selected lesson does not belong to this course.';

// -----------------------------------------------------------
//  Status page
// -----------------------------------------------------------
$string['status_pagetitle']      = 'Generation status';

$string['status_pending']        = 'Pending';
$string['status_running']        = 'Running';
$string['status_done']           = 'Done';
$string['status_failed']         = 'Failed';

$string['status_pending_help']   = 'The job is queued. Generation will start at the next cron run.';
$string['status_running_help']   = 'The LLM is processing your request. This page refreshes automatically every 5 seconds.';
$string['status_done_help']      = 'The test was generated successfully. You can now open the quiz or the created category and adjust.';
$string['status_failed_help']    = 'Generation failed.';
$string['status_lasterror']      = 'Last error:';

$string['status_label_status']   = 'Status';
$string['status_label_created']  = 'Created at';
$string['status_label_attempts'] = 'Attempts';

$string['open_quiz']             = 'Open the quiz';
$string['open_category']         = 'Open the question category';
$string['back_to_form']          = '← Back to the generation form';

// -----------------------------------------------------------
//  Pipeline errors (handler)
// -----------------------------------------------------------
$string['source_missing']        = 'The source PDF associated with this job cannot be found in Moodle storage.';
$string['source_empty']          = 'No text could be extracted from the source PDF. The document is likely scanned/image-only, without a text layer.';
$string['source_lesson_missing'] = 'The source lesson cannot be found (was it deleted from the course?).';
$string['source_lesson_empty']   = 'The lesson contains no usable text.';
$string['llm_no_questions']      = 'The LLM did not return any usable question.';
$string['quiz_creation_failed']  = 'Quiz module creation failed.';
$string['untitled_question']     = 'Untitled question';

// -----------------------------------------------------------
//  Created metadata
// -----------------------------------------------------------
$string['category_info']         = 'Category automatically generated by the local_aiquizgen plugin.';
$string['quiz_intro_generated']  = 'Quiz automatically generated by AI. Review and adjust before publishing.';

// -----------------------------------------------------------
//  Log (display on the status page)
// -----------------------------------------------------------
$string['status_label_log']      = 'Generation log';
