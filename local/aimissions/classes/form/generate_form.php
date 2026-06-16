<?php
namespace local_aimissions\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Formulaire de génération de missions client.
 *
 * customdata attendu :
 *   - courseid      (int)
 *   - groups        (array idgroup => nom)
 *   - competences   (array : sortie de efe_bridge::get_competences())
 *   - efeconfigured (bool)
 */
class generate_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;

        $mform->addElement('hidden', 'courseid', (int)$custom['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('header', 'h_target', get_string('form_target', 'local_aimissions'));

        // Module / matière.
        $mform->addElement('text', 'module', get_string('form_module', 'local_aimissions'),
            array('size' => 50, 'maxlength' => 200));
        $mform->setType('module', PARAM_TEXT);
        $mform->addHelpButton('module', 'form_module', 'local_aimissions');

        // Compétence évaluée.
        if (!empty($custom['efeconfigured']) && !empty($custom['competences'])) {
            $options = array('' => get_string('form_competency_choose', 'local_aimissions'));
            foreach (array('n1', 'n2', 'n3') as $lvl) {
                foreach (($custom['competences'][$lvl] ?? array()) as $c) {
                    $code = (string)($c['code'] ?? '');
                    $nom  = (string)($c['nom'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $options[$lvl . ':' . $code] = strtoupper($lvl) . ' · ' . $code . ' — ' . $nom;
                }
            }
            $mform->addElement('select', 'competency',
                get_string('form_competency', 'local_aimissions'), $options);
            $mform->addHelpButton('competency', 'form_competency', 'local_aimissions');
        } else {
            if (empty($custom['efeconfigured'])) {
                $mform->addElement('static', 'efe_note', '',
                    get_string('efe_unavailable', 'local_aimissions'));
            }
            $mform->addElement('text', 'competencylabel',
                get_string('form_competencylabel', 'local_aimissions'),
                array('size' => 60, 'maxlength' => 250));
            $mform->setType('competencylabel', PARAM_TEXT);
            $mform->addHelpButton('competencylabel', 'form_competencylabel', 'local_aimissions');
        }

        // Niveau.
        $mform->addElement('select', 'level', get_string('form_level', 'local_aimissions'), array(
            'BTS CIEL 1ère année' => 'BTS CIEL 1ère année',
            'BTS CIEL 2ème année' => 'BTS CIEL 2ème année',
        ));

        // Complexité.
        $mform->addElement('select', 'complexity', get_string('form_complexity', 'local_aimissions'), array(
            'Découverte'    => get_string('complexity_easy', 'local_aimissions'),
            'Intermédiaire' => get_string('complexity_medium', 'local_aimissions'),
            'Avancé'        => get_string('complexity_hard', 'local_aimissions'),
        ));
        $mform->setDefault('complexity', 'Intermédiaire');

        // Nombre de contraintes.
        $mform->addElement('select', 'constraints', get_string('form_constraints', 'local_aimissions'),
            array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5));
        $mform->setDefault('constraints', 3);

        // Profil du client.
        $mform->addElement('select', 'personaprofile', get_string('form_persona', 'local_aimissions'), array(
            'neutre'       => get_string('persona_neutre', 'local_aimissions'),
            'exigeant'     => get_string('persona_exigeant', 'local_aimissions'),
            'imprecis'     => get_string('persona_imprecis', 'local_aimissions'),
            'versatile'    => get_string('persona_versatile', 'local_aimissions'),
            'lent'         => get_string('persona_lent', 'local_aimissions'),
            'nontechnique' => get_string('persona_nontechnique', 'local_aimissions'),
        ));
        $mform->addHelpButton('personaprofile', 'form_persona', 'local_aimissions');

        // Groupes cibles.
        $mform->addElement('header', 'h_groups', get_string('form_groups_heading', 'local_aimissions'));
        if (empty($custom['groups'])) {
            $mform->addElement('static', 'nogroups', '',
                get_string('form_nogroups', 'local_aimissions'));
        } else {
            foreach ($custom['groups'] as $gid => $gname) {
                $mform->addElement('advcheckbox', 'group_' . (int)$gid, '', $gname);
            }
            $mform->addElement('static', 'groups_help', '',
                get_string('form_groups_help', 'local_aimissions'));
        }

        $this->add_action_buttons(true, get_string('form_submit', 'local_aimissions'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Au moins un groupe coché.
        $hasgroup = false;
        foreach ($data as $k => $v) {
            if (strpos($k, 'group_') === 0 && !empty($v)) {
                $hasgroup = true;
                break;
            }
        }
        if (!$hasgroup) {
            $errors['h_groups'] = get_string('error_nogroup', 'local_aimissions');
        }

        // Compétence : code EFE ou libellé libre requis.
        if (isset($data['competency']) && trim((string)$data['competency']) === ''
                && empty($data['competencylabel'])) {
            $errors['competency'] = get_string('error_nocompetency', 'local_aimissions');
        }
        return $errors;
    }
}
