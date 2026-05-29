<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Type de question "Réponse courte (correction IA)" : l'étudiant saisit une
 * réponse brève (mot, définition, phrase) notée de façon asynchrone par un LLM
 * via la queue partagée de local_aifeedback.
 *
 * Variante allégée de qtype_aiessay : pas de pièces jointes ni de limites de
 * mots ; la taille de la zone de saisie est un simple paramètre de question.
 */
class qtype_aishortanswer extends question_type {

    public function is_manual_graded() {
        // En needs grading tant que le job IA n'a pas posé la note (a posteriori).
        return true;
    }

    public function extra_question_fields() {
        return array(
            'qtype_aishortanswer_options',
            'responsefieldlines',
            'systemprompt',
            'expectedanswer',
            'apiurl',
            'apiurl_override',
            'model',
            'model_override',
            'apikey',
            'apikey_override',
        );
    }

    /**
     * Chiffre l'apikey avant persistance par le mécanisme extra_question_fields.
     */
    public function save_question_options($formdata) {
        if (isset($formdata->apikey) && is_string($formdata->apikey) && $formdata->apikey !== '') {
            $formdata->apikey = \local_aifeedback\secret::encrypt($formdata->apikey);
        }
        return parent::save_question_options($formdata);
    }

    /**
     * Déchiffre l'apikey pour l'édition.
     */
    public function get_question_options($question) {
        $result = parent::get_question_options($question);
        if (!empty($question->options->apikey)) {
            $question->options->apikey = \local_aifeedback\secret::decrypt($question->options->apikey);
        }
        return $result;
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $opts = $questiondata->options;
        $question->responsefieldlines = (int)$opts->responsefieldlines;
        $question->systemprompt        = (string)$opts->systemprompt;
        $question->expectedanswer      = (string)$opts->expectedanswer;
        $question->apiurl              = (string)$opts->apiurl;
        $question->apiurl_override     = (int)$opts->apiurl_override;
        $question->model               = (string)$opts->model;
        $question->model_override      = (int)$opts->model_override;
        $question->apikey              = (string)$opts->apikey; // déjà déchiffrée
        $question->apikey_override     = (int)$opts->apikey_override;
    }
}
