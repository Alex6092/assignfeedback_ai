<?php
namespace local_aifeedback\task;

defined('MOODLE_INTERNAL') || die();

use local_aifeedback\pool;

/**
 * Tâche ad-hoc PARTAGÉE par tous les consommateurs (assignfeedback_ai,
 * qtype_aiessay, qtype_aishortanswer, local_aiquizgen, local_aimissions,
 * local_aichat…).
 *
 * Deux modes, choisis par le réglage « Répartir la file sur le pool »
 * (local_aifeedback/pool_feedback, activé par défaut) :
 *
 *   - POOL : chaque job prend une place sur un des serveurs qui acceptent les
 *     corrections, la rend à la fin (un élève en attente du tuteur passe alors
 *     avant le job suivant) et bascule sur un autre serveur en cas de panne
 *     (voir api::call). Plusieurs processus cron peuvent travailler en
 *     parallèle : une réservation PAR JOB empêche qu'un même job soit traité
 *     deux fois — ce que garantissait jusqu'ici le verrou global.
 *     Les jobs visant une API externe imposée par l'activité (voir
 *     routable_job_handler) partent directement, sans place locale.
 *
 *   - HISTORIQUE : un seul appel LLM à la fois sur tout le site (verrou
 *     global), sur le serveur 1. Conservé comme retour arrière immédiat.
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

    /** Délai de re-tentative quand le lock global est pris / aucune place libre. */
    const REQUEUE_LOCK_BUSY = 30;

    /** Délai de re-tentative quand AUCUN serveur n'accepte les corrections. */
    const REQUEUE_NO_SERVER = 300;

    /**
     * Durée de vie maximale d'une réservation de job. N'intervient que si le
     * processus meurt en la tenant ET que le site utilise des verrous en base
     * (les verrous MySQL / PostgreSQL disparaissent avec la connexion). Doit
     * dépasser le pire cas d'un job : 3 serveurs × 180 s + attentes.
     */
    const CLAIM_MAXLIFETIME = 1200;

    /** Issues de run_one(). */
    const RUN_OK      = 'ok';
    const RUN_FAILED  = 'failed';
    const RUN_SKIPPED = 'skipped';
    const RUN_NOSLOT  = 'noslot';

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
        if (pool::feedback_enabled()) {
            $this->execute_pooled();
        } else {
            $this->execute_legacy();
        }
    }

    // =====================================================================
    //  MODE POOL
    // =====================================================================

    private function execute_pooled() {
        $data      = $this->get_custom_data();
        $myhandler = isset($data->handler) ? (string)$data->handler : '';
        $mypayload = isset($data->payload) ? $data->payload : new \stdClass();
        if ($myhandler === '') {
            return;
        }
        $handler = $this->instantiate_handler($myhandler);
        if ($handler === null) {
            mtrace("local_aifeedback: handler introuvable: $myhandler");
            return;
        }

        // 1) Le job demandé par cette tâche.
        $result = $this->run_one($handler, $mypayload, $myhandler);
        if ($result === self::RUN_NOSLOT) {
            $this->requeue();
            return;
        }
        if ($result !== self::RUN_OK) {
            // Échec (le handler a déjà fait son record_failure + requeue) ou
            // job réservé par un autre processus : rien de plus à faire ici.
            return;
        }

        // 2) Drainage : d'autres jobs en attente, chacun avec sa propre place
        //    (rendue entre deux jobs, pour laisser passer les élèves). On
        //    s'arrête dès qu'un job est tenu ailleurs ou qu'aucune place ne se
        //    libère : ces jobs ont leur propre tâche et seront repris.
        $deadline  = time() + self::BATCH_DEADLINE_SEC;
        $processed = 1;
        while ($processed < self::BATCH_LIMIT && time() < $deadline) {
            $extra = $handler->find_drainable_payloads();
            if (empty($extra)) {
                break;
            }
            if ($this->run_one($handler, reset($extra), $myhandler) !== self::RUN_OK) {
                break;
            }
            $processed++;
        }
        $processed += $this->drain_other_handlers(
            $myhandler, self::BATCH_LIMIT - $processed, $deadline, true);

        mtrace("local_aifeedback: tick terminé, $processed job(s) traités (handler=$myhandler)");
    }

    /**
     * Exécute UN job en mode pool : réservation du job, routage (API externe
     * ou serveur du pool), exécution, libération.
     *
     * @return string self::RUN_*
     */
    private function run_one(\local_aifeedback\job_handler $handler, \stdClass $payload, $handlername) {
        // Réservation du job : sans verrou global, deux processus cron
        // pourraient sinon traiter le même job (et, pour une API payante, le
        // facturer deux fois). Délai 0 : s'il est tenu, on passe notre tour.
        $factory = \core\lock\lock_config::get_lock_factory('local_aifeedback');
        $claim   = $factory->get_lock('job_' . sha1($handlername . '|' . json_encode($payload)),
            0, self::CLAIM_MAXLIFETIME);
        if (!$claim) {
            return self::RUN_SKIPPED;
        }

        try {
            // Job visant une API imposée par l'activité : il part directement,
            // sans occuper ni attendre un serveur du lycée.
            if ($this->external_endpoint($handler, $payload) !== null) {
                return $this->try_run($handler, $payload, $handlername)
                    ? self::RUN_OK : self::RUN_FAILED;
            }

            $wait = (int)get_config('local_aifeedback', 'pool_acquire_wait');
            $got  = pool::acquire(pool::PURPOSE_FEEDBACK, ($wait > 0) ? $wait : 10, $handlername);
            if ($got === null) {
                return self::RUN_NOSLOT;
            }

            pool::set_current($got['server'], $got['slotid'], pool::PURPOSE_FEEDBACK, $handlername);
            $ok = false;
            try {
                $ok = $this->try_run($handler, $payload, $handlername);
            } finally {
                // Le ticket courant peut avoir changé (basculement) : on libère
                // celui qui est tenu à la fin, les précédents l'ont déjà été.
                pool::release(pool::current_slot(), $ok ? 'done' : 'failed');
                pool::clear_current();
            }
            return $ok ? self::RUN_OK : self::RUN_FAILED;

        } finally {
            $claim->release();
        }
    }

    /**
     * URL externe imposée par l'activité pour ce job, ou null.
     */
    private function external_endpoint(\local_aifeedback\job_handler $handler, \stdClass $payload) {
        if (!($handler instanceof \local_aifeedback\routable_job_handler)) {
            return null;
        }
        try {
            $url = $handler->external_endpoint($payload);
        } catch (\Throwable $e) {
            return null; // dans le doute, on passe par le pool
        }
        return ($url === null || trim($url) === '') ? null : $url;
    }

    /**
     * Re-planifie cette tâche faute de place disponible.
     */
    private function requeue() {
        if (pool::has_server_for(pool::PURPOSE_FEEDBACK)) {
            $delay = self::REQUEUE_LOCK_BUSY;
            mtrace("local_aifeedback: aucun serveur libre, re-planification dans {$delay}s");
        } else {
            $delay = self::REQUEUE_NO_SERVER;
            mtrace("local_aifeedback: AUCUN serveur n'accepte les corrections (réglages du pool) : "
                . "re-planification dans {$delay}s");
        }
        $next = new self();
        $next->set_custom_data($this->get_custom_data());
        $next->set_next_run_time(time() + $delay);
        \core\task\manager::queue_adhoc_task($next);
    }

    // =====================================================================
    //  MODE HISTORIQUE (verrou global, serveur 1)
    // =====================================================================

    private function execute_legacy() {
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
                $myhandler, self::BATCH_LIMIT - $processed, $deadline, false);

            mtrace("local_aifeedback: tick terminé, $processed job(s) traités (handler=$myhandler)");

        } finally {
            $lock->release();
        }
    }

    // =====================================================================
    //  COMMUN
    // =====================================================================

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
     * exécute immédiatement. Retourne le nombre de jobs effectivement traités.
     *
     * On scanne la table métier {local_aifeedback_qgrading} plutôt que
     * {task_adhoc}, pour deux raisons :
     *   - robuste contre la concurrence du cron : si un autre worker a pris la
     *     task adhoc en parallèle (et l'a re-enqueuée plus tard), elle est
     *     invisible côté task_adhoc, mais la ligne métier, elle, reste en
     *     'pending' — on peut donc la consommer tout de suite ;
     *   - rattrape les 'pending' orphelins (cas où l'enqueue adhoc aurait
     *     échoué silencieusement par le passé).
     *
     * Les tasks adhoc "zombies" éventuellement laissées en queue ne posent pas
     * de problème : quand elles tournent, quiz_grader::execute() vérifie le
     * statut et fait no-op si la ligne n'est plus 'pending'.
     *
     * @param bool $pooled true = chaque job passe par run_one() (réservation,
     *                     routage, place sur le pool)
     */
    private function drain_other_handlers(string $myhandler, int $budget, int $deadline, bool $pooled): int {
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
            $ok = $pooled
                ? ($this->run_one($handler, $payload, (string)$row->component) === self::RUN_OK)
                : $this->try_run($handler, $payload, (string)$row->component);
            if (!$ok) {
                break; // erreur, job tenu ailleurs ou pas de place — on ne s'acharne pas
            }
            $processed++;
        }
        return $processed;
    }
}
