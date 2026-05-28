<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Formulaire d'édition d'une question AI Essay.
 *
 * Hérite du formulaire standard de question Moodle ; on ajoute les champs
 * spécifiques à la composition (UI réponse + pièces jointes) et la config
 * IA (prompt, corrigé attendu, compétences, overrides URL/modèle/clé/vision).
 */
class qtype_aiessay_edit_form extends question_edit_form {

    public function qtype() {
        return 'aiessay';
    }

    protected function definition_inner($mform) {
        // --- Options de réponse (mêmes choix que qtype_essay) ---
        $mform->addElement('header', 'responseoptions',
            get_string('responseoptions', 'qtype_aiessay'));

        $mform->addElement('select', 'responseformat',
            get_string('responseformat', 'qtype_aiessay'),
            array(
                'editor'             => get_string('formateditor', 'qtype_aiessay'),
                'editorfilepicker'   => get_string('formateditorfilepicker', 'qtype_aiessay'),
                'plain'              => get_string('formatplain', 'qtype_aiessay'),
                'monospaced'         => get_string('formatmonospaced', 'qtype_aiessay'),
                'noinline'           => get_string('formatnoinline', 'qtype_aiessay'),
            )
        );
        $mform->setDefault('responseformat', 'editor');

        $mform->addElement('select', 'responserequired',
            get_string('responserequired', 'qtype_aiessay'),
            array(0 => get_string('responsenotrequired', 'qtype_aiessay'),
                  1 => get_string('responseisrequired', 'qtype_aiessay')));
        $mform->setDefault('responserequired', 1);
        $mform->hideIf('responserequired', 'responseformat', 'eq', 'noinline');

        $mform->addElement('select', 'responsefieldlines',
            get_string('responsefieldlines', 'qtype_aiessay'),
            array_combine(range(5, 40, 5), range(5, 40, 5)));
        $mform->setDefault('responsefieldlines', 15);
        $mform->hideIf('responsefieldlines', 'responseformat', 'eq', 'noinline');

        $mform->addElement('text', 'minwordlimit',
            get_string('minwordlimit', 'qtype_aiessay'), array('size' => 5));
        $mform->setType('minwordlimit', PARAM_INT);
        $mform->hideIf('minwordlimit', 'responseformat', 'eq', 'noinline');

        $mform->addElement('text', 'maxwordlimit',
            get_string('maxwordlimit', 'qtype_aiessay'), array('size' => 5));
        $mform->setType('maxwordlimit', PARAM_INT);
        $mform->hideIf('maxwordlimit', 'responseformat', 'eq', 'noinline');

        $mform->addElement('select', 'attachments',
            get_string('allowattachments', 'qtype_aiessay'),
            array(0 => get_string('no'), 1 => '1', 2 => '2', 3 => '3',
                  -1 => get_string('unlimited', 'qtype_aiessay')));
        $mform->setDefault('attachments', 0);

        $mform->addElement('select', 'attachmentsrequired',
            get_string('attachmentsrequired', 'qtype_aiessay'),
            array(0 => get_string('attachmentsoptional', 'qtype_aiessay'),
                  1 => '1', 2 => '2', 3 => '3'));
        $mform->setDefault('attachmentsrequired', 0);
        $mform->hideIf('attachmentsrequired', 'attachments', 'eq', 0);

        // --- Configuration IA ---
        $mform->addElement('header', 'aiconfig',
            get_string('aiconfig_heading', 'qtype_aiessay'));

        $mform->addElement('textarea', 'systemprompt',
            get_string('systemprompt', 'qtype_aiessay'),
            array('rows' => 6, 'cols' => 60));
        $mform->setType('systemprompt', PARAM_TEXT);
        $mform->addHelpButton('systemprompt', 'systemprompt', 'qtype_aiessay');

        $mform->addElement('textarea', 'expectedanswer',
            get_string('expectedanswer', 'qtype_aiessay'),
            array('rows' => 6, 'cols' => 60));
        $mform->setType('expectedanswer', PARAM_TEXT);
        $mform->addHelpButton('expectedanswer', 'expectedanswer', 'qtype_aiessay');

        $mform->addElement('textarea', 'competencies',
            get_string('competencies', 'qtype_aiessay'),
            array('rows' => 3, 'cols' => 60));
        $mform->setType('competencies', PARAM_TEXT);
        $mform->addHelpButton('competencies', 'competencies', 'qtype_aiessay');

        // --- Overrides URL / Modèle / Clé / Vision ---
        $mform->addElement('header', 'aioverrides',
            get_string('aioverrides_heading', 'qtype_aiessay'));

        $mform->addElement('advcheckbox', 'apiurl_override',
            get_string('apiurl_override', 'qtype_aiessay'));
        $mform->setDefault('apiurl_override', 0);
        $mform->addElement('text', 'apiurl', get_string('apiurl', 'qtype_aiessay'),
            array('size' => 60));
        $mform->setType('apiurl', PARAM_URL);
        $mform->disabledIf('apiurl', 'apiurl_override', 'notchecked');

        $mform->addElement('advcheckbox', 'model_override',
            get_string('model_override', 'qtype_aiessay'));
        $mform->setDefault('model_override', 0);
        $mform->addElement('text', 'model', get_string('model', 'qtype_aiessay'),
            array('size' => 40));
        $mform->setType('model', PARAM_TEXT);
        $mform->disabledIf('model', 'model_override', 'notchecked');

        $mform->addElement('advcheckbox', 'apikey_override',
            get_string('apikey_override', 'qtype_aiessay'));
        $mform->setDefault('apikey_override', 0);
        $mform->addElement('passwordunmask', 'apikey',
            get_string('apikey', 'qtype_aiessay'), array('size' => 60));
        $mform->setType('apikey', PARAM_RAW);
        $mform->disabledIf('apikey', 'apikey_override', 'notchecked');

        $mform->addElement('advcheckbox', 'vision_enabled_override',
            get_string('vision_enabled_override', 'qtype_aiessay'));
        $mform->setDefault('vision_enabled_override', 0);
        $mform->addElement('advcheckbox', 'vision_enabled',
            get_string('vision_enabled', 'qtype_aiessay'));
        $mform->setDefault('vision_enabled', 0);
        $mform->disabledIf('vision_enabled', 'vision_enabled_override', 'notchecked');
    }

