<?php
namespace local_classhours\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use local_classhours\schedule;

/**
 * Ajout d'un créneau hebdomadaire : jour, heure de début, heure de fin, groupe.
 *
 * customdata : 'groups' => [groupid => nom] (0 = tout le cours).
 */
class slot_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;

        $days = array();
        for ($d = 1; $d <= 7; $d++) {
            $days[$d] = schedule::weekday_name($d);
        }
        $mform->addElement('select', 'weekday', get_string('weekday', 'local_classhours'), $days);

        self::add_time($mform, 'start', get_string('starttime', 'local_classhours'), 8, 0);
        self::add_time($mform, 'end', get_string('endtime', 'local_classhours'), 10, 0);

        $mform->addElement('select', 'groupid', get_string('group'), $this->_customdata['groups']);

        $this->add_action_buttons(false, get_string('slot_add', 'local_classhours'));
    }

    /**
     * Heure : deux listes (heures, minutes par pas de 5).
     */
    public static function add_time(\MoodleQuickForm $mform, string $name, string $label, int $hour, int $minute): void {
        $hours = array();
        for ($h = 0; $h <= 23; $h++) {
            $hours[$h] = sprintf('%02d', $h);
        }
        $minutes = array();
        for ($m = 0; $m < 60; $m += 5) {
            $minutes[$m] = sprintf('%02d', $m);
        }
        $mform->addGroup(array(
            $mform->createElement('select', 'hour', get_string('hour', 'form'), $hours),
            $mform->createElement('select', 'minute', get_string('minute', 'form'), $minutes),
        ), $name, $label, ' : ', true);
        $mform->setDefault($name . '[hour]', $hour);
        $mform->setDefault($name . '[minute]', $minute);
    }

    /** Minutes depuis minuit d'un groupe « heure : minute ». */
    public static function minutes($value): int {
        $value = (array)$value;
        return max(0, min(23, (int)($value['hour'] ?? 0))) * 60 + max(0, min(59, (int)($value['minute'] ?? 0)));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (self::minutes($data['end'] ?? array()) <= self::minutes($data['start'] ?? array())) {
            $errors['end'] = get_string('error_endbeforestart', 'local_classhours');
        }
        if (!self::valid_group($data, $this->_customdata['groups'])) {
            $errors['groupid'] = get_string('invalidgroupid', 'error');
        }
        return $errors;
    }

    /**
     * Le groupe choisi est-il bien un groupe du cours (ou « tout le cours ») ?
     *
     * @param array $data
     * @param array $groups groupid => nom
     */
    public static function valid_group(array $data, array $groups): bool {
        return array_key_exists((int)($data['groupid'] ?? -1), $groups);
    }
}
