<?php
namespace local_aiquizgen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Formulaire de génération d'un test IA.
 *
 * Étape 2 (minimal) : source = un PDF, type généré = QCM core uniquement.
 * Les autres sources (leçon Moodle) et types (réponse courte IA, composition
 * IA, QCM à pool aléatoire) seront ajoutés aux étapes suivantes, en
 * étendant ce même formulaire — d'où le découpage en sections dès maintenant.
 *
 * @package local_aiquizgen
 */
class generate_form extends \moodleform {

    public function definition() {
        $mform   = $this->_form;
        $custom  = $this->_customdata;

        // -----------------------------------------------------------
        //  SOURCE
        // -----------------------------------------------------------
        $mform->addElement('header', 'sourceheader',
            get_string('source_heading', 'local_aiquizgen'));
        $mform->setExpanded('sourceheader', true);

        $mform->addElement('filepicker', 'sourcefile',
            get_string('source_pdf', 'local_aiquizgen'),
            null,
            array(
                'accepted_types' => array('.pdf'),
                'maxfiles'       => 1,
            )
        );
        $mform->addHelpButton('sourcefile', 'source_pdf', 'local_aiquizgen');

        // -----------------------------------------------------------
        //  TYPES ET NOMBRES
        // -----------------------------------------------------------
        $mform->addElement('header', 'countsheader',
            get_string('counts_heading', 'local_aiquizgen'));
        $mform->setExpanded('countsheader', true);

        $mform->addElement('text', 'mcqcount',
            get_string('mcqcount', 'local_aiquizgen'),
            array('size' => 4));
        $mform->setType('mcqcount', PARAM_INT);
        $mform->setDefault('mcqcount', 10);
        $mform->addHelpButton('mcqcount', 'mcqcount', 'local_aiquizgen');
        $mform->addRule('mcqcount', null, 'required', null, 'client');

        // -----------------------------------------------------------
        //  DESTINATION
        // -----------------------------------------------------------
        $mform->addElement('header', 'destheader',
            get_string('dest_heading', 'local_aiquizgen'));
        $mform->setExpanded('destheader', true);

        $mform->addElement('text', 'quizname',
            get_string('quizname', 'local_aiquizgen'),
            array('size' => 60));
        $mform->setType('quizname', PARAM_TEXT);
        $mform->addHelpButton('quizname', 'quizname', 'local_aiquizgen');
        $mform->addRule('quizname', null, 'required', null, 'client');

        // -----------------------------------------------------------
        //  CHAMPS CACHÉS
        // -----------------------------------------------------------
        $mform->addElement('hidden', 'courseid', $custom['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true,
            get_string('generate_button', 'local_aiquizgen'));
    }

    /**
     * Validation serveur :
     *   - mcqcount entre 1 et 50,
     *   - un fichier PDF effectivement fourni (le filepicker addRule
     *     'required' ne vérifie pas toujours côté client).
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);

        $count = isset($data['mcqcount']) ? (int)$data['mcqcount'] : 0;
        if ($count < 1) {
            $errors['mcqcount'] = get_string('error_mcqcount_min', 'local_aiquizgen');
        } else if ($count > 50) {
            $errors['mcqcount'] = get_string('error_mcqcount_max', 'local_aiquizgen');
        }

        // Vérification serveur du PDF.
        if (!empty($data['sourcefile'])) {
            $fs        = get_file_storage();
            $usercxt   = \context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files(
                $usercxt->id, 'user', 'draft',
                (int)$data['sourcefile'], 'id', false);
            if (empty($draftfiles)) {
                $errors['sourcefile'] = get_string('error_source_required', 'local_aiquizgen');
            }
        } else {
            $errors['sourcefile'] = get_string('error_source_required', 'local_aiquizgen');
        }

        return $errors;
    }
}
