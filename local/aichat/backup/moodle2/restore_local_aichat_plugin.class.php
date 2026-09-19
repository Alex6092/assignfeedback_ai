<?php
defined('MOODLE_INTERNAL') || die();

use local_aichat\activity;
use local_aichat\brief;

/**
 * Restauration de la configuration du tuteur sur le NOUVEAU module créé par une
 * restauration, une importation ou une duplication d'activité.
 */
class restore_local_aichat_plugin extends restore_local_plugin {

    /** @var array|null configuration lue dans la sauvegarde, en attente d'écriture */
    protected $aichatdata = null;

    /**
     * Chemins restaurés : <module>/plugin_local_aichat_module/aichat_activity.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return array(
            new restore_path_element($this->get_namefor('activity'), $this->get_pathfor('/aichat_activity')),
        );
    }

    /**
     * Mémorise la configuration ; l'écriture attend after_restore_module(),
     * quand le module ET la configuration de la correction IA (source du
     * brief) sont restaurés.
     *
     * @param array|\stdClass $data
     */
    public function process_local_aichat_activity($data) {
        $this->aichatdata = (array)$data;
    }

    /**
     * Rattache la configuration au nouveau module, puis vérifie le brief.
     */
    public function after_restore_module() {
        if ($this->aichatdata === null) {
            return; // l'activité d'origine n'avait pas de tuteur configuré
        }
        $cmid     = (int)$this->task->get_moduleid();
        $courseid = (int)$this->task->get_courseid();
        if ($cmid <= 0 || activity::get($cmid) !== null) {
            return; // déjà configuré (restauration rejouée) : on n'écrase rien
        }

        $src = $this->aichatdata;
        $text = function($name) use ($src) {
            return (isset($src[$name]) && $src[$name] !== null) ? (string)$src[$name] : null;
        };

        // Brief repris s'il était prêt ; sinon (en file, en échec) il sera
        // régénéré. L'empreinte est comparée juste après au corrigé restauré.
        $brief = (string)$text('brief');
        $ready = ($text('briefstatus') === 'ready' && trim($brief) !== '');

        activity::save($cmid, $courseid, array(
            'enabled'        => !empty($src['enabled']) ? 1 : 0,
            'includeintro'   => !empty($src['includeintro']) ? 1 : 0,
            'includebrief'   => !empty($src['includebrief']) ? 1 : 0,
            'brief'          => $ready ? $brief : null,
            'briefstatus'    => $ready ? 'ready' : 'none',
            'briefhash'      => $ready ? (string)$text('briefhash') : '',
            'brieferror'     => null,
            'customprompt'   => $text('customprompt'),
            'quizscope'      => ($text('quizscope') === 'tagged') ? 'tagged' : 'all',
            'quiztag'        => (string)$text('quiztag'),
            'websearch'      => max(0, min(2, (int)$text('websearch'))),
            'websearchsites' => $text('websearchsites'),
        ));

        // Brief absent, ou corrigé différent de celui qui l'avait produit :
        // régénération en file. Un échec ici ne doit jamais faire échouer la
        // restauration (le bouton « Régénérer » reste disponible).
        try {
            brief::schedule_if_stale($cmid);
        } catch (\Throwable $e) {
            debugging('local_aichat restore: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
