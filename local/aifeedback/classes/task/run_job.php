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
     * @param int       $delaysec    délai minimal avant exécution (0 = dès que possible).
     *                               Le cron Moodle saute la tâche tant que son nextruntime
     *                               n'est pas atteint : permet une exécution DIFFÉRÉE.
     */
    public static function enqueue($handlername, \stdClass $payload, $delaysec = 0) {
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

            // 2b) Drain cross-handler : on consomme aussi les autres run_job
            //     adhoc en attente d'AUTRES handlers IA (ex. aishortanswer
            //     juste après aiessay), tant qu'on tient le lock LLM. Évite
            //     d'attendre un tick de cron complet entre deux questions IA
            //     d'une même tentative de quiz.
            $processed += $this->drain_other_handlers(
                $myhandler, self::BATCH_LIMIT - $processed, $deadline);

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

    /**
     * Cherche d'autres jobs IA en attente pour des handlers DIFFÉRENTS et les
     * exécute immédiatement (on tient déjà le lock LLM). Retourne le nombre de
     * jobs effectivement traités ici.
     *
     * On scanne la table métier {local_aifeedback_qgrading} plutôt que
     * {task_adhoc}, pour deux raisons :
     *   - robuste contre la concurrence du cron : si un autre worker a pris la
     *     task adhoc en parallèle (et l'a re-enqueuée à +30s sur lock busy),
     *     elle est invisible côté task_adhoc, mais la ligne métier, elle,
     *     reste en 'pending' — on peut donc la consommer tout de suite ;
     *   - rattrape les 'pending' orphelins (cas où l'enqueue adhoc aurait
     *     échoué silencieusement par le passé).
     *
     * Les tasks adhoc "zombies" éventuellement laissées en queue ne posent pas
     * de problème : quand elles tournent, quiz_grader::execute() vérifie le
     * statut et fait no-op si la ligne n'est plus 'pending'.
     */
    private function drain_other_handlers(string $myhandler, int $budget, int $deadline): int {
        global $DB;
        if ($budget <= 0) {
            return 0;
        }
        $processed = 0;

        while ($processed < $budget && time() < $deadline) {
            $row = $DB->get_record_sql("
                SELECT id, component
                  FROM {local_aifeedback_qgrading}
                 WHERE status = ?
                   AND component <> ?
              ORDER BY timecreated ASC",
                array('pending', $myhandler),
                IGNORE_MULTIPLE
            );
            if (!$row) {
                break;
            }

            $handler = $this->instantiate_handler((string)$row->component);
            if ($handler === null) {
                // Handler manquant pour ce composant : on évite de boucler.
                mtrace('local_aifeedback: handler introuvable pour drainage: ' . $row->component);
                break;
            }

            $payload = new \stdClass();
            $payload->rowid = (int)$row->id;
            if (!$this->try_run($handler, $payload, (string)$row->component)) {
                break; // erreur transitoire — on ne s'acharne pas
            }
            $processed++;
        }
        return $processed;
    }
}
