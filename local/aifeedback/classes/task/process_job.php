<?php
namespace local_aifeedback\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Application différée d'un résultat reçu du load balancer, quand le
 * traitement immédiat dans le callback a échoué (erreur passagère de base,
 * délai…). La réconciliation périodique reprend aussi ces lignes.
 */
class process_job extends \core\task\adhoc_task {

    public function get_name() {
        return get_string('task_process', 'local_aifeedback');
    }

    public function execute() {
        $data  = $this->get_custom_data();
        $rowid = isset($data->rowid) ? (int)$data->rowid : 0;
        if ($rowid > 0) {
            \local_aifeedback\jobs::process($rowid);
        }
    }
}
