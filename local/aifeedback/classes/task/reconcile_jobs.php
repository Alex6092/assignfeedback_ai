<?php
namespace local_aifeedback\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Réconciliation périodique avec le load balancer : résultats dont le
 * callback n'est pas arrivé, traitements interrompus, travaux orphelins,
 * purge de l'historique. Voir \local_aifeedback\jobs::reconcile().
 */
class reconcile_jobs extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_reconcile', 'local_aifeedback');
    }

    public function execute() {
        \local_aifeedback\jobs::reconcile();
    }
}
