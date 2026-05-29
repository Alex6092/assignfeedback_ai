<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Observer PARTAGÉ pour tous les types de question corrigés par IA.
 *
 * À la soumission d'une tentative de quiz, détecte les questions dont le type
 * fournit une classe \qtype_xxx\job_handler étendant \local_aifeedback\quiz_grader,
 * crée une ligne pending dans {local_aifeedback_qgrading} et enqueue le job.
 *
 * Cette découverte automatique évite d'avoir un observer par type de question :
 * il suffit qu'un nouveau qtype IA fournisse son job_handler pour être pris en
 * charge ici.
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

        // Liste les question_attempts de la tentative avec leur type de question.
        $qarecords = $DB->get_records_sql("
            SELECT qa.id AS qaid, qa.slot, qa.questionid, q.qtype
              FROM {question_attempts} qa
              JOIN {question} q ON q.id = qa.questionid
             WHERE qa.questionusageid = :usageid
          ORDER BY qa.slot
        ", array('usageid' => (int)$quizattempt->uniqueid));
        if (empty($qarecords)) {
            return;
        }

        $userid = (int)$quizattempt->userid;

        // Plafond de corrections IA par (quiz, question, utilisateur).
        // 0 (ou non défini) = illimité : on s'appuie alors sur le délai entre
        // tentatives du quiz (réglage natif de Moodle) pour throttler le LLM.
        $maxgraded = (int)get_config('local_aifeedback', 'max_attempts_to_grade');

        foreach ($qarecords as $r) {
            // Un type est "noté par IA" s'il expose \qtype_xxx\job_handler
            // étendant la base partagée quiz_grader.
            $component    = 'qtype_' . $r->qtype;
            $handlerclass = '\\' . $component . '\\job_handler';
            if (!class_exists($handlerclass) ||
                    !is_subclass_of($handlerclass, '\\local_aifeedback\\quiz_grader')) {
                continue;
            }

            $qaid       = (int)$r->qaid;
            $questionid = (int)$r->questionid;

            // Idempotence (clé unique sur questionattemptid de toute façon).
            if ($DB->record_exists('local_aifeedback_qgrading',
                    array('questionattemptid' => $qaid))) {
                continue;
            }

            // Plafond optionnel, compté PAR QUIZ pour ce (question, user). On
            // scope par quiz (et non globalement par questionid) afin de ne pas
            // pénaliser la réutilisation d'une même question dans un autre quiz.
            if ($maxgraded > 0) {
                $alreadygraded = $DB->count_records_sql("
                    SELECT COUNT(g.id)
                      FROM {local_aifeedback_qgrading} g
                      JOIN {question_attempts} qa  ON qa.id = g.questionattemptid
                      JOIN {quiz_attempts}     qza ON qza.uniqueid = qa.questionusageid
                     WHERE g.component  = :component
                       AND g.questionid = :questionid
                       AND g.userid     = :userid
                       AND qza.quiz     = :quizid
                       AND g.status IN ('pending', 'generated', 'failed')",
                    array(
                        'component'  => $component,
                        'questionid' => $questionid,
                        'userid'     => $userid,
                        'quizid'     => (int)$quizattempt->quiz,
                    ));
                if ($alreadygraded >= $maxgraded) {
                    continue;
                }
            }

            $now = time();
            $row = new \stdClass();
            $row->component         = $component;
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
            $rowid = $DB->insert_record('local_aifeedback_qgrading', $row);

            $handlerclass::enqueue($rowid);
        }
    }
}
