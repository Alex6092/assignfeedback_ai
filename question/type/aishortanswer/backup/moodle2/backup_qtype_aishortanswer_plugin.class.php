<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/type/aishortanswer/questiontype.php');

/**
 * Sauvegarde des options d'une question Réponse courte IA
 * (qtype_aishortanswer_options).
 *
 * Sans cette classe, la sauvegarde ne contient que la ligne {question} : toute
 * restauration (duplication d'un test, dont la banque propre est recopiée par
 * sauvegarde/restauration, restauration, import ou copie de cours) recrée la
 * question sans prompt ni corrigé.
 */
class backup_qtype_aishortanswer_plugin extends backup_qtype_plugin {

    /**
     * <plugin_qtype_aishortanswer_question><aishortanswer id="…">options</aishortanswer>,
     * sans la clé API propre à la question (qtype_aishortanswer::NONPORTABLE_FIELDS).
     *
     * @return backup_plugin_element
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../qtype', 'aishortanswer');

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $options = new backup_nested_element('aishortanswer', array('id'),
            question_bank::get_qtype('aishortanswer')->portable_fields());
        $pluginwrapper->add_child($options);
        $options->set_source_table('qtype_aishortanswer_options',
            array('questionid' => backup::VAR_PARENTID));

        return $plugin;
    }
}
