<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Type de question "Réponse courte (correction IA)" : l'étudiant saisit une
 * réponse brève (mot, définition, phrase) notée de façon asynchrone par un LLM
 * via la queue partagée de local_aifeedback.
 *
 * Variante allégée de qtype_aiessay : pas de pièces jointes ni de limites de
 * mots ; la taille de la zone de saisie est un simple paramètre de question.
 */
class qtype_aishortanswer extends question_type {

    /**
     * Options qui ne quittent jamais le site : la clé API propre à la question est
     * chiffrée avec la clé du site (\core\encryption), indéchiffrable ailleurs, et
     * ne doit pas finir dans un fichier. La sauvegarde (donc aussi la duplication
     * d'un test, l'import et la copie de cours) et l'export XML les omettent ; la
     * question recréée n'a pas de clé propre et utilise celle du site.
     */
    const NONPORTABLE_FIELDS = array('apikey', 'apikey_override');

    /** Valeurs par défaut des options (celles de db/install.xml, chaîne vide pour les textes). */
    const DEFAULT_OPTIONS = array(
        'responsefieldlines' => 1,
        'systemprompt'       => '',
        'expectedanswer'     => '',
        'apiurl'             => '',
        'apiurl_override'    => 0,
        'model'              => '',
        'model_override'     => 0,
        'apikey'             => '',
        'apikey_override'    => 0,
    );

    public function is_manual_graded() {
        // En needs grading tant que le job IA n'a pas posé la note (a posteriori).
        return true;
    }

    public function extra_question_fields() {
        return array(
            'qtype_aishortanswer_options',
            'responsefieldlines',
            'systemprompt',
            'expectedanswer',
            'apiurl',
            'apiurl_override',
            'model',
            'model_override',
            'apikey',
            'apikey_override',
        );
    }

    /**
     * Chiffre l'apikey avant persistance par le mécanisme extra_question_fields.
     */
    public function save_question_options($formdata) {
        if (isset($formdata->apikey) && is_string($formdata->apikey) && $formdata->apikey !== '') {
            $formdata->apikey = \local_aifeedback\secret::encrypt($formdata->apikey);
        }
        return parent::save_question_options($formdata);
    }

    /**
     * Déchiffre l'apikey pour l'édition.
     */
    public function get_question_options($question) {
        $result = parent::get_question_options($question);
        if (!empty($question->options->apikey)) {
            $question->options->apikey = \local_aifeedback\secret::decrypt($question->options->apikey);
        }
        return $result;
    }

    /**
     * Options transportées par la sauvegarde et l'export XML : toutes celles de
     * extra_question_fields() sauf NONPORTABLE_FIELDS.
     *
     * @return string[]
     */
    public function portable_fields(): array {
        $fields = $this->extra_question_fields();
        array_shift($fields); // Nom de la table.
        return array_values(array_diff($fields, self::NONPORTABLE_FIELDS));
    }

    /**
     * Options d'une question recréée depuis un fichier (restauration d'une
     * sauvegarde, import XML) : valeur par défaut pour tout champ absent ou vide,
     * entiers convertis, et jamais de clé API propre.
     *
     * @param array $values valeurs lues dans le fichier, par nom de champ
     * @return array valeur de chaque colonne d'options
     */
    public function options_from_file(array $values): array {
        $fields = $this->extra_question_fields();
        array_shift($fields); // Nom de la table.
        $options = array();
        foreach ($fields as $field) {
            $default = array_key_exists($field, self::DEFAULT_OPTIONS) ? self::DEFAULT_OPTIONS[$field] : '';
            $value = isset($values[$field]) ? $values[$field] : null;
            if (in_array($field, self::NONPORTABLE_FIELDS, true)
                    || !is_scalar($value) || trim((string)$value) === '') {
                $value = $default;
            } else if (!is_string($default)) {
                $value = (int)$value; // Colonne entière.
            }
            $options[$field] = $value;
        }
        return $options;
    }

    /**
     * Export Moodle XML sans la clé API : get_question_options() l'a déchiffrée,
     * l'export par défaut l'écrirait en clair.
     */
    public function export_to_xml($question, qformat_xml $format, $extra = null) {
        $expout = '';
        foreach ($this->portable_fields() as $field) {
            $value = isset($question->options->$field) ? (string)$question->options->$field : '';
            $expout .= "    <{$field}>" . $format->xml_escape($value) . "</{$field}>\n";
        }
        return $expout;
    }

    /**
     * Import Moodle XML. L'implémentation par défaut lit $data['#']['answer'] sans
     * vérifier sa présence (avertissements PHP : ce type n'a pas de réponses) et
     * reprendrait une clé API écrite en clair par un ancien export.
     */
    public function import_from_xml($data, $question, qformat_xml $format, $extra = null) {
        if (!isset($data['@']['type']) || $data['@']['type'] != $this->name()) {
            return false;
        }
        $qo = $format->import_headers($data);
        $qo->qtype = $this->name();

        $values = array();
        foreach ($this->portable_fields() as $field) {
            $values[$field] = $format->getpath($data, array('#', $field, 0, '#'), null);
        }
        foreach ($this->options_from_file($values) as $field => $value) {
            $qo->$field = $value;
        }
        return $qo;
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $opts = $questiondata->options;
        $question->responsefieldlines = (int)$opts->responsefieldlines;
        $question->systemprompt        = (string)$opts->systemprompt;
        $question->expectedanswer      = (string)$opts->expectedanswer;
        $question->apiurl              = (string)$opts->apiurl;
        $question->apiurl_override     = (int)$opts->apiurl_override;
        $question->model               = (string)$opts->model;
        $question->model_override      = (int)$opts->model_override;
        $question->apikey              = (string)$opts->apikey; // déjà déchiffrée
        $question->apikey_override     = (int)$opts->apikey_override;
    }
}
