<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Sauvegarde de la durée minimale attendue d'une activité (niveau <module> :
 * elle suit aussi la duplication d'une activité).
 *
 * Les décisions « légitime » portent sur des tentatives d'élèves précises :
 * elles ne suivent pas l'activité.
 */
class backup_local_attemptcheck_plugin extends backup_local_plugin {

    /**
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $activity = new backup_nested_element('attemptcheck_activity', array('id'), array('minduration'));

        $plugin->add_child($wrapper);
        $wrapper->add_child($activity);

        $activity->set_source_table('local_attemptcheck_activity', array('cmid' => backup::VAR_MODID));

        return $plugin;
    }
}
