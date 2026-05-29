<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Base partagée pour les types de question corrigés par IA dans un quiz
 * (qtype_aiessay, qtype_aishortanswer, …).
 *
 * Contient TOUTE la mécanique commune et sensible aux versions de Moodle :
 *   - file d'attente / retry (execute, find_drainable_payloads) ;
 *   - découplage appel LLM / application de note (process_one) ;
 *   - notation via \question_engine + \mod_quiz\grade_calculator (Moodle 5.x) ;
 *   - construction des messages OpenAI.
 *
 * Les sous-classes (\qtype_xxx\job_handler) ne fournissent que ce qui diffère
 * réellement entre types de question :
 *   - component_name()        : le frankenstyle du qtype (ex. 'qtype_aiessay') ;
 *   - response_schema()       : le schéma JSON forcé sur la sortie du LLM ;
 *   - default_system_prompt() : le prompt système par défaut du type.
 *
 * Toutes les lignes de travail vivent dans la table partagée
 * {local_aifeedback_qgrading}, discriminées par la colonne `component`.
 */
abstract class quiz_grader implements \local_aifeedback\job_handler {

    /** Tentatives techniques avant abandon. */
    const MAX_ATTEMPTS = 3;

    /** Table partagée de suivi des corrections. */
    const TABLE = 'local_aifeedback_qgrading';

    // === Hooks à implémenter par chaque type de question ===================

    /** Frankenstyle du composant, ex. 'qtype_aiessay'. */
    abstract protected static function component_name(): string;

    /** Schéma JSON strict imposé à la sortie du LLM. */
    abstract protected function response_schema(): array;

    /** Prompt système par défaut quand ni la question ni le site n'en fournit. */
    abstract protected function default_system_prompt(): string;

    // === API de la file d'attente =========================================

    /**
     * Enqueue un job pour la ligne de grading donnée. Le composant est résolu
     * par late static binding (la sous-classe définit component_name()).
     */
    public static function enqueue($rowid) {
        $payload = new \stdClass();
        $payload->rowid = (int)$rowid;
        \local_aifeedback\task\run_job::enqueue(static::component_name(), $payload);
    }

