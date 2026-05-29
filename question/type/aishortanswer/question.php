<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Instance d'une question "Réponse courte (correction IA)" en cours de tentative.
 *
 * Comme qtype_aiessay, on étend question_with_responses (et non
 * _automatically_gradable) : la note est posée a posteriori par le job IA via
 * manual_grade(). Le behaviour est forcé à "manualgraded".
 */
class qtype_aishortanswer_question extends question_with_responses {

    /** @var int taille de la zone de saisie (1 = champ une ligne). */
    public $responsefieldlines;

    // Config IA (hydratée par initialise_question_instance, lue par la base).
    public $systemprompt;
    public $expectedanswer;
    public $apiurl;
    public $apiurl_override;
    public $model;
    public $model_override;
    public $apikey;
    public $apikey_override;

    public function make_behaviour(question_attempt $qa, $preferredbehaviour) {
        return question_engine::make_behaviour('manualgraded', $qa, $preferredbehaviour);
    }

    public function get_expected_data() {
        return array('answer' => PARAM_RAW);
    }

    public function summarise_response(array $response) {
        $text = isset($response['answer']) ? (string)$response['answer'] : '';
        return shorten_text(trim($text), 200);
    }

    public function un_summarise_response(string $summary) {
        return array('answer' => $summary);
    }

    public function is_complete_response(array $response) {
        return isset($response['answer']) && trim((string)$response['answer']) !== '';
    }

    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    public function get_validation_error(array $response) {
        if ($this->is_complete_response($response)) {
            return '';
        }
        return get_string('pleaseenteranswer', 'qtype_aishortanswer');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank(
            $prevresponse, $newresponse, 'answer');
    }

    public function grade_response(array $response) {
        // Notation asynchrone : le job IA posera la note via manual_grade.
        return array(0, question_state::$needsgrading);
    }

    public function get_correct_response() {
        // Pas de bonne réponse mécanique ; le corrigé attendu sert au LLM.
        return null;
    }

    public function check_file_access($qa, $options, $component, $filearea, $args, $forcedownload) {
        if ($component === 'question' && in_array($filearea,
                array('hint', 'correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'))) {
            return $this->check_hint_file_access($qa, $options, $args);
        }
        return parent::check_file_access($qa, $options, $component, $filearea, $args, $forcedownload);
    }
}
