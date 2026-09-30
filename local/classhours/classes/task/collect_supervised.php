<?php
namespace local_classhours\task;

defined('MOODLE_INTERNAL') || die();

use local_classhours\gate_collector;

/**
 * Ramassage du travail en cours d'une activité surveillée que l'enseignant
 * vient de fermer (données : cmid, closedat). Tourne comme administrateur.
 */
class collect_supervised extends \core\task\adhoc_task {

    public function get_name() {
        return get_string('task_collect_supervised', 'local_classhours');
    }

    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data->cmid)) {
            return;
        }
        $done = gate_collector::collect((int)$data->cmid, (int)($data->closedat ?? time()));
        mtrace('local_classhours : activité ' . (int)$data->cmid . ' — ' . $done['quiz'] . ' tentative(s) envoyée(s), '
            . $done['assign'] . ' brouillon(s) remis.');
    }
}
