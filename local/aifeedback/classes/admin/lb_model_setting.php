<?php
namespace local_aifeedback\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

use local_aifeedback\lb\client;

/**
 * Réglage « modèle » : liste déroulante alimentée par le catalogue du load
 * balancer (GET /v1/models).
 *
 * Si le catalogue est indisponible (service injoignable, clé non encore
 * saisie), le réglage devient un champ texte libre : l'administrateur n'est
 * jamais bloqué. Une valeur absente du catalogue reste affichée et signalée.
 *
 * Utilisé par local_aifeedback (modèle des corrections) et par les autres
 * plugins IA (tuteur, génération de quiz, missions…), avec une option vide
 * « modèle des corrections » quand le réglage est facultatif.
 */
class lb_model_setting extends \admin_setting_configselect {

    /** @var string|null libellé de l'option vide (null = modèle à choisir obligatoirement) */
    private $emptylabel;

    /** @var string|null message d'erreur du catalogue (null = catalogue disponible) */
    private $catalogueerror = null;

    /**
     * @param string      $name        plugin/réglage
     * @param string      $visiblename
     * @param string      $description
     * @param string|null $emptylabel  libellé de l'option vide, ou null si le choix est obligatoire
     */
    public function __construct($name, $visiblename, $description, $emptylabel = null) {
        $this->emptylabel = $emptylabel;
        parent::__construct($name, $visiblename, $description, '', null);
    }

    public function load_choices() {
        if (is_array($this->choices)) {
            return true;
        }
        $choices = array(
            '' => ($this->emptylabel !== null) ? $this->emptylabel : get_string('lbmodel_choose', 'local_aifeedback'),
        );
        try {
            foreach (client::models() as $id => $model) {
                $choices[$id] = self::label($model);
            }
            $this->catalogueerror = null;
        } catch (\Throwable $e) {
            $this->catalogueerror = $e->getMessage();
        }
        $current = $this->config_read($this->name);
        if ($current !== null && $current !== '' && !isset($choices[$current])) {
            $choices[$current] = get_string('lbmodel_missing', 'local_aifeedback', $current);
        }
        $this->choices = $choices;
        return true;
    }

    public function write_setting($data) {
        $this->load_choices();
        if ($this->catalogueerror !== null) {
            // Catalogue indisponible : saisie libre.
            $data = trim(clean_param((string)$data, PARAM_TEXT));
            return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
        }
        return parent::write_setting($data);
    }

    public function output_html($data, $query = '') {
        global $OUTPUT;
        $this->load_choices();
        if ($this->catalogueerror === null) {
            return parent::output_html($data, $query);
        }
        $context = (object)array(
            'size'     => 40,
            'id'       => $this->get_id(),
            'name'     => $this->get_full_name(),
            'value'    => (string)$data,
            'forceltr' => true,
            'readonly' => $this->is_readonly(),
        );
        $element = $OUTPUT->render_from_template('core_admin/setting_configtext', $context);
        $description = $this->description . \html_writer::div(
            get_string('lbmodel_unavailable', 'local_aifeedback', s($this->catalogueerror)),
            'alert alert-warning mt-2');
        return format_admin_setting($this, $this->visiblename, $element, $description, true, '',
            $this->get_defaultsetting(), $query);
    }

    /**
     * Libellé d'un modèle du catalogue : identifiant, vision, contexte, sortie.
     *
     * @param array $model {id, vision, context_tokens, max_output_tokens}
     * @return string
     */
    public static function label(array $model) {
        return get_string('lbmodel_label', 'local_aifeedback', (object)array(
            'id'      => $model['id'],
            'vision'  => !empty($model['vision']) ? ' · ' . get_string('lbmodel_vision', 'local_aifeedback') : '',
            'context' => number_format((int)$model['context_tokens'], 0, ',', ' '),
            'output'  => number_format((int)$model['max_output_tokens'], 0, ',', ' '),
        ));
    }
}
