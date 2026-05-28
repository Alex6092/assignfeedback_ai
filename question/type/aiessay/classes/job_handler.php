<?php
namespace qtype_aiessay;

defined('MOODLE_INTERNAL') || die();

/**
 * Job handler pour qtype_aiessay : appelle le LLM, calcule la note (arrondi
 * au 0.25 supérieur), applique manual_grade sur le question_attempt et
 * recalcule la note globale de la tentative de quiz.
 */
class job_handler implements \local_aifeedback\job_handler {

    /** Tentatives techniques d'appel API avant abandon. */
    const MAX_ATTEMPTS = 3;

    public static function enqueue($rowid) {
        $payload = new \stdClass();
        $payload->rowid = (int)$rowid;
        \local_aifeedback\task\run_job::enqueue('qtype_aiessay', $payload);
    }

    public function execute(\stdClass $payload): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return;
        }
        $row = $DB->get_record('qtype_aiessay_grading', array('id' => $rowid));
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
            $current = $DB->get_record('qtype_aiessay_grading', array('id' => $rowid));
            if ($current && (int)$current->attempts < self::MAX_ATTEMPTS) {
                self::enqueue($rowid);
            }
            throw $e;
        }
    }

    public function find_drainable_payloads(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT id FROM {qtype_aiessay_grading}
              WHERE status = ?
           ORDER BY timecreated ASC',
            array('pending'),
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

    /**
     * Cœur du job : LLM → mark → manual_grade → recalcul quiz.
     */
    private function process_one(\stdClass $row) {
        global $DB;

        // 1) Récupère le question_attempt et son contexte quiz.
        $qa = \question_engine::load_question_attempt((int)$row->questionattemptid);
        if (!$qa) {
            throw new \moodle_exception('questionattemptmissing', 'qtype_aiessay');
        }
        $question = $qa->get_question();

        // 2) Construit les messages avec la config IA de cette question.
        $messages = $this->build_messages($qa, $question);

        // 3) Appel LLM via la lib partagée, en passant les overrides per-question.
        $options = array(
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'qtype_aiessay_response',
                    'strict' => true,
                    'schema' => self::response_schema(),
                ),
            ),
            'extra_body' => array('enable_thinking' => false),
        );
        if (!empty($question->apiurl_override) && !empty($question->apiurl)) {
            $options['apiurl'] = $question->apiurl;
        }
        if (!empty($question->model_override) && !empty($question->model)) {
            $options['model'] = $question->model;
        }
        if (!empty($question->apikey_override) && !empty($question->apikey)) {
            // L'apikey est déjà déchiffrée par get_question_options() / initialise_question_instance().
            $options['apikey'] = $question->apikey;
        }
        $result = \local_aifeedback\api::call($messages, $options);

        // 4) Calcule la note : 0–100 → maxmark, arrondi au 0.25 supérieur.
        $score   = isset($result['score']) ? max(0, min(100, (int)$result['score'])) : 0;
        $maxmark = (float)$qa->get_max_mark();
        $raw     = ($score / 100.0) * $maxmark;
        $mark    = \local_aifeedback\math::round_up_quarter($raw);
        if ($mark > $maxmark) {
            $mark = $maxmark; // sécurité, ne dépasse jamais le max
        }

        // 5) Commentaire HTML rendu depuis le feedback IA (carte structurée).
        $commenthtml = $this->render_feedback_comment($result);

        // 6) Applique manual_grade sur le question_attempt + sauvegarde.
        $quba = \question_engine::load_questions_usage_by_id($qa->get_usage_id());
        $quba->manual_grade($qa->get_slot(), $commenthtml, $mark, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);

        // 7) Recalcule la note totale de la tentative + meilleure note quiz.
        $this->recompute_quiz_grade($quba);

        // 8) Persiste le résultat.
        $row->status        = 'generated';
        $row->error_message = null;
        $row->aifeedback    = json_encode($result, JSON_UNESCAPED_UNICODE);
        $row->mark          = $mark;
        $row->timemodified  = time();
        $DB->update_record('qtype_aiessay_grading', $row);
    }

    /**
     * Construit les messages OpenAI à partir de la config IA de la question
     * et de la réponse de l'étudiant.
     */
    private function build_messages($qa, $question) {
        $defprompt = (string)get_config('local_aifeedback', 'defaultsystemprompt');
        $system    = !empty($question->systemprompt) ? (string)$question->systemprompt
                   : ($defprompt !== '' ? $defprompt : self::default_system_prompt());

        // L'énoncé = la questiontext de Moodle (HTML → texte).
        $exercise = trim(html_to_text((string)$question->questiontext, 0, false));

        // La réponse étudiant.
        $response = $qa->get_last_qt_data();
        $answer   = isset($response['answer']) ? (string)$response['answer'] : '';
        $answer   = trim(html_to_text($answer, 0, false));
        if ($answer === '') {
            $answer = get_string('emptyresponse', 'qtype_aiessay');
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
     * Rendu HTML du feedback IA pour le commentaire stocké sur la question.
     * Volontairement minimaliste — le renderer de la question affichera la
     * carte complète à la review.
     */
    private function render_feedback_comment($result) {
        $niveau = isset($result['niveau']) ? (string)$result['niveau'] : '';
        $score  = isset($result['score'])  ? (int)$result['score']     : 0;
        $fb     = isset($result['feedback']) ? (string)$result['feedback'] : '';

        $html  = '<div class="qtype-aiessay-comment">';
        $html .= '<p><strong>' . s($niveau) . '</strong> — ' . $score . '/100</p>';
        if ($fb !== '') {
            $html .= '<p>' . nl2br(s($fb)) . '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Met à jour quiz_attempts.sumgrades et la meilleure note de l'utilisateur.
     */
    private function recompute_quiz_grade($quba) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizattempt = $DB->get_record('quiz_attempts',
            array('uniqueid' => (int)$quba->get_id()));
        if (!$quizattempt) {
            return; // pas un quiz_attempt (autre consumer de l'engine de questions)
        }

        $total = 0.0;
        foreach ($quba->get_slots() as $slot) {
            $m = $quba->get_question_mark($slot);
            if ($m !== null) {
                $total += (float)$m;
            }
        }
        $DB->set_field('quiz_attempts', 'sumgrades', $total, array('id' => $quizattempt->id));

        $quiz = $DB->get_record('quiz', array('id' => $quizattempt->quiz));
        if ($quiz) {
            quiz_save_best_grade($quiz, $quizattempt->userid);
        }
    }

    private function record_failure($rowid, $errmsg) {
        global $DB;
        $row = $DB->get_record('qtype_aiessay_grading', array('id' => $rowid));
        if (!$row) {
            return;
        }
        $row->attempts      = (int)$row->attempts + 1;
        $row->error_message = (string)$errmsg;
        $row->timemodified  = time();
        $row->status        = ((int)$row->attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending';
        $DB->update_record('qtype_aiessay_grading', $row);
    }

    /**
     * Schéma JSON forcé sur la sortie du LLM.
     * Identique à assignfeedback_ai pour homogénéité du rendu.
     */
    public static function response_schema() {
        $levels = array(
            'Maîtrise insuffisante',
            'Maîtrise fragile',
            'Maîtrise satisfaisante',
            'Très bonne maîtrise',
        );
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'niveau' => array('type' => 'string', 'enum' => $levels),
                'score'  => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100),
                'points_forts'        => array('type' => 'array',
                    'items' => array('type' => 'string')),
                'points_a_ameliorer'  => array('type' => 'array',
                    'items' => array('type' => 'string')),
                'feedback'            => array('type' => 'string'),
                'competences_evaluees' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties' => array(
                            'competence'  => array('type' => 'string'),
                            'niveau'      => array('type' => 'string', 'enum' => $levels),
                            'commentaire' => array('type' => 'string'),
                        ),
                        'required' => array('competence', 'niveau', 'commentaire'),
                    ),
                ),
            ),
            'required' => array(
                'niveau', 'score', 'points_forts',
                'points_a_ameliorer', 'feedback', 'competences_evaluees',
            ),
        );
    }

    private static function default_system_prompt() {
        $p  = "Tu es un correcteur pédagogique spécialisé dans l'enseignement supérieur ";
        $p .= "technologique français (BTS Informatique / BTS CIEL).\n";
        $p .= "Tu évalues une réponse rédigée à un exercice court.\n\n";
        $p .= "Les niveaux possibles sont STRICTEMENT :\n";
        $p .= "- \"Maîtrise insuffisante\"\n- \"Maîtrise fragile\"\n";
        $p .= "- \"Maîtrise satisfaisante\"\n- \"Très bonne maîtrise\"\n\n";
        $p .= "Critères :\n";
        $p .= "- Très bonne maîtrise (80-100) : réponse complète, concepts corrects.\n";
        $p .= "- Maîtrise satisfaisante (50-79) : notions principales comprises, quelques imprécisions.\n";
        $p .= "- Maîtrise fragile (25-49) : compréhension partielle.\n";
        $p .= "- Maîtrise insuffisante (0-24) : hors sujet ou erreurs majeures.\n\n";
        $p .= "Reste factuel et pédagogique, ne pénalise pas les fautes mineures de français ";
        $p .= "si les concepts techniques sont corrects. La structure de ta réponse JSON est ";
        $p .= "imposée par le schéma fourni dans la requête.";
        return $p;
    }
}
