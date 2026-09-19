<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Sauvegarde de la configuration du tuteur (local_aichat_activity) attachée à
 * un module de cours.
 *
 * Moodle l'appelle pour toute activité sauvegardée (backup_module_structure_step
 * branche les plugins locaux au niveau <module>) : sauvegarde de cours,
 * importation (« Réutilisation de cours ») et DUPLICATION d'une activité, qui
 * passe en interne par une sauvegarde suivie d'une restauration.
 *
 * Seule la configuration est sauvegardée. Les conversations des élèves n'en
 * font pas partie : ce sont des échanges personnels, liés à une cohorte, qui
 * n'ont pas à suivre l'activité dans un autre cours.
 */
class backup_local_aichat_plugin extends backup_local_plugin {

    /**
     * Structure attachée à l'élément <module>.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());

        // cmid et courseid sont recalculés à la restauration, les dates
        // régénérées ; brieferror n'a pas de sens hors de l'activité d'origine.
        // Le brief est repris tel quel : son empreinte dit, à la restauration,
        // s'il correspond encore au corrigé restauré (sinon il est régénéré).
        $activity = new backup_nested_element('aichat_activity', array('id'), array(
            'enabled',
            'includeintro',
            'includebrief',
            'brief',
            'briefstatus',
            'briefhash',
            'customprompt',
            'quizscope',
            'quiztag',
            'websearch',
            'websearchsites',
        ));

        $plugin->add_child($wrapper);
        $wrapper->add_child($activity);

        $activity->set_source_table('local_aichat_activity', array('cmid' => backup::VAR_MODID));

        return $plugin;
    }
}