    /**
     * On utilise PARAM_RAW pour les champs cleartext stockés en TEXT.
     */
    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);
        if (!empty($question->options)) {
            $question->responseformat       = $question->options->responseformat;
            $question->responserequired     = $question->options->responserequired;
            $question->responsefieldlines   = $question->options->responsefieldlines;
            $question->minwordlimit         = $question->options->minwordlimit;
            $question->maxwordlimit         = $question->options->maxwordlimit;
            $question->attachments          = $question->options->attachments;
            $question->attachmentsrequired  = $question->options->attachmentsrequired;
            $question->filetypeslist        = $question->options->filetypeslist;
            $question->systemprompt              = $question->options->systemprompt;
            $question->expectedanswer            = $question->options->expectedanswer;
            $question->competencies              = $question->options->competencies;
            $question->apiurl                    = $question->options->apiurl;
            $question->apiurl_override           = $question->options->apiurl_override;
            $question->model                     = $question->options->model;
            $question->model_override            = $question->options->model_override;
            $question->apikey                    = $question->options->apikey; // déjà déchiffrée par get_question_options
            $question->apikey_override           = $question->options->apikey_override;
            $question->vision_enabled            = $question->options->vision_enabled;
            $question->vision_enabled_override   = $question->options->vision_enabled_override;
        }
        return $question;
    }

    public function validation($fromform, $files) {
        $errors = parent::validation($fromform, $files);
        if (!empty($fromform['minwordlimit']) && !empty($fromform['maxwordlimit'])
                && (int)$fromform['minwordlimit'] > (int)$fromform['maxwordlimit']) {
            $errors['maxwordlimit'] = get_string('maxlessthanmin', 'qtype_aiessay');
        }
        return $errors;
    }
}
