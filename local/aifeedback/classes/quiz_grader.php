<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Base partagée pour les types de question corrigés par IA dans un quiz
 * (qtype_aiessay, qtype_aishortanswer, …).
 *
 * Contient TOUTE la mécanique commune et sensible aux versions de Moodle :
 *   - handler de la file partagée (build_request / complete / fail), la
 *     génération étant confiée au load balancer (job asynchrone) ;
 *   - découplage appel LLM / application de note : le résultat est persisté
 *     AVANT d'appliquer la note ; si l'application échoue, le nouvel essai
 *     réutilise ce résultat sans rappeler le modèle ;
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
abstract class quiz_grader implements \local_aifeedback\async_handler {

    /** Tentatives techniques avant abandon. */
    const MAX_ATTEMPTS = 3;

    /** Délai avant un nouvel essai après un échec (secondes). */
    const RETRY_DELAY = 120;

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
     * Met en file la correction de la ligne donnée. Le composant est résolu
     * par late static binding (la sous-classe définit component_name()).
     */
    public static function enqueue($rowid) {
        \local_aifeedback\jobs::enqueue(static::component_name(), (object)array('rowid' => (int)$rowid));
    }

    /**
     * Demande de correction : la réponse de l'élève et la configuration IA de
     * la question. Une question configurée sur une API externe (surcharge
     * d'URL) est corrigée directement par cette API, hors load balancer ; un
     * modèle imposé sans URL désigne un autre modèle du load balancer.
     */
    public function build_request(\stdClass $payload): ?\local_aifeedback\request {
        $row = $this->pending_row($payload);
        if ($row === null) {
            return null;
        }
        list($qarec, $quba, $qa, $question) = $this->load_attempt($row);

        // Résultat déjà obtenu (seule l'application de la note avait échoué) :
        // on l'applique sans rappeler le modèle.
        $cached = !empty($row->aifeedback) ? json_decode($row->aifeedback, true) : null;
        if (is_array($cached) && $row->mark !== null) {
            $this->apply_grade($row, $cached, (float)$row->mark, $qarec, $quba, $qa);
            return null;
        }

        $options = array(
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
        $model   = (!empty($question->model_override) && !empty($question->model)) ? (string)$question->model : '';
        $request = new \local_aifeedback\request($this->build_messages($qa, $question), $options,
            ($model !== '') ? $model : \local_aifeedback\models::feedback(), (int)$row->userid);
        if (!empty($question->apiurl_override) && !empty($question->apiurl)) {
            $request->set_direct((string)$question->apiurl,
                (!empty($question->apikey_override) && !empty($question->apikey))
                    ? (string)$question->apikey : '', // déjà déchiffrée à l'init
                $model);
        }
        return $request;
    }

    /**
     * LLM → note → application au quiz → carnet. Le résultat LLM est persisté
     * AVANT l'application de la note.
     */
    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        global $DB;

        $row = $this->pending_row($payload);
        if ($row === null) {
            return null; // ligne supprimée, ou corrigée entre-temps
        }
        list($qarec, $quba, $qa) = $this->load_attempt($row);

        // Cohérence niveau ↔ score (le niveau fait foi ; un score renvoyé
        // en points de barème au lieu d'un pourcentage est ramené dans la bande).
        $result = \local_aifeedback\scoring::reconcile($result);

        $score   = (int)$result['score'];
        $maxmark = (float)$qa->get_max_mark();
        $mark    = \local_aifeedback\math::round_up_quarter(($score / 100.0) * $maxmark);
        if ($mark > $maxmark) {
            $mark = $maxmark;
        }

        // === ÉTAPE A : persistance du résultat AVANT d'appliquer la note ====
        $row->aifeedback   = json_encode($result, JSON_UNESCAPED_UNICODE);
        $row->mark         = $mark;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);

