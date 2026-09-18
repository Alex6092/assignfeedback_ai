<?php
namespace local_aichat\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Nettoyage quotidien : conversations expirées et messages restés bloqués.
 */
class purge extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_purge', 'local_aichat');
    }

    public function execute() {
        global $DB;

        // 1. Messages abandonnés : un message « pending » ou « streaming » vieux
        //    de plus d'une heure correspond à un PHP mort ou à un onglet fermé.
        //    Sans cela, la conversation resterait bloquée pour l'élève.
        $stale = $DB->get_records_select('local_aichat_message',
            "status IN (?, ?) AND timecreated < ?",
            array('pending', 'streaming', time() - HOURSECS), '', 'id');
        if (!empty($stale)) {
            list($insql, $params) = $DB->get_in_or_equal(array_keys($stale));
            $DB->set_field_select('local_aichat_message', 'status', 'failed',
                "id $insql", $params);
            mtrace('local_aichat: ' . count($stale) . ' message(s) bloqué(s) libéré(s)');
        }

        // 2. Conservation des conversations.
        $days = (int)get_config('local_aichat', 'retentiondays');
        if ($days <= 0) {
            return; // conservation illimitée
        }
        $cutoff = time() - ($days * DAYSECS);

        $conversationids = $DB->get_fieldset_select('local_aichat_conversation', 'id',
            'timemodified < ?', array($cutoff));
        if (empty($conversationids)) {
            return;
        }
        // Par lots : une classe entière sur une année peut représenter beaucoup
        // de lignes, et get_in_or_equal a ses limites.
        foreach (array_chunk($conversationids, 500) as $chunk) {
            list($insql, $params) = $DB->get_in_or_equal($chunk);
            $DB->delete_records_select('local_aichat_message', "conversationid $insql", $params);
            $DB->delete_records_select('local_aichat_conversation', "id $insql", $params);
        }
        mtrace('local_aichat: ' . count($conversationids) . ' conversation(s) purgée(s)');
    }
}
