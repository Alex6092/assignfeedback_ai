<?php
defined('MOODLE_INTERNAL') || die();

use local_attemptcheck\review;

/**
 * Restauration de la durée minimale attendue sur le nouveau module.
 */
class restore_local_attemptcheck_plugin extends restore_local_plugin {

    /**
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return array(
            new restore_path_element($this->get_namefor('activity'), $this->get_pathfor('/attemptcheck_activity')),
        );
    }

    /**
     * @param array|\stdClass $data
     */
    public function process_local_attemptcheck_activity($data) {
        $data = (object)$data;
        $cmid = (int)$this->task->get_moduleid();
        if ($cmid > 0 && (int)$data->minduration > 0 && review::get_minduration($cmid) === 0) {
            review::set_minduration($cmid, (int)$this->task->get_courseid(), (int)$data->minduration);
        }
    }
}
