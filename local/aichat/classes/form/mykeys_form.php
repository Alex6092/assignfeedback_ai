<?php
namespace local_aichat\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use local_aichat\websearch\manager;
use local_aichat\websearch\userkeys;

/**
 * Formulaire « mes clés de recherche » de l'élève.
 *
 * Une clé enregistrée n'est jamais réaffichée : le champ reste vide et l'on
 * n'indique que ses 4 derniers caractères. Saisir une valeur la remplace ;
 * cocher « supprimer » la retire.
 *
 * customdata : status (string[] HTML par moteur), configured (bool[] par moteur)
 */
class mykeys_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        foreach (userkeys::PROVIDERS as $id) {
            $mform->addElement('header', 'hdr_' . $id, manager::NAMES[$id]);
            $mform->setExpanded('hdr_' . $id);
            $mform->addElement('static', 'status_' . $id, get_string('mykeys_status', 'local_aichat'),
                $this->_customdata['status'][$id]);

            $mform->addElement('password', 'key_' . $id, get_string('mykeys_newkey', 'local_aichat'),
                array('size' => 50, 'autocomplete' => 'new-password'));
            $mform->setType('key_' . $id, PARAM_RAW_TRIMMED);
            $mform->addHelpButton('key_' . $id, 'mykeys_' . $id, 'local_aichat');

            if (!empty($this->_customdata['configured'][$id])) {
                $mform->addElement('advcheckbox', 'remove_' . $id, get_string('mykeys_remove', 'local_aichat'));
            }
        }
        $this->add_action_buttons(false, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (userkeys::PROVIDERS as $id) {
            $key = isset($data['key_' . $id]) ? trim((string)$data['key_' . $id]) : '';
            if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-.]{8,200}$/', $key)) {
                $errors['key_' . $id] = get_string('mykeys_invalidformat', 'local_aichat');
            }
        }
        return $errors;
    }
}
