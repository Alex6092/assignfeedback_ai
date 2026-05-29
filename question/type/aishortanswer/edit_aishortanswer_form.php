<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Formulaire d'édition d'une question "Réponse courte (correction IA)".
 *
 * Hérite du formulaire standard ; ajoute la taille de la zone de saisie
 * (paramètre par question) et la configuration IA (prompt, corrigé attendu,
 * surcharges URL/modèle/clé).
 */
class qtype_aishortanswer_edit_form extends question_edit_form {

    public function qtype() {
        return 'aishortanswer';
    }

    protected function definition_inner($mform) {
        // --- Options de réponse ---
        $mform->addElement('header', 'responseoptions',
            get_string('responseoptions', 'qtype_aishortanswer'));

        $mform->addElement('select', 'responsefieldlines',
            get_string('responsefieldlines', 'qtype_aishortanswer'),
            array_combine(range(1, 8), range(1, 8)));
        $mform->setDefault('responsefieldlines', 1);
        $mform->addHelpButton('responsefieldlines', 'responsefieldlines', 'qtype_aishortanswer');

        // --- Configuration IA ---
        $mform->addElement('header', 'aiconfig',
            get_string('aiconfig_heading', 'qtype_aishortanswer'));

        $mform->addElement('textarea', 'systemprompt',
            get_string('systemprompt', 'qtype_aishortanswer'),
            array('rows' => 5, 'cols' => 60));
        $mform->setType('systemprompt', PARAM_TEXT);
        $mform->addHelpButton('systemprompt', 'systemprompt', 'qtype_aishortanswer');

        $mform->addElement('textarea', 'expectedanswer',
            get_string('expectedanswer', 'qtype_aishortanswer'),
            array('rows' => 4, 'cols' => 60));
        $mform->setType('expectedanswer', PARAM_TEXT);
        $mform->addHelpButton('expectedanswer', 'expectedanswer', 'qtype_aishortanswer');

        // --- Surcharges URL / Modèle / Clé ---
        $mform->addElement('header', 'aioverrides',
            get_string('aioverrides_heading', 'qtype_aishortanswer'));

        $mform->addElement('advcheckbox', 'apiurl_override',
            get_string('apiurl_override', 'qtype_aishortanswer'));
        $mform->setDefault('apiurl_override', 0);
        $mform->addElement('text', 'apiurl', get_string('apiurl', 'qtype_aishortanswer'),
            array('size' => 60));
        $mform->setType('apiurl', PARAM_URL);
        $mform->disabledIf('apiurl', 'apiurl_override', 'notchecked');

        $mform->addElement('advcheckbox', 'model_override',
            get_string('model_override', 'qtype_aishortanswer'));
        $mform->setDefault('model_override', 0);
        $mform->addElement('text', 'model', get_string('model', 'qtype_aishortanswer'),
            array('size' => 40));
        $mform->setType('model', PARAM_TEXT);
        $mform->disabledIf('model', 'model_override', 'notchecked');

        $mform->addElement('advcheckbox', 'apikey_override',
            get_string('apikey_override', 'qtype_aishortanswer'));
        $mform->setDefault('apikey_override', 0);
        $mform->addElement('passwordunmask', 'apikey',
            get_string('apikey', 'qtype_aishortanswer'), array('size' => 60));
        $mform->setType('apikey', PARAM_RAW);
        $mform->disabledIf('apikey', 'apikey_override', 'notchecked');
    }

    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);
        if (!empty($question->options)) {
            $question->responsefieldlines = $question->options->responsefieldlines;
            $question->systemprompt    = $question->options->systemprompt;
            $question->expectedanswer  = $question->options->expectedanswer;
            $question->apiurl          = $question->options->apiurl;
            $question->apiurl_override = $question->options->apiurl_override;
            $question->model           = $question->options->model;
            $question->model_override  = $question->options->model_override;
            $question->apikey          = $question->options->apikey; // déjà déchiffrée
            $question->apikey_override = $question->options->apikey_override;
        }
        return $question;
    }
}
