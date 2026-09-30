<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');

/**
 * Définition du type de question "AI Essay" : composition rédigée par
 * l'étudiant, notée de façon asynchrone par un LLM via la queue partagée
 * de local_aifeedback.
 *
 * Inspiré de qtype_essay côté UI (response area + pièces jointes), mais la
 * note est posée par un job en arrière-plan.
 */
class qtype_aiessay extends question_type {

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
        'responseformat'          => 'editor',
        'responserequired'        => 1,
        'responsefieldlines'      => 15,
        'minwordlimit'            => null,
        'maxwordlimit'            => null,
        'attachments'             => 0,
        'attachmentsrequired'     => 0,
        'filetypeslist'           => '',
        'systemprompt'            => '',
        'expectedanswer'          => '',
        'competencies'            => '',
        'apiurl'                  => '',
        'apiurl_override'         => 0,
        'model'                   => '',
        'model_override'          => 0,
        'apikey'                  => '',
        'apikey_override'         => 0,
        'vision_enabled'          => 0,
        'vision_enabled_override' => 0,
    );

    public function is_manual_graded() {
        // Du point de vue de mod_quiz on est en "needs grading" tant que le
        // job IA n'a pas tourné. C'est exactement ce que fait qtype_essay :
        // la note finit par être posée a posteriori.
        return true;
    }

    public function response_file_areas() {
        return array('attachments', 'answer');
    }

    public function extra_question_fields() {
        return array(
            'qtype_aiessay_options',
            'responseformat',
            'responserequired',
            'responsefieldlines',
            'minwordlimit',
            'maxwordlimit',
            'attachments',
            'attachmentsrequired',
            'filetypeslist',
            'systemprompt',
            'expectedanswer',
            'competencies',
            'apiurl',
            'apiurl_override',
            'model',
            'model_override',
            'apikey',
            'apikey_override',
            'vision_enabled',
            'vision_enabled_override',
        );
    }

    /**
     * Override : on chiffre l'apikey AVANT que le mécanisme standard de
     * extra_question_fields persiste les données.
     */
    public function save_question_options($formdata) {
        // Chiffre la clé API si elle est saisie en clair par le formulaire.
        if (isset($formdata->apikey) && is_string($formdata->apikey) && $formdata->apikey !== '') {
            $formdata->apikey = \local_aifeedback\secret::encrypt($formdata->apikey);
        }
        return parent::save_question_options($formdata);
    }

    /**
     * Charge la config IA pour le formulaire d'édition (déchiffre l'apikey).
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
                $value = (int)$value; // Colonne entière (défaut entier, ou null pour les limites de mots).
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

    /**
     * Hydrate l'objet question_definition (question.php) à partir des
     * données chargées par get_question_options().
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $opts = $questiondata->options;
        $question->responseformat       = $opts->responseformat;
        $question->responserequired     = (int)$opts->responserequired;
        $question->responsefieldlines   = (int)$opts->responsefieldlines;
        $question->minwordlimit         = $opts->minwordlimit;
        $question->maxwordlimit         = $opts->maxwordlimit;
        $question->attachments          = (int)$opts->attachments;
        $question->attachmentsrequired  = (int)$opts->attachmentsrequired;
        $question->filetypeslist        = (string)$opts->filetypeslist;
        // Les champs IA sont accessibles depuis $question->aicfg(...) si besoin
        // dans le handler ; on les stocke aussi tels quels pour le rendu.
        $question->systemprompt              = (string)$opts->systemprompt;
        $question->expectedanswer            = (string)$opts->expectedanswer;
        $question->competencies              = (string)$opts->competencies;
        $question->apiurl                    = (string)$opts->apiurl;
        $question->apiurl_override           = (int)$opts->apiurl_override;
        $question->model                     = (string)$opts->model;
        $question->model_override            = (int)$opts->model_override;
        $question->apikey                    = (string)$opts->apikey; // déjà déchiffrée
        $question->apikey_override           = (int)$opts->apikey_override;
        $question->vision_enabled            = (int)$opts->vision_enabled;
        $question->vision_enabled_override   = (int)$opts->vision_enabled_override;
    }
}
