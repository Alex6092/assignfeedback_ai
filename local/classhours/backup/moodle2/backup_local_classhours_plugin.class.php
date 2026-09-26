<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Sauvegarde des heures de cours.
 *
 * Niveau <course> : emploi du temps (créneaux, ouvertures, périodes fermées
 * du cours), option EFE et case « périodes globales ». Les périodes globales
 * elles-mêmes appartiennent au site et ne sont pas sauvegardées.
 * Niveau <module> : exclusion de l'activité de l'option EFE ; elle suit donc
 * aussi une duplication d'activité.
 *
 * La restriction elle-même ({"type":"classhours"}) fait partie du JSON
 * d'accès de l'activité, que Moodle sauvegarde déjà.
 */
class backup_local_classhours_plugin extends backup_local_plugin {

    /**
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());

        $settings = new backup_nested_element('classhours_course', array('id'), array('efeauto', 'useglobal'));

        $slots = new backup_nested_element('classhours_slots');
        $slot = new backup_nested_element('classhours_slot', array('id'),
            array('groupid', 'weekday', 'starttime', 'endtime'));

        $periods = new backup_nested_element('classhours_periods');
        $period = new backup_nested_element('classhours_period', array('id'),
            array('groupid', 'type', 'timestart', 'timeend', 'name'));

        $plugin->add_child($wrapper);
        $wrapper->add_child($settings);
        $wrapper->add_child($slots);
        $slots->add_child($slot);
        $wrapper->add_child($periods);
        $periods->add_child($period);

        $settings->set_source_table('local_classhours_course', array('courseid' => backup::VAR_COURSEID));
        $slot->set_source_table('local_classhours_slot', array('courseid' => backup::VAR_COURSEID));
        $period->set_source_table('local_classhours_period', array('courseid' => backup::VAR_COURSEID));

        return $plugin;
    }

    /**
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());

        $exclude = new backup_nested_element('classhours_exclude', array('id'), array('timecreated'));

        $plugin->add_child($wrapper);
        $wrapper->add_child($exclude);

        $exclude->set_source_table('local_classhours_exclude', array('cmid' => backup::VAR_MODID));

        return $plugin;
    }
}
