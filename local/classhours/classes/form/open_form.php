<?php
namespace local_classhours\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Ajout d'une ouverture exceptionnelle : début et fin datés (rattrapage,
 * séance déplacée), groupe, libellé. Dates saisies dans le fuseau du serveur.
 *
 * customdata : 'groups' => [groupid => nom], 'timezone' => nom du fuseau.
 */
class open_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $options = array('timezone' => $this->_customdata['timezone'], 'step' => 5);

        $mform->addElement('date_time_selector', 'timestart', get_string('timestart', 'local_classhours'), $options);
        $mform->addElement('date_time_selector', 'timeend', get_string('timeend', 'local_classhours'), $options);
        $start = (int)(ceil(time() / HOURSECS) * HOURSECS);
        $mform->setDefault('timestart', $start);
        $mform->setDefault('timeend', $start + 2 * HOURSECS);

        $mform->addElement('select', 'groupid', get_string('group'), $this->_customdata['groups']);

        $mform->addElement('text', 'name', get_string('periodname', 'local_classhours'), array('size' => 40));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->add_action_buttons(false, get_string('open_add', 'local_classhours'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((int)($data['timeend'] ?? 0) <= (int)($data['timestart'] ?? 0)) {
            $errors['timeend'] = get_string('error_endbeforestart', 'local_classhours');
        }
        if (!slot_form::valid_group($data, $this->_customdata['groups'])) {
            $errors['groupid'] = get_string('invalidgroupid', 'error');
        }
        return $errors;
    }
}