    public function execute(\stdClass $payload): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return;
        }
        $row = $DB->get_record(self::TABLE, array('id' => $rowid));
        if (!$row) {
            return;
        }
        if ($row->status !== 'pending') {
            return;
        }

        try {
            $this->process_one($row);
        } catch (\Throwable $e) {
            $this->record_failure($rowid, $e->getMessage());
            $current = $DB->get_record(self::TABLE, array('id' => $rowid));
            if ($current && (int)$current->attempts < self::MAX_ATTEMPTS) {
                static::enqueue($rowid);
            }
            throw $e;
        }
    }

    public function find_drainable_payloads(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id FROM {' . self::TABLE . '}
              WHERE component = ? AND status = ?
           ORDER BY timecreated ASC',
            array(static::component_name(), 'pending'),
            0, 1
        );
        if (empty($rows)) {
            return array();
        }
        $first = reset($rows);
        $payload = new \stdClass();
        $payload->rowid = (int)$first->id;
        return array($payload);
    }

    // === Cœur du traitement ===============================================

    /**
     * LLM → note → application au quiz → carnet. Le résultat LLM est persisté
     * AVANT l'application de la note : si celle-ci échoue, le retry réutilise
     * le cache et ne rappelle jamais le modèle.
     */
    protected function process_one(\stdClass $row) {
        global $DB;

        // 1) Charge le question_attempt via son question_usage_by_activity.
        $qarec = $DB->get_record('question_attempts',
            array('id' => (int)$row->questionattemptid));
        if (!$qarec) {
            throw new \moodle_exception('questionattemptmissing', 'local_aifeedback');
        }
        $quba     = \question_engine::load_questions_usage_by_activity((int)$qarec->questionusageid);
        $qa       = $quba->get_question_attempt((int)$qarec->slot);
        $question = $qa->get_question();

        // === ÉTAPE A : résultat LLM (coûteuse, mise en cache) ==============
        if (!empty($row->aifeedback)) {
            $result = json_decode($row->aifeedback, true);
            if (!is_array($result)) {
                $result = null; // cache corrompu → nouvel appel propre
            }
        } else {
            $result = null;
        }

        if ($result === null) {
            $messages = $this->build_messages($qa, $question);
            $options  = array(
                'response_format' => array(
                    'type'        => 'json_schema',
                    'json_schema' => array(
                        'name'   => static::component_name() . '_response',
                        'strict' => true,
                        'schema' => $this->response_schema(),
                    ),
                ),
                'extra_body' => array('enable_thinking' => false),
            );
            // Surcharges per-question (mêmes noms de propriétés sur tous les qtypes IA).
            if (!empty($question->apiurl_override) && !empty($question->apiurl)) {
                $options['apiurl'] = $question->apiurl;
            }
            if (!empty($question->model_override) && !empty($question->model)) {
                $options['model'] = $question->model;
            }
            if (!empty($question->apikey_override) && !empty($question->apikey)) {
                $options['apikey'] = $question->apikey; // déjà déchiffrée à l'init
            }
            $result = \local_aifeedback\api::call($messages, $options);

            $score   = isset($result['score']) ? max(0, min(100, (int)$result['score'])) : 0;
            $maxmark = (float)$qa->get_max_mark();
            $mark    = \local_aifeedback\math::round_up_quarter(($score / 100.0) * $maxmark);
            if ($mark > $maxmark) {
                $mark = $maxmark;
            }

            // Persiste le résultat AVANT d'appliquer la note.
            $row->aifeedback   = json_encode($result, JSON_UNESCAPED_UNICODE);
            $row->mark         = $mark;
            $row->timemodified = time();
            $DB->update_record(self::TABLE, $row);
        } else {
            $mark = (float)$row->mark;
        }

        // === ÉTAPE B : application de la note (idempotente) ================
        $commenthtml = $this->render_feedback_comment($result);
        $quba->manual_grade($qa->get_slot(), $commenthtml, $mark, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);
        $this->recompute_quiz_grade($qarec);

        // === ÉTAPE C : succès complet =====================================
        $row->status        = 'generated';
        $row->error_message = null;
        $row->timemodified  = time();
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Construit les messages OpenAI à partir de la config IA de la question et
     * de la réponse de l'étudiant. Générique : lit des propriétés présentes sur
     * tous les types de question IA (systemprompt, expectedanswer, competencies).
     */
    protected function build_messages($qa, $question) {
        $defprompt = (string)get_config('local_aifeedback', 'defaultsystemprompt');
        $system    = !empty($question->systemprompt) ? (string)$question->systemprompt
                   : ($defprompt !== '' ? $defprompt : $this->default_system_prompt());

        $exercise = trim(html_to_text((string)$question->questiontext, 0, false));

        $response = $qa->get_last_qt_data();
        $answer   = isset($response['answer']) ? (string)$response['answer'] : '';
        $answer   = trim(html_to_text($answer, 0, false));
        if ($answer === '') {
            $answer = get_string('emptyresponse', 'local_aifeedback');
        }

        $parts = array();
        $parts[] = "EXERCICE :\n" . $exercise;
        if (!empty($question->competencies)) {
            $parts[] = "COMPETENCES EVALUEES :\n" . (string)$question->competencies;
        }
        if (!empty($question->expectedanswer)) {
            $parts[] = "ATTENDUS / CORRIGE :\n" . (string)$question->expectedanswer;
        }
        $parts[] = "REPONSE ETUDIANT :\n" . $answer;

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => implode("\n\n", $parts)),
        );
    }

    /**
     * Commentaire HTML minimaliste stocké sur la question (la carte complète
     * est rendue par feedback_card à la relecture).
     */
    protected function render_feedback_comment($result) {
        $niveau = isset($result['niveau'])   ? (string)$result['niveau'] : '';
        $score  = isset($result['score'])    ? (int)$result['score']     : 0;
        $fb     = isset($result['feedback']) ? (string)$result['feedback'] : '';

        $html  = '<div class="local-aifeedback-comment">';
        $html .= '<p><strong>' . s($niveau) . '</strong> — ' . $score . '/100</p>';
        if ($fb !== '') {
            $html .= '<p>' . nl2br(s($fb)) . '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Recalcule la note du quiz après notation IA, via la mécanique moderne
     * \mod_quiz\grade_calculator (les fonctions quiz_save_best_grade() /
     * quiz_set_grade() ont été supprimées en Moodle 5.x).
     */
    protected function recompute_quiz_grade($qarec) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizattempt = $DB->get_record('quiz_attempts',
            array('uniqueid' => (int)$qarec->questionusageid));
        if (!$quizattempt) {
            return; // pas un quiz_attempt (autre consumer du question engine)
        }

        $quizobj = \mod_quiz\quiz_settings::create((int)$quizattempt->quiz);
        $calc    = $quizobj->get_grade_calculator();
        $calc->recompute_all_attempt_sumgrades();
        $calc->recompute_final_grade((int)$quizattempt->userid);
    }

    protected function record_failure($rowid, $errmsg) {
        global $DB;
        $row = $DB->get_record(self::TABLE, array('id' => $rowid));
        if (!$row) {
            return;
        }
        $row->attempts      = (int)$row->attempts + 1;
        $row->error_message = (string)$errmsg;
        $row->timemodified  = time();
        $row->status        = ((int)$row->attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending';
        $DB->update_record(self::TABLE, $row);
    }
}
