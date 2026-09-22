<?php
namespace local_aimissions\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Formulaire de génération d'un sprint (une demande client par groupe).
 *
 * customdata attendu :
 *   - courseid          (int)
 *   - groups            (array idgroup => nom)
 *   - competences       (array code => libellé : efe_bridge::competence_options())
 *   - efeconfigured     (bool)
 *   - efeloaderror      (string|null) référentiel EFE injoignable
 *   - canmanagegroups   (bool) moodle/course:managegroups
 *   - aichat            (bool) Tuteur IA installé
 *   - checked           (int[]) groupes à cocher d'office (juste créés)
 *   - defaultcontext    (string) contexte pédagogique du dernier sprint du cours
 */
class generate_form extends \moodleform {

    /** Longueur maximale du contexte pédagogique. */
    const MAXCONTEXT = 4000;

    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;

        $mform->addElement('hidden', 'courseid', (int)$custom['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('header', 'h_target', get_string('form_target', 'local_aimissions'));

        // Contexte pédagogique : attentes, contraintes, choix technologiques imposés.
        $mform->addElement('textarea', 'pedagogicalcontext',
            get_string('form_pedagogicalcontext', 'local_aimissions'),
            array('rows' => 6, 'cols' => 70));
        $mform->setType('pedagogicalcontext', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('pedagogicalcontext', 'form_pedagogicalcontext', 'local_aimissions');
        if (!empty($custom['defaultcontext'])) {
            $mform->setDefault('pedagogicalcontext', (string)$custom['defaultcontext']);
        }

        // Compétences évaluées : plusieurs codes EFE, ou un libellé libre.
        if (!empty($custom['efeconfigured']) && !empty($custom['competences'])) {
            $mform->addElement('autocomplete', 'competencies',
                get_string('form_competency', 'local_aimissions'), $custom['competences'],
                array('multiple' => true,
                      'noselectionstring' => get_string('form_competency_choose', 'local_aimissions')));
            $mform->addHelpButton('competencies', 'form_competency', 'local_aimissions');
        } else {
            if (!empty($custom['efeloaderror'])) {
                $mform->addElement('static', 'efe_note', '',
                    \html_writer::span(s($custom['efeloaderror']), 'text-danger'));
            } else if (empty($custom['efeconfigured'])) {
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

        // Tuteur IA sur les devoirs de ce sprint.
        if (!empty($custom['aichat'])) {
            $mform->addElement('header', 'h_aichat', get_string('form_aichat_heading', 'local_aimissions'));
            $mform->addElement('advcheckbox', 'aichat', get_string('form_aichat', 'local_aimissions'));
            $mform->addHelpButton('aichat', 'form_aichat', 'local_aimissions');
            $mform->setDefault('aichat', 0);
            $mform->addElement('select', 'aichatsearch', get_string('form_aichatsearch', 'local_aimissions'), array(
                \local_aimissions\aichat_bridge::SEARCH_NONE     => get_string('aichatsearch_none', 'local_aimissions'),
                \local_aimissions\aichat_bridge::SEARCH_WEB      => get_string('aichatsearch_web', 'local_aimissions'),
                \local_aimissions\aichat_bridge::SEARCH_MATERIAL => get_string('aichatsearch_material', 'local_aimissions'),
            ));
            $mform->addHelpButton('aichatsearch', 'form_aichatsearch', 'local_aimissions');
            $mform->hideIf('aichatsearch', 'aichat', 'notchecked');
        }

        // Groupes cibles.
        $mform->addElement('header', 'h_groups', get_string('form_groups_heading', 'local_aimissions'));
        $mform->setExpanded('h_groups');
        $checked = array_map('intval', (array)($custom['checked'] ?? array()));
        if (empty($custom['groups'])) {
            $mform->addElement('static', 'nogroups', '', get_string(
                !empty($custom['canmanagegroups']) ? 'form_nogroups_create' : 'form_nogroups',
                'local_aimissions'));
        } else {
            foreach ($custom['groups'] as $gid => $gname) {
                $mform->addElement('advcheckbox', 'group_' . (int)$gid, '', $gname);
                if (in_array((int)$gid, $checked, true)) {
                    $mform->setDefault('group_' . (int)$gid, 1);
                }
            }
            $mform->addElement('static', 'groups_help', '',
                get_string('form_groups_help', 'local_aimissions'));
        }
        if (!empty($custom['canmanagegroups'])) {
            $mform->addElement('textarea', 'newgroups', get_string('form_newgroups', 'local_aimissions'),
                array('rows' => 3, 'cols' => 40));
            $mform->setType('newgroups', PARAM_TEXT);
            $mform->addHelpButton('newgroups', 'form_newgroups', 'local_aimissions');
            $mform->registerNoSubmitButton('creategroups');
            $mform->addElement('submit', 'creategroups', get_string('form_creategroups', 'local_aimissions'));
        }

        $this->add_action_buttons(true, get_string('form_submit', 'local_aimissions'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Au moins un groupe coché, ou un groupe à créer.
        $hasgroup = trim((string)($data['newgroups'] ?? '')) !== '';
        foreach ($data as $k => $v) {
            if (strpos($k, 'group_') === 0 && !empty($v)) {
                $hasgroup = true;
                break;
            }
        }
        if (!$hasgroup) {
            $errors['h_groups'] = get_string('error_nogroup', 'local_aimissions');
        }

        // Compétences EFE : au moins une (le libellé libre reste facultatif).
        if (!empty($this->_customdata['efeconfigured']) && !empty($this->_customdata['competences'])
                && empty($data['competencies'])) {
            $errors['competencies'] = get_string('error_nocompetency', 'local_aimissions');
        }

        if (\core_text::strlen((string)($data['pedagogicalcontext'] ?? '')) > self::MAXCONTEXT) {
            $errors['pedagogicalcontext'] = get_string('error_contexttoolong', 'local_aimissions',
                self::MAXCONTEXT);
        }
        return $errors;
    }
}
