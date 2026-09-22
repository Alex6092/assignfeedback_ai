<?php
namespace local_aimissions\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Duplication d'un sprint vers d'autres groupes.
 *
 * customdata attendu :
 *   - courseid, missionid (int)
 *   - groups (array idgroup => ['name' => string, 'blocker' => string]) : blocker =
 *     raison pour laquelle la COPIE est impossible ('' si possible)
 */
class duplicate_form extends \moodleform {

    const MODE_COPY  = 'copy';
    const MODE_ADAPT = 'adapt';

    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;

        $mform->addElement('hidden', 'courseid', (int)$custom['courseid']);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'missionid', (int)$custom['missionid']);
        $mform->setType('missionid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'duplicate');
        $mform->setType('action', PARAM_ALPHA);

        $modes = array();
        $modes[] = $mform->createElement('radio', 'mode', '',
            get_string('dup_mode_copy', 'local_aimissions'), self::MODE_COPY);
        $modes[] = $mform->createElement('radio', 'mode', '',
            get_string('dup_mode_adapt', 'local_aimissions'), self::MODE_ADAPT);
        $mform->addGroup($modes, 'modegroup', get_string('dup_mode', 'local_aimissions'),
            \html_writer::empty_tag('br'), false);
        $mform->addHelpButton('modegroup', 'dup_mode', 'local_aimissions');
        $mform->setDefault('mode', self::MODE_COPY);
        $mform->setType('mode', PARAM_ALPHA);

        $mform->addElement('header', 'h_targets', get_string('dup_targets', 'local_aimissions'));
        $mform->setExpanded('h_targets');
        if (empty($custom['groups'])) {
            $mform->addElement('static', 'nogroups', '', get_string('dup_nogroups', 'local_aimissions'));
        }
        foreach ($custom['groups'] as $gid => $group) {
            $label = s($group['name']);
            if ($group['blocker'] !== '') {
                $label .= ' ' . \html_writer::span('(' . $group['blocker'] . ')', 'text-muted small');
            }
            $mform->addElement('advcheckbox', 'target_' . (int)$gid, '', $label);
        }

        $this->add_action_buttons(true, get_string('dup_submit', 'local_aimissions'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $targets = self::targets($data);
        if (empty($targets)) {
            $errors['h_targets'] = get_string('dup_error_notarget', 'local_aimissions');
            return $errors;
        }
        if (($data['mode'] ?? self::MODE_COPY) === self::MODE_COPY) {
            $blocked = array();
            foreach ($targets as $gid) {
                $group = $this->_customdata['groups'][$gid] ?? null;
                if ($group === null || $group['blocker'] !== '') {
                    $blocked[] = $group ? $group['name'] : ('#' . $gid);
                }
            }
            if (!empty($blocked)) {
                $errors['modegroup'] = get_string('dup_error_ineligible', 'local_aimissions',
                    implode(', ', $blocked));
            }
        }
        return $errors;
    }

    /**
     * Groupes cochés.
     *
     * @param array|\stdClass $data
     * @return int[]
     */
    public static function targets($data): array {
        $out = array();
        foreach ((array)$data as $key => $value) {
            if (strpos((string)$key, 'target_') === 0 && !empty($value)) {
                $out[] = (int)substr((string)$key, strlen('target_'));
            }
        }
        return array_values(array_filter($out));
    }
}
