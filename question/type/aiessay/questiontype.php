<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Définition du type de question "AI Essay" : composition rédigée par
 * l'étudiant, notée de façon asynchrone par un LLM via la queue partagée
 * de local_aifeedback.
 *
 * Inspiré de qtype_essay côté UI (response area + pièces jointes), mais la
 * note est posée par un job en arrière-plan.
 */
class qtype_aiessay extends question_type {

    public function is_manual_graded() {
        // Du point de vue de mod_quiz on est en "needs grading" tant que le
        // job IA n'a pas tourné. C'est exactement ce que fait qtype_essay :
        // la note finit par être posée a posteriori.
        return true;
    }

    public function response_file_areas() {
        return array('attachments', 'answer');
    }

    public function extra_question_fields() {
        return array(
            'qtype_aiessay_options',
            'responseformat',
            'responserequired',
            'responsefieldlines',
            'minwordlimit',
            'maxwordlimit',
            'attachments',
            'attachmentsrequired',
            'filetypeslist',
            'systemprompt',
            'expectedanswer',
            'competencies',
            'apiurl',
            'apiurl_override',
            'model',
            'model_override',
            'apikey',
            'apikey_override',
            'vision_enabled',
            'vision_enabled_override',
        );
    }

    /**
     * Override : on chiffre l'apikey AVANT que le mécanisme standard de
     * extra_question_fields persiste les données.
     */
    public function save_question_options($formdata) {
        // Chiffre la clé API si elle est saisie en clair par le formulaire.
        if (isset($formdata->apikey) && is_string($formdata->apikey) && $formdata->apikey !== '') {
            $formdata->apikey = \local_aifeedback\secret::encrypt($formdata->apikey);
        }
        return parent::save_question_options($formdata);
    }

    /**
     * Charge la config IA pour le formulaire d'édition (déchiffre l'apikey).
     */
    public function get_question_options($question) {
        $result = parent::get_question_options($question);
        if (!empty($question->options->apikey)) {
            $question->options->apikey = \local_aifeedback\secret::decrypt($question->options->apikey);
        }
        return $result;
    }

    /**
     * Hydrate l'objet question_definition (question.php) à partir des
     * données chargées par get_question_options().
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $opts = $questiondata->options;
        $question->responseformat       = $opts->responseformat;
        $question->responserequired     = (int)$opts->responserequired;
        $question->responsefieldlines   = (int)$opts->responsefieldlines;
        $question->minwordlimit         = $opts->minwordlimit;
        $question->maxwordlimit         = $opts->maxwordlimit;
        $question->attachments          = (int)$opts->attachments;
        $question->attachmentsrequired  = (int)$opts->attachmentsrequired;
        $question->filetypeslist        = (string)$opts->filetypeslist;
        // Les champs IA sont accessibles depuis $question->aicfg(...) si besoin
        // dans le handler ; on les stocke aussi tels quels pour le rendu.
        $question->systemprompt              = (string)$opts->systemprompt;
        $question->expectedanswer            = (string)$opts->expectedanswer;
        $question->competencies              = (string)$opts->competencies;
        $question->apiurl                    = (string)$opts->apiurl;
        $question->apiurl_override           = (int)$opts->apiurl_override;
        $question->model                     = (string)$opts->model;
        $question->model_override            = (int)$opts->model_override;
        $question->apikey                    = (string)$opts->apikey; // déjà déchiffrée
        $question->apikey_override           = (int)$opts->apikey_override;
        $question->vision_enabled            = (int)$opts->vision_enabled;
        $question->vision_enabled_override   = (int)$opts->vision_enabled_override;
    }
}
