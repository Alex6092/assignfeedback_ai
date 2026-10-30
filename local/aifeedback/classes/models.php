<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Choix du modèle (catalogue du load balancer) selon l'usage.
 *
 * Le modèle des corrections IA (local_aifeedback/model_feedback) sert de
 * repli à tous les plugins : chacun peut le remplacer par son propre réglage
 * (modèle du tuteur, de la génération de quiz, des missions…).
 */
class models {

    /** Modèle des corrections IA (devoirs et questions de quiz). */
    public static function feedback() {
        return trim((string)get_config('local_aifeedback', 'model_feedback'));
    }

    /**
     * Modèle d'un plugin : son réglage s'il est renseigné, sinon le modèle des
     * corrections.
     *
     * @param string $component frankenstyle du plugin (ex. local_aiquizgen)
     * @param string $setting   nom du réglage dans ce plugin
     * @return string
     */
    public static function for_component($component, $setting = 'model') {
        $model = trim((string)get_config($component, $setting));
        return ($model !== '') ? $model : self::feedback();
    }

    /**
     * Modèles configurés dans Moodle, avec leurs usages : réglages des plugins
     * et surcharges d'activités qui passent par le load balancer (un modèle
     * seul, sans URL d'API externe). Sert à vérifier qu'ils existent bien
     * dans le catalogue du load balancer.
     *
     * @return array modèle => string[] usages lisibles
     */
    public static function configured() {
        global $DB;
        $out = array();
        $add = function($model, $usage) use (&$out) {
            $model = trim((string)$model);
            if ($model !== '') {
                $out[$model][] = $usage;
            }
        };

        $add(self::feedback(), get_string('usage_feedback', 'local_aifeedback'));
        $settings = array(
            array('local_aichat', 'model_tutor', 'usage_tutor'),
            array('local_aichat', 'model_moderation', 'usage_moderation'),
            array('local_aiquizgen', 'model', 'usage_quizgen'),
            array('local_aimissions', 'model', 'usage_missions'),
        );
        foreach ($settings as $s) {
            if (\core_component::get_component_directory($s[0])) {
                $add(get_config($s[0], $s[1]), get_string($s[2], 'local_aifeedback'));
            }
        }

        // Surcharges d'activités : modèle imposé SANS API externe.
        $tables = array(
            'assignfeedback_ai'           => 'usage_assignoverride',
            'qtype_aiessay_options'       => 'usage_questionoverride',
            'qtype_aishortanswer_options' => 'usage_questionoverride',
        );
        $dbman = $DB->get_manager();
        foreach ($tables as $table => $usage) {
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $rows = $DB->get_records_sql('SELECT model, COUNT(*) AS n
                                            FROM {' . $table . '}
                                           WHERE model_override = 1 AND apiurl_override = 0
                                        GROUP BY model');
            foreach ($rows as $row) {
                $add($row->model, get_string($usage, 'local_aifeedback', (int)$row->n));
            }
        }
        return $out;
    }
}
