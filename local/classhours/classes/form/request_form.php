<?php
namespace local_classhours\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Demande d'accès exceptionnel de l'élève : un message facultatif pour
 * l'enseignant.
 */
class request_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('textarea', 'reason', get_string('request_reason', 'local_classhours'),
            array('rows' => 4, 'cols' => 60, 'maxlength' => \local_classhours\grant::MAXTEXT));
        $mform->setType('reason', PARAM_TEXT);
        $mform->addHelpButton('reason', 'request_reason', 'local_classhours');

        $this->add_action_buttons(true, get_string('request_submit', 'local_classhours'));
    }
}
