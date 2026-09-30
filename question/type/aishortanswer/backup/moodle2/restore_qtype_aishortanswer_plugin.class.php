<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/type/aishortanswer/questiontype.php');

/**
 * Restauration des options d'une question Réponse courte IA, pendant de
 * backup_qtype_aishortanswer_plugin.
 */
class restore_qtype_aishortanswer_plugin extends restore_qtype_plugin {

    /**
     * @return restore_path_element[]
     */
    protected function define_question_plugin_structure() {
        return array(
            new restore_path_element('aishortanswer', $this->get_pathfor('/aishortanswer')),
        );
    }

    /**
     * Crée les options quand la restauration crée la question (une question déjà
     * présente et reprise telle quelle garde les siennes). Champ absent : valeur
     * par défaut. Clé API : aucune, la question utilise celle du site.
     *
     * @param array $data
     */
    public function process_aishortanswer($data) {
        global $DB;

        $data = (array)$data;
        $questioncreated = $this->get_mappingid('question_created',
            $this->get_old_parentid('question')) ? true : false;
        if (!$questioncreated) {
            return;
        }

        $options = (object)question_bank::get_qtype('aishortanswer')->options_from_file($data);
        $options->questionid = $this->get_new_parentid('question');
        $newitemid = $DB->insert_record('qtype_aishortanswer_options', $options);
        $this->set_mapping('qtype_aishortanswer_options', $data['id'], $newitemid);
    }

    /**
     * La clé API n'est pas dans la sauvegarde, mais get_question_options() la
     * charge côté base : on l'écarte de l'empreinte qui reconnaît une question
     * déjà présente, sinon aucune question avec une clé propre ne serait reconnue.
     *
     * @return string[]
     */
    protected function define_excluded_identity_hash_fields(): array {
        $paths = array();
        foreach (qtype_aishortanswer::NONPORTABLE_FIELDS as $field) {
            $paths[] = '/options/' . $field;
        }
        return $paths;
    }

    /**
     * Les liens vers le site écrits dans les textes de configuration sont encodés
     * à la sauvegarde (comme tout le contenu) : on les décode ici.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return array(
            new restore_decode_content('qtype_aishortanswer_options',
                array('systemprompt', 'expectedanswer'), 'qtype_aishortanswer_options'),
        );
    }

    /**
     * Sauvegardes faites avant la prise en charge de ce type, sans options :
     * options par défaut, pour que la question se charge et reste modifiable.
     */
    protected function after_execute_question() {
        global $DB;

        $questionids = $DB->get_fieldset_sql("
                SELECT q.id
                  FROM {question} q
                  JOIN {backup_ids_temp} bi ON bi.newitemid = q.id
             LEFT JOIN {qtype_aishortanswer_options} o ON o.questionid = q.id
                 WHERE q.qtype = ?
                   AND o.id IS NULL
                   AND bi.backupid = ?
                   AND bi.itemname = ?",
            array('aishortanswer', $this->get_restoreid(), 'question_created'));

        $defaults = question_bank::get_qtype('aishortanswer')->options_from_file(array());
        foreach ($questionids as $questionid) {
            $options = (object)$defaults;
            $options->questionid = $questionid;
            $DB->insert_record('qtype_aishortanswer_options', $options);
        }
    }
}
