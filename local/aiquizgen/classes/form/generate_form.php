<?php
namespace local_aiquizgen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Formulaire de génération d'un test IA.
 *
 * Sources supportées :
 *   - PDF uploadé (depuis l'étape 2)
 *   - Leçon Moodle du cours (depuis l'étape 4)
 *
 * Types générés actuellement : QCM core. Les autres types (réponse courte
 * IA, composition IA, QCM à pool aléatoire) seront ajoutés aux étapes
 * suivantes.
 *
 * @package local_aiquizgen
 */
class generate_form extends \moodleform {

    public function definition() {
        $mform   = $this->_form;
        $custom  = $this->_customdata;
        $lessons = isset($custom['lessons']) ? (array)$custom['lessons'] : array();

        // -----------------------------------------------------------
        //  SOURCE
        // -----------------------------------------------------------
        $mform->addElement('header', 'sourceheader',
            get_string('source_heading', 'local_aiquizgen'));
        $mform->setExpanded('sourceheader', true);

        // Sélecteur du type de source.
        $sourcetypes = array(
            'pdf'    => get_string('source_type_pdf',    'local_aiquizgen'),
            'lesson' => get_string('source_type_lesson', 'local_aiquizgen'),
        );
        $mform->addElement('select', 'sourcetype',
            get_string('source_type', 'local_aiquizgen'),
            $sourcetypes);
        $mform->setDefault('sourcetype', 'pdf');
        $mform->addHelpButton('sourcetype', 'source_type', 'local_aiquizgen');

        // Champ PDF — visible uniquement si sourcetype = pdf.
        $mform->addElement('filepicker', 'sourcefile',
            get_string('source_pdf', 'local_aiquizgen'),
            null,
            array(
                'accepted_types' => array('.pdf'),
                'maxfiles'       => 1,
            )
        );
        $mform->addHelpButton('sourcefile', 'source_pdf', 'local_aiquizgen');
        $mform->hideIf('sourcefile', 'sourcetype', 'neq', 'pdf');

        // Dropdown leçon — visible uniquement si sourcetype = lesson.
        if (!empty($lessons)) {
            $lessonoptions = array('' => '-- ' . get_string('source_lesson_choose',
                'local_aiquizgen') . ' --');
            foreach ($lessons as $id => $name) {
                $lessonoptions[(int)$id] = format_string($name);
            }
            $mform->addElement('select', 'sourcelessonid',
                get_string('source_lesson', 'local_aiquizgen'), $lessonoptions);
            $mform->setType('sourcelessonid', PARAM_INT);
            $mform->addHelpButton('sourcelessonid', 'source_lesson', 'local_aiquizgen');
            $mform->hideIf('sourcelessonid', 'sourcetype', 'neq', 'lesson');
        } else {
            // Pas de leçon dans le cours → message informatif visible quand on
            // choisit « Leçon ».
            $mform->addElement('static', 'sourcelessonid_none',
                get_string('source_lesson', 'local_aiquizgen'),
                get_string('source_lesson_none', 'local_aiquizgen'));
            $mform->hideIf('sourcelessonid_none', 'sourcetype', 'neq', 'lesson');
        }

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
        //  VARIATION (fixe vs tirage aléatoire par tentative)
        // -----------------------------------------------------------
        $mform->addElement('header', 'variationheader',
            get_string('variation_heading', 'local_aiquizgen'));
        $mform->setExpanded('variationheader', true);

        $modes = array(
            'fixed'  => get_string('variation_mode_fixed',  'local_aiquizgen'),
            'random' => get_string('variation_mode_random', 'local_aiquizgen'),
        );
        $mform->addElement('select', 'variationmode',
            get_string('variation_mode', 'local_aiquizgen'), $modes);
        $mform->setDefault('variationmode', 'fixed');
        $mform->addHelpButton('variationmode', 'variation_mode', 'local_aiquizgen');

        // Nombre de questions tirées par tentative (visible si random).
        $mform->addElement('text', 'randomperattempt',
            get_string('randomperattempt', 'local_aiquizgen'),
            array('size' => 4));
        $mform->setType('randomperattempt', PARAM_INT);
        $mform->setDefault('randomperattempt', 10);
        $mform->addHelpButton('randomperattempt', 'randomperattempt', 'local_aiquizgen');
        $mform->hideIf('randomperattempt', 'variationmode', 'neq', 'random');

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
     * Validation serveur. Selon le type de source :
     *   - pdf    : un fichier PDF effectivement uploadé dans le draft area
     *   - lesson : une leçon choisie et appartenant au cours
     */
    public function validation($data, $files) {
        global $DB, $USER;

        $errors = parent::validation($data, $files);

        $count = isset($data['mcqcount']) ? (int)$data['mcqcount'] : 0;
        if ($count < 1) {
            $errors['mcqcount'] = get_string('error_mcqcount_min', 'local_aiquizgen');
        } else if ($count > 50) {
            $errors['mcqcount'] = get_string('error_mcqcount_max', 'local_aiquizgen');
        }

        // Cohérence du tirage aléatoire.
        $variationmode = isset($data['variationmode'])
            ? (string)$data['variationmode'] : 'fixed';
        if ($variationmode === 'random') {
            $rpp = isset($data['randomperattempt'])
                ? (int)$data['randomperattempt'] : 0;
            if ($rpp < 1) {
                $errors['randomperattempt'] = get_string(
                    'error_randomperattempt_min', 'local_aiquizgen');
            } else if ($count >= 1 && $rpp > $count) {
                // On ne peut pas tirer plus de questions que ce qu'on génère.
                $errors['randomperattempt'] = get_string(
                    'error_randomperattempt_max', 'local_aiquizgen');
            }
        }

        $sourcetype = isset($data['sourcetype']) ? (string)$data['sourcetype'] : 'pdf';
        $courseid   = isset($this->_customdata['courseid'])
            ? (int)$this->_customdata['courseid'] : 0;

        if ($sourcetype === 'pdf') {
            // Vérification serveur du PDF.
            if (!empty($data['sourcefile'])) {
                $fs        = get_file_storage();
                $usercxt   = \context_user::instance($USER->id);
                $draftfiles = $fs->get_area_files(
                    $usercxt->id, 'user', 'draft',
                    (int)$data['sourcefile'], 'id', false);
                if (empty($draftfiles)) {
                    $errors['sourcefile'] = get_string('error_source_required',
                        'local_aiquizgen');
                }
            } else {
                $errors['sourcefile'] = get_string('error_source_required',
                    'local_aiquizgen');
            }
        } else if ($sourcetype === 'lesson') {
            $lessonid = isset($data['sourcelessonid'])
                ? (int)$data['sourcelessonid'] : 0;
            if ($lessonid <= 0) {
                $errors['sourcelessonid'] = get_string(
                    'error_source_lesson_required', 'local_aiquizgen');
            } else if (!$DB->record_exists('lesson',
                    array('id' => $lessonid, 'course' => $courseid))) {
                $errors['sourcelessonid'] = get_string(
                    'error_source_lesson_invalid', 'local_aiquizgen');
            }
        }

        return $errors;
    }
}
