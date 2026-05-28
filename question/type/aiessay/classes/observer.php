<?php
namespace qtype_aiessay;

defined('MOODLE_INTERNAL') || die();

/**
 * Détecte les questions aiessay dans une tentative de quiz soumise, crée une
 * ligne pending dans qtype_aiessay_grading et enqueue un job dans la queue
 * partagée local_aifeedback.
 *
 * Respecte le réglage admin local_aifeedback/max_attempts_to_grade pour
 * éviter d'abuser du LLM sur les quiz à tentatives illimitées.
 */
class observer {

    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attemptid = (int)$event->objectid;
        if ($attemptid <= 0) {
            return;
        }

        try {
            $attemptobj = \quiz_attempt::create($attemptid);
        } catch (\Throwable $e) {
            return;
        }

        $userid = (int)$attemptobj->get_userid();
        $usage  = $attemptobj->get_question_usage();

        // Cap éventuel sur le nombre de tentatives notées par l'IA.
        $maxgraded = (int)get_config('local_aifeedback', 'max_attempts_to_grade');
        if ($maxgraded <= 0) {
            $maxgraded = 3;
        }

        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $usage->get_question_attempt($slot);
            $question = $qa->get_question();
            if ($question->get_type_name() !== 'aiessay') {
                continue;
            }

            // Compte combien de tentatives de CET étudiant sur CETTE question
            // ont déjà été notées par l'IA (via les autres question_attempt
            // référencés dans qtype_aiessay_grading).
            $alreadygraded = self::count_user_question_gradings($userid, (int)$question->id);
            if ($alreadygraded >= $maxgraded) {
                continue; // au-delà du plafond → la copie restera en needsgrading manuel
            }

            // Idempotence : si déjà une ligne pour ce question_attempt, on ne refait pas.
            $existing = $DB->get_record('qtype_aiessay_grading',
                array('questionattemptid' => (int)$qa->get_database_id()));
            if ($existing) {
                continue;
            }

            $now = time();
            $row = new \stdClass();
            $row->questionattemptid = (int)$qa->get_database_id();
            $row->questionid        = (int)$question->id;
            $row->userid            = $userid;
            $row->status            = 'pending';
            $row->attempts          = 0;
            $row->aifeedback        = null;
            $row->error_message     = null;
            $row->mark              = null;
            $row->timecreated       = $now;
            $row->timemodified      = $now;
            $rowid = $DB->insert_record('qtype_aiessay_grading', $row);

            \qtype_aiessay\job_handler::enqueue($rowid);
        }
    }

    private static function count_user_question_gradings($userid, $questionid) {
        global $DB;
        return $DB->count_records_select('qtype_aiessay_grading',
            'userid = ? AND questionid = ? AND status IN (?, ?, ?)',
            array($userid, $questionid, 'pending', 'generated', 'failed'));
    }
}
