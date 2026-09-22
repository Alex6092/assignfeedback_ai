<?php
namespace local_moodlesearch\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Purge des recherches et des clics plus anciens que la durée de conservation
 * (réglage retentiondays ; 0 = conservés sans limite).
 */
class purge_logs extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_purge', 'local_moodlesearch');
    }

    public function execute() {
        $deleted = self::purge();
        mtrace('local_moodlesearch : ' . $deleted . ' recherche(s) purgée(s).');
    }

    /**
     * @param int|null $now pour les tests
     * @return int recherches supprimées
     */
    public static function purge(?int $now = null): int {
        global $DB;
        $days = (int)get_config('local_moodlesearch', 'retentiondays');
        if (get_config('local_moodlesearch', 'retentiondays') === false) {
            $days = 365;
        }
        if ($days <= 0) {
            return 0;
        }
        $before = ($now ?? time()) - $days * DAYSECS;
        $count = $DB->count_records_select('local_moodlesearch_search', 'timecreated < ?', array($before));
        $DB->delete_records_select('local_moodlesearch_click',
            'searchid IN (SELECT id FROM {local_moodlesearch_search} WHERE timecreated < ?)', array($before));
        $DB->delete_records_select('local_moodlesearch_click', 'timecreated < ?', array($before));
        $DB->delete_records_select('local_moodlesearch_search', 'timecreated < ?', array($before));
        return (int)$count;
    }
}
