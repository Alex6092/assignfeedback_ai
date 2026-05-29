<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Définition d'une instance de question "AI Essay" en cours de tentative.
 *
 * Étendre question_with_responses (et non _automatically_*) parce qu'on ne
 * sait pas noter de façon synchrone : la note est posée plus tard par le
 * job en arrière-plan via manual_grade().
 */
class qtype_aiessay_question extends question_with_responses {

    /** @var string editor|editorfilepicker|plain|monospaced|noinline */
    public $responseformat;
    /** @var int 0|1 */
    public $responserequired;
    /** @var int */
    public $responsefieldlines;
    /** @var int|null */
    public $minwordlimit;
    /** @var int|null */
    public $maxwordlimit;
    /** @var int 0|1|2|3|... */
    public $attachments;
    /** @var int */
    public $attachmentsrequired;
    /** @var string */
    public $filetypeslist;

    // AI fields (copiés depuis l'option, accessibles côté handler).
    public $systemprompt;
    public $expectedanswer;
    public $competencies;
    public $apiurl;
    public $apiurl_override;
    public $model;
    public $model_override;
    public $apikey;
    public $apikey_override;
    public $vision_enabled;
    public $vision_enabled_override;

    /**
     * On force le behaviour "manualgraded" quel que soit le réglage du quiz :
     * la correction est asynchrone (LLM via la queue partagée), donc du point
     * de vue du question_engine c'est de la notation manuelle posée a posteriori.
     * Même pattern que qtype_essay.
     */
    public function make_behaviour(question_attempt $qa, $preferredbehaviour) {
        return question_engine::make_behaviour('manualgraded', $qa, $preferredbehaviour);
    }

    public function get_expected_data() {
        $data = array();
        if ($this->responseformat === 'editor' || $this->responseformat === 'editorfilepicker') {
            $data['answer']       = PARAM_RAW;
            $data['answerformat'] = PARAM_ALPHANUMEXT;
        } else {
            $data['answer'] = PARAM_RAW;
        }
        if ($this->attachments != 0) {
            $data['attachments'] = question_attempt::PARAM_FILES;
        }
        return $data;
    }

    public function summarise_response(array $response) {
        $text = isset($response['answer']) ? (string)$response['answer'] : '';
        return shorten_text(html_to_text($text, 0, false), 200);
    }

    public function un_summarise_response(string $summary) {
        // Best effort : on reconstitue un response array minimal.
        return array('answer' => $summary);
    }

    public function is_complete_response(array $response) {
        // Pas de texte ET pas de pièces jointes attendues → incomplet.
        $hastext = !empty($response['answer']) && trim((string)$response['answer']) !== '';
        $hasfiles = false;
        if (isset($response['attachments']) && $response['attachments']) {
            $files = $response['attachments']->get_files();
            $hasfiles = !empty($files);
        }
        $minfiles = (int)$this->attachmentsrequired;
        $needstext = (int)$this->responserequired === 1;

        if ($needstext && !$hastext) {
            return false;
        }
        if ($minfiles > 0) {
            $count = isset($files) ? count($files) : 0;
            if ($count < $minfiles) {
                return false;
            }
        }
        return $hastext || $hasfiles;
    }

    public function is_gradable_response(array $response) {
        // On accepte de noter du moment qu'il y a quelque chose (texte ou fichier).
        return $this->is_complete_response($response);
    }

    public function get_validation_error(array $response) {
        if ($this->is_complete_response($response)) {
            return '';
        }
        return get_string('pleaseinputatleastsomething', 'qtype_aiessay');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        if (!question_utils::arrays_same_at_key_missing_is_blank(
                $prevresponse, $newresponse, 'answer')) {
            return false;
        }
        if (!question_utils::arrays_same_at_key_missing_is_blank(
                $prevresponse, $newresponse, 'answerformat')) {
            return false;
        }
        if (array_key_exists('attachments', $prevresponse) ||
                array_key_exists('attachments', $newresponse)) {
            $previous = isset($prevresponse['attachments']) ? $prevresponse['attachments'] : null;
            $newone   = isset($newresponse['attachments'])  ? $newresponse['attachments']  : null;
            if ((string)$previous !== (string)$newone) {
                return false;
            }
        }
        return true;
    }

    public function grade_response(array $response) {
        // On ne sait pas noter de façon synchrone — c'est le job IA qui posera
        // la note plus tard via manual_grade.
        return array(0, question_state::$needsgrading);
    }

    public function get_correct_response() {
        // Pas de "bonne réponse" mécanique. Le corrigé est dans expectedanswer
        // côté config et n'est pas montré directement à l'étudiant.
        return null;
    }

    public function check_file_access($qa, $options, $component, $filearea, $args, $forcedownload) {
        if ($component === 'question' && $filearea === 'response_attachments') {
            return (int)$this->attachments !== 0;
        } else if ($component === 'question' && $filearea === 'response_answer') {
            return $this->responseformat === 'editorfilepicker';
        } else if ($component === 'question' && in_array($filearea, array('hint', 'correctfeedback',
                'partiallycorrectfeedback', 'incorrectfeedback'))) {
            return $this->check_hint_file_access($qa, $options, $args);
        }
        return parent::check_file_access($qa, $options, $component, $filearea, $args, $forcedownload);
    }
}
