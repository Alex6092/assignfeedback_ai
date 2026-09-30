<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/type/aiessay/questiontype.php');

/**
 * Sauvegarde des options d'une question Composition IA (qtype_aiessay_options).
 *
 * Sans cette classe, la sauvegarde ne contient que la ligne {question} : toute
 * restauration (duplication d'un test, dont la banque propre est recopiée par
 * sauvegarde/restauration, restauration, import ou copie de cours) recrée la
 * question sans prompt, corrigé ni compétences.
 */
class backup_qtype_aiessay_plugin extends backup_qtype_plugin {

    /**
     * <plugin_qtype_aiessay_question><aiessay id="…">options</aiessay>, sans la clé
     * API propre à la question (qtype_aiessay::NONPORTABLE_FIELDS).
     *
     * @return backup_plugin_element
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../qtype', 'aiessay');

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $options = new backup_nested_element('aiessay', array('id'),
            question_bank::get_qtype('aiessay')->portable_fields());
        $pluginwrapper->add_child($options);
        $options->set_source_table('qtype_aiessay_options',
            array('questionid' => backup::VAR_PARENTID));

        return $plugin;
    }
}
