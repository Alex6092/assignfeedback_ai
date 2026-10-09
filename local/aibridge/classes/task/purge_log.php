<?php
namespace local_aibridge\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Nettoyage quotidien du journal des appels au pont.
 */
class purge_log extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_purge_log', 'local_aibridge');
    }

    public function execute() {
        global $DB;
        $days = (int)get_config('local_aibridge', 'logretentiondays');
        if ($days <= 0) {
            return; // conservation illimitée
        }
        $DB->delete_records_select('local_aibridge_log', 'timecreated < ?',
            array(time() - $days * DAYSECS));
    }
}
