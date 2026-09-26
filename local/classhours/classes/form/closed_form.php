<?php
namespace local_classhours\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Ajout d'une période fermée (vacances, stage) : du premier au dernier jour
 * inclus, groupe, libellé. Pendant cette période, les créneaux hebdomadaires ne
 * s'appliquent pas ; les ouvertures exceptionnelles, si.
 *
 * customdata : 'groups' => [groupid => nom] (null : période globale du site,
 * sans groupe), 'timezone' => nom du fuseau.
 */
class closed_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $options = array('timezone' => $this->_customdata['timezone']);

        $mform->addElement('date_selector', 'datestart', get_string('datestart', 'local_classhours'), $options);
        $mform->addElement('date_selector', 'dateend', get_string('dateend', 'local_classhours'), $options);

        if ($this->_customdata['groups'] !== null) {
            $mform->addElement('select', 'groupid', get_string('group'), $this->_customdata['groups']);
        }

        $mform->addElement('text', 'name', get_string('periodname', 'local_classhours'), array('size' => 40));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->add_action_buttons(false, get_string('closed_add', 'local_classhours'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((int)($data['dateend'] ?? 0) < (int)($data['datestart'] ?? 0)) {
            $errors['dateend'] = get_string('error_endbeforestart', 'local_classhours');
        }
        if ($this->_customdata['groups'] !== null && !slot_form::valid_group($data, $this->_customdata['groups'])) {
            $errors['groupid'] = get_string('invalidgroupid', 'error');
        }
        return $errors;
    }

    /**
     * Bornes de la période : du minuit du premier jour au minuit du lendemain
     * du dernier jour, dans le fuseau des créneaux.
     *
     * @param \stdClass     $data données du formulaire
     * @param \DateTimeZone $tz
     * @return int[] [début, fin exclue]
     */
    public static function bounds(\stdClass $data, \DateTimeZone $tz): array {
        $start = (new \DateTimeImmutable('@' . (int)$data->datestart))->setTimezone($tz)->setTime(0, 0);
        $end = (new \DateTimeImmutable('@' . (int)$data->dateend))->setTimezone($tz)->setTime(0, 0)->modify('+1 day');
        return array($start->getTimestamp(), $end->getTimestamp());
    }
}
