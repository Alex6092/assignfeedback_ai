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

        // 1) Récupère le question_attempt via son question_usage_by_activity.
        //    \question_engine n'expose pas de load_question_attempt() ; on
        //    charge le quba et on en extrait le qa via le slot.
        //    Attention : la méthode s'appelle load_questions_usage_by_activity
        //    (le "by_activity" désigne le type d'objet retourné, pas la clé).
        $qarec = $DB->get_record('question_attempts',
            array('id' => (int)$row->questionattemptid));
        if (!$qarec) {
            throw new \moodle_exception('questionattemptmissing', 'qtype_aiessay');
        }
        $quba     = \question_engine::load_questions_usage_by_activity((int)$qarec->questionusageid);
        $qa       = $quba->get_question_attempt((int)$qarec->slot);
        $question = $qa->get_question();

        // === ÉTAPE A : obtention du résultat LLM (coûteuse) =================
        // Si un résultat est déjà mis en cache (parce qu'un run précédent a
        // obtenu la réponse du LLM mais a échoué à APPLIQUER la note), on le
        // réutilise au lieu de rappeler le modèle. C'est ce découplage qui
        // évite les appels LLM multiples sur erreur d'application de note.
        if (!empty($row->aifeedback)) {
            $result = json_decode($row->aifeedback, true);
            if (!is_array($result)) {
                // Cache corrompu : on repart pour un appel propre.
                $result = null;
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
                // Déjà déchiffrée par initialise_question_instance().
                $options['apikey'] = $question->apikey;
            }
            $result = \local_aifeedback\api::call($messages, $options);

            // Note : 0–100 → maxmark, arrondi au 0.25 supérieur.
            $score   = isset($result['score']) ? max(0, min(100, (int)$result['score'])) : 0;
            $maxmark = (float)$qa->get_max_mark();
            $mark    = \local_aifeedback\math::round_up_quarter(($score / 100.0) * $maxmark);
            if ($mark > $maxmark) {
                $mark = $maxmark; // sécurité, ne dépasse jamais le max
            }

            // On PERSISTE le résultat LLM immédiatement, AVANT toute tentative
            // d'application de la note. Ainsi, si l'étape B échoue, le retry
            // réutilisera ce cache et ne rappellera jamais le LLM.
            $row->aifeedback   = json_encode($result, JSON_UNESCAPED_UNICODE);
            $row->mark         = $mark;
            $row->timemodified = time();
            $DB->update_record('qtype_aiessay_grading', $row);
        } else {
            $mark = (float)$row->mark;
        }

        // === ÉTAPE B : application de la note au quiz (fragile, idempotente) =
        // manual_grade est idempotent (même commentaire + même note) : un
        // re-run après échec partiel ne crée pas d'incohérence.
        $commenthtml = $this->render_feedback_comment($result);
        $quba->manual_grade($qa->get_slot(), $commenthtml, $mark, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);

        // Recalcule sumgrades de la tentative + note finale (quiz_grades +
        // propagation au carnet de notes via quiz_update_grades).
        $this->recompute_quiz_grade($qarec);

        // === ÉTAPE C : succès complet =====================================
        $row->status        = 'generated';
        $row->error_message = null;
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
     * Recalcule la note du quiz après notation de la question par l'IA.
     *
     * En Moodle 4.3+ la mécanique a été refactorée dans \mod_quiz\grade_calculator
     * (les anciennes fonctions quiz_save_best_grade() / quiz_set_grade() ont été
     * supprimées en 5.x). On passe donc par le grade_calculator :
     *   - recompute_all_attempt_sumgrades() : recalcule quiz_attempts.sumgrades
     *     (qui était NULL tant que la question était en needsgrading) ;
     *   - recompute_final_grade($userid)    : recalcule quiz_grades pour
     *     l'utilisateur ET propage au carnet de notes (quiz_update_grades).
     */
    private function recompute_quiz_grade($qarec) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizattempt = $DB->get_record('quiz_attempts',
            array('uniqueid' => (int)$qarec->questionusageid));
        if (!$quizattempt) {
            return; // pas un quiz_attempt (autre consumer de l'engine de questions)
        }

        $quizobj = \mod_quiz\quiz_settings::create((int)$quizattempt->quiz);
        $calc    = $quizobj->get_grade_calculator();
        $calc->recompute_all_attempt_sumgrades();
        $calc->recompute_final_grade((int)$quizattempt->userid);
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
