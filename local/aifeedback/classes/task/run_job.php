<?php
namespace local_aifeedback\task;

defined('MOODLE_INTERNAL') || die();

use local_aifeedback\jobs;

/**
 * Tâche ad-hoc PARTAGÉE par tous les consommateurs (assignfeedback_ai,
 * qtype_aiessay, qtype_aishortanswer, local_aiquizgen, local_aimissions,
 * local_aichat…).
 *
 * Elle ne fait plus attendre le cron pendant la génération : elle prépare la
 * demande puis la SOUMET au load balancer (job asynchrone), et rend la main.
 * Le résultat revient par callback (voir \local_aifeedback\jobs).
 *
 * Seules les activités configurées sur une API externe (surcharge d'URL)
 * sont encore appelées directement depuis cette tâche.
 *
 * Le custom_data porte un payload de la forme :
 *   { handler: 'assignfeedback_ai', payload: { ... } }
 * (format inchangé : les tâches déjà en file avant la mise à jour restent
 * valides). Le handler est résolu par '\{name}\job_handler' (autoload Moodle).
 */
class run_job extends \core\task\adhoc_task {

    public function get_name() {
        return get_string('taskname', 'local_aifeedback');
    }

    /**
     * Met un job en file pour le handler donné (sans effet si un job est déjà
     * en cours pour ce payload).
     *
     * @param string    $handlername nom frankenstyle du composant (ex: 'assignfeedback_ai')
     * @param \stdClass $payload     données libres pour le handler
     * @param int       $delaysec    délai minimal avant exécution (0 = dès que possible).
     */
    public static function enqueue($handlername, \stdClass $payload, $delaysec = 0) {
        jobs::enqueue((string)$handlername, $payload, (int)$delaysec);
    }

    /**
     * Ajoute une tâche à la file ad-hoc de Moodle, sans dédoublonnage.
     *
     * @param string    $handlername
     * @param \stdClass $payload
     * @param int       $delaysec
     */
    public static function queue($handlername, \stdClass $payload, $delaysec = 0) {
        $task = new self();
        $task->set_custom_data((object)array(
            'handler' => (string)$handlername,
            'payload' => $payload,
        ));
        if ((int)$delaysec > 0) {
            $task->set_next_run_time(time() + (int)$delaysec);
        }
        \core\task\manager::queue_adhoc_task($task);
    }

    public function execute() {
        $data    = $this->get_custom_data();
        $handler = isset($data->handler) ? (string)$data->handler : '';
        $payload = (isset($data->payload) && is_object($data->payload)) ? $data->payload : new \stdClass();
        if ($handler === '') {
            return;
        }

        $out = jobs::run($handler, $payload);
        if ($out['retry'] > 0) {
            mtrace("local_aifeedback: $handler — nouvel essai dans {$out['retry']} s");
            self::queue($handler, $payload, $out['retry']);
            return;
        }
        mtrace("local_aifeedback: $handler — {$out['outcome']}");
    }
}
