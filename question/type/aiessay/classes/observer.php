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
        global $DB;

        $attemptid = (int)$event->objectid;
        if ($attemptid <= 0) {
            return;
        }

        $quizattempt = $DB->get_record('quiz_attempts', array('id' => $attemptid));
        if (!$quizattempt) {
            return;
        }

        // On évite l'API \question_engine (load_questions_usage_by_id /
        // load_question_attempt) car ces noms de méthode varient selon les
        // versions de Moodle. Lister directement les question_attempts de
        // type aiessay rattachés au question_usage suffit : on n'a pas
        // besoin d'instancier la question pour décider d'enqueuer un job.
        $qarecords = $DB->get_records_sql("
            SELECT qa.id AS qaid, qa.slot, qa.questionid AS questionid
              FROM {question_attempts} qa
              JOIN {question} q ON q.id = qa.questionid
             WHERE qa.questionusageid = :usageid
               AND q.qtype = :qtype
          ORDER BY qa.slot
        ", array(
            'usageid' => (int)$quizattempt->uniqueid,
            'qtype'   => 'aiessay',
        ));

        if (empty($qarecords)) {
            return;
        }

        $userid = (int)$quizattempt->userid;

        // Cap éventuel sur le nombre de tentatives notées par l'IA.
        $maxgraded = (int)get_config('local_aifeedback', 'max_attempts_to_grade');
        if ($maxgraded <= 0) {
            $maxgraded = 3;
        }

        foreach ($qarecords as $qarec) {
            $qaid       = (int)$qarec->qaid;
            $questionid = (int)$qarec->questionid;

            // Idempotence : si déjà une ligne pour ce question_attempt, on ne refait pas.
            if ($DB->record_exists('qtype_aiessay_grading',
                    array('questionattemptid' => $qaid))) {
                continue;
            }

            // Cap : combien de tentatives notées par l'IA sur cette question pour ce user.
            $alreadygraded = self::count_user_question_gradings($userid, $questionid);
            if ($alreadygraded >= $maxgraded) {
                continue;
            }

            $now = time();
            $row = new \stdClass();
            $row->questionattemptid = $qaid;
            $row->questionid        = $questionid;
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
