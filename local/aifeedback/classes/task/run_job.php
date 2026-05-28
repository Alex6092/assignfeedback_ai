<?php
namespace local_aifeedback\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tâche ad-hoc PARTAGÉE par tous les consommateurs (assignfeedback_ai,
 * qtype_aiessay, qtype_aishortanswer, …). Garantit :
 *
 *   1. Un seul appel LLM en cours sur tout le site (lock global)
 *   2. Drainage de la queue tant qu'on tient le lock (max BATCH_LIMIT jobs
 *      par tick, max BATCH_DEADLINE_SEC de wall time)
 *   3. Re-tentative douce sur lock contention (pas de faildelay Moodle)
 *
 * Le custom_data porte un payload de la forme :
 *   { handler: 'assignfeedback_ai', payload: { ... } }
 *
 * Le handler est résolu par '\{name}\job_handler' (autoload Moodle).
 */
class run_job extends \core\task\adhoc_task {

    /** Nombre max de jobs traités dans un seul tick. */
    const BATCH_LIMIT = 20;

    /** Wall time max pour un tick de drainage (en secondes). */
    const BATCH_DEADLINE_SEC = 540;

    /** Délai de re-tentative quand le lock global est déjà pris. */
    const REQUEUE_LOCK_BUSY = 30;

    public function get_name() {
        return get_string('taskname', 'local_aifeedback');
    }

    /**
     * Enqueue un job pour le handler donné.
     *
     * @param string    $handlername nom frankenstyle du composant (ex: 'assignfeedback_ai')
     * @param \stdClass $payload     données libres pour le handler
     */
    public static function enqueue($handlername, \stdClass $payload) {
        $task = new self();
        $task->set_custom_data((object)array(
            'handler' => (string)$handlername,
            'payload' => $payload,
        ));
        \core\task\manager::queue_adhoc_task($task);
    }

    public function execute() {
        global $CFG;

        // Acquisition du lock global (un seul appel LLM en cours sur tout le site).
        $factory = \core\lock\lock_config::get_lock_factory('local_aifeedback');
        $lock    = $factory->get_lock('llm_call', 5);
        if (!$lock) {
            // Un autre tick draine déjà : on se re-planifie sans faildelay.
            $next = new self();
            $next->set_custom_data($this->get_custom_data());
            $next->set_next_run_time(time() + self::REQUEUE_LOCK_BUSY);
            \core\task\manager::queue_adhoc_task($next);
            mtrace("local_aifeedback: lock occupé, re-planification dans "
                . self::REQUEUE_LOCK_BUSY . "s");
            return;
        }

        try {
            $data   = $this->get_custom_data();
            $myhandler = isset($data->handler) ? (string)$data->handler : '';
            $mypayload = isset($data->payload) ? $data->payload : new \stdClass();
            if ($myhandler === '') {
                return;
            }

            // 1) Le job demandé par cette tâche.
            $handler = $this->instantiate_handler($myhandler);
            if ($handler === null) {
                mtrace("local_aifeedback: handler introuvable: $myhandler");
                return;
            }
            $ok = $this->try_run($handler, $mypayload, $myhandler);
            if (!$ok) {
                // Erreur transitoire : on s'arrête là pour laisser le retry classique
                // (le handler a déjà fait son record_failure + requeue côté métier).
                return;
            }

            // 2) Drainage : on enchaîne d'autres jobs du même handler tant
            //    qu'il en signale, puis on jette un œil aux ad-hoc en attente
            //    pour les autres handlers.
            $deadline  = time() + self::BATCH_DEADLINE_SEC;
            $processed = 1;

            // 2a) Drain du handler courant.
            while ($processed < self::BATCH_LIMIT && time() < $deadline) {
                $extra = $handler->find_drainable_payloads();
                if (empty($extra)) {
                    break;
                }
                $next = reset($extra);
                if (!$this->try_run($handler, $next, $myhandler)) {
                    break;
                }
                $processed++;
            }

            mtrace("local_aifeedback: tick terminé, $processed job(s) traités (handler=$myhandler)");

        } finally {
            $lock->release();
        }
    }

    /**
     * Instancie l'implémentation de job_handler du composant donné.
     */
    private function instantiate_handler($component) {
        $class = '\\' . $component . '\\job_handler';
        if (!class_exists($class)) {
            return null;
        }
        $instance = new $class();
        if (!($instance instanceof \local_aifeedback\job_handler)) {
            debugging("local_aifeedback: $class n'implémente pas job_handler", DEBUG_DEVELOPER);
            return null;
        }
        return $instance;
    }

    /**
     * Exécute un job. Retourne true sur succès, false sur erreur (le handler
     * gère ses propres compteurs).
     */
    private function try_run(\local_aifeedback\job_handler $handler, \stdClass $payload, $handlername) {
        try {
            $handler->execute($payload);
            return true;
        } catch (\Throwable $e) {
            mtrace("local_aifeedback: échec job ($handlername): " . $e->getMessage());
            return false;
        }
    }
}