        $this->apply_grade($row, $result, $mark, $qarec, $quba, $qa);
        return null;
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        global $DB;
        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        $this->record_failure($rowid, $error, $permanent);
        $row = $DB->get_record(self::TABLE, array('id' => $rowid), 'id, status');
        return ($row && $row->status === 'pending') ? self::RETRY_DELAY : null;
    }

    public function pending_payloads(int $before): array {
        global $DB;
        $ids = $DB->get_fieldset_select(self::TABLE, 'id',
            'component = ? AND status = ? AND timemodified < ?',
            array(static::component_name(), 'pending', $before));
        $out = array();
        foreach (array_slice($ids, 0, 50) as $id) {
            $out[] = (object)array('rowid' => (int)$id);
        }
        return $out;
    }

    // === Cœur du traitement ===============================================

    /**
     * Ligne de correction encore à traiter, ou null.
     */
    protected function pending_row(\stdClass $payload) {
        global $DB;
        $rowid = isset($payload->rowid) ? (int)$payload->rowid : 0;
        if ($rowid <= 0) {
            return null;
        }
        $row = $DB->get_record(self::TABLE, array('id' => $rowid));
        return ($row && $row->status === 'pending') ? $row : null;
    }

    /**
     * Charge la tentative de question d'une ligne de correction.
     *
     * @return array [question_attempts record, quba, question_attempt, question]
     */
    protected function load_attempt(\stdClass $row) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $qarec = $DB->get_record('question_attempts',
            array('id' => (int)$row->questionattemptid));
        if (!$qarec) {
            throw new \moodle_exception('questionattemptmissing', 'local_aifeedback');
        }
        $quba = \question_engine::load_questions_usage_by_activity((int)$qarec->questionusageid);
        $qa   = $quba->get_question_attempt((int)$qarec->slot);
        return array($qarec, $quba, $qa, $qa->get_question());
    }

    /**
     * ÉTAPE B (idempotente) : application de la note et du commentaire au
     * quiz, recalcul de la note du quiz, puis ÉTAPE C : succès complet.
     */
    protected function apply_grade(\stdClass $row, array $result, $mark, $qarec, $quba, $qa) {
        global $DB;

        $commenthtml = $this->render_feedback_comment($result);
        $quba->manual_grade($qa->get_slot(), $commenthtml, $mark, FORMAT_HTML);
        \question_engine::save_questions_usage_by_activity($quba);
        $this->recompute_quiz_grade($qarec);

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
        // Consigne d'accessibilité (tolérance orthographique) selon le réglage global.
        $system   .= \local_aifeedback\prompt::accessibility_suffix();
        // Garde-fous non modifiables par l'enseignant : échelle du score + intégrité.
        $system   .= \local_aifeedback\prompt::score_scale_suffix();
        $system   .= \local_aifeedback\prompt::integrity_suffix(false);

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
        $parts[] = \local_aifeedback\prompt::wrap_student_answer($answer);

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => implode("\n\n", $parts)),
        );
    }

    /**
     * Carte HTML complète stockée comme commentaire de notation manuelle :
     * elle s'affiche ainsi partout où Moodle rend le commentaire (review
     * étudiant, vue enseignant, rapport de notation manuelle, export…), sans
     * dépendre de specific_feedback() qui n'est pas appelé sur toutes les
     * pages quand la question est en notation manuelle.
     */
    protected function render_feedback_comment($result) {
        return \local_aifeedback\feedback_card::render((array)$result, static::component_name());
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

    /**
     * Compte un échec ; au-delà de MAX_ATTEMPTS (ou pour un échec définitif),
     * la ligne passe en « failed » avec un commentaire visible et un lien de
     * relance pour l'enseignant.
     */
    protected function record_failure($rowid, $errmsg, $permanent = false) {
        global $DB;
        $row = $DB->get_record(self::TABLE, array('id' => $rowid));
        if (!$row || $row->status !== 'pending') {
            return;
        }
        $row->attempts      = (int)$row->attempts + 1;
        $row->error_message = (string)$errmsg;
        $row->timemodified  = time();
        $row->status        = ($permanent || (int)$row->attempts >= self::MAX_ATTEMPTS) ? 'failed' : 'pending';
        $DB->update_record(self::TABLE, $row);

        // Définitivement en échec : pose un commentaire de notation manuelle
        // visible à la relecture, avec le motif et un lien de relance pour
        // l'enseignant. Best effort — si le quba ne se charge pas, on ignore.
        if ($row->status === 'failed') {
            $this->post_failure_comment($row);
        }
    }

    /**
     * Pose, en best effort, un commentaire HTML sur la question_attempt
     * échouée : message d'erreur + lien de relance vers /local/aifeedback/retry.php.
     * Le mark reste null (la question conserve son état needsgrading).
     */
    protected function post_failure_comment(\stdClass $row): void {
        global $CFG, $DB;
        try {
            require_once($CFG->dirroot . '/lib/questionlib.php');

            $qarec = $DB->get_record('question_attempts',
                array('id' => (int)$row->questionattemptid));
            if (!$qarec) {
                return;
            }
            $quba = \question_engine::load_questions_usage_by_activity(
                (int)$qarec->questionusageid);

            $retryurl = (new \moodle_url('/local/aifeedback/retry.php',
                array('id' => (int)$row->id)))->out(false);
            $errmsg   = isset($row->error_message) ? (string)$row->error_message : '';

            $html  = \html_writer::start_div('alert alert-warning local-aifeedback-failed');
            $html .= \html_writer::tag('p',
                \html_writer::tag('strong', get_string('feedback_failed', 'local_aifeedback')));
            if ($errmsg !== '') {
                $html .= \html_writer::tag('p',
                    \html_writer::tag('em', s($errmsg)),
                    array('class' => 'small text-muted'));
            }
            $html .= \html_writer::div(
                \html_writer::link($retryurl,
                    get_string('retry_button', 'local_aifeedback'),
                    array('class' => 'btn btn-secondary btn-sm')),
                'mt-2');
            $html .= \html_writer::end_div();

            $quba->manual_grade((int)$qarec->slot, $html, null, FORMAT_HTML);
            \question_engine::save_questions_usage_by_activity($quba);
        } catch (\Throwable $e) {
            // Ne jamais lever depuis le path d'échec.
        }
    }
}
