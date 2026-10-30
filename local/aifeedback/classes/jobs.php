<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

use local_aifeedback\lb\client;
use local_aifeedback\lb\lb_exception;

/**
 * File de générations partagée par tous les plugins IA (corrections de
 * devoirs et de questions, génération de quiz, missions, modération et brief
 * du tuteur…), adossée au LOAD BALANCER d'inférence.
 *
 * Moodle ne répartit plus rien lui-même : il soumet des jobs asynchrones au
 * load balancer et applique leur résultat quand il revient.
 *
 *   enqueue() ──► tâche ad-hoc run_job ──► run()
 *                    │  build_request() du handler
 *                    ├─ API externe imposée : appel direct, puis complete()
 *                    └─ sinon : POST /v1/jobs (callbackURL) → ligne « submitted »
 *
 *   callback.php ──► receive() (résultat enregistré, idempotent)
 *                └─► process() → complete() du handler (note, feedback…)
 *                                └─ étape suivante éventuelle → run()
 *
 *   tâche reconcile_jobs (5 min) : filet de sécurité si un callback n'arrive
 *   pas (Moodle injoignable, secret erroné…) — interroge le load balancer.
 *
 * Une ligne {local_aifeedback_job} suit chaque soumission :
 *   submitting → submitted → received → processing → done | failed | cancelled
 */
class jobs {

    /** Table de suivi des jobs soumis au load balancer. */
    const TABLE = 'local_aifeedback_job';

    /** Statuts d'un job encore en vie (un seul par payload). */
    const ACTIVE_STATUSES = array('submitting', 'submitted', 'received', 'processing');

    /** Soumissions maximales d'une même ligne (job perdu ou expiré côté load balancer). */
    const MAX_SUBMISSIONS = 3;

    /** Nouvel essai après un refus passager du load balancer (secondes). */
    const RETRY_TRANSIENT = 60;

    /** Nouvel essai quand le load balancer n'est pas encore configuré (secondes). */
    const RETRY_NOTCONFIGURED = 300;

    /** Délais de la réconciliation (secondes). */
    const RECONCILE_SUBMITTED  = 600;
    const RECONCILE_RECEIVED   = 300;
    const RECONCILE_ORPHANS    = 900;
    const PURGE_AFTER          = 30 * DAYSECS;

    /** Issues de run(). */
    const RUN_SUBMITTED = 'submitted';
    const RUN_DONE      = 'done';
    const RUN_SKIPPED   = 'skipped';
    const RUN_FAILED    = 'failed';
    const RUN_RETRY     = 'retry';

    // =====================================================================
    //  MISE EN FILE
    // =====================================================================

    /**
     * Met en file une génération. Sans effet si un job est déjà en cours pour
     * ce même payload (double clic, relance pendant la génération…).
     *
     * @param string    $component frankenstyle du handler (ex. 'assignfeedback_ai')
     * @param \stdClass $payload   données du handler (identifiants)
     * @param int       $delay     délai minimal avant traitement (secondes)
     * @return bool false si un job était déjà en cours
     */
    public static function enqueue($component, \stdClass $payload, $delay = 0) {
        if (self::active_job($component, $payload) !== null) {
            return false;
        }
        task\run_job::queue($component, $payload, (int)$delay);
        return true;
    }

    /**
     * Relance une génération en REMPLAÇANT celle qui serait en cours (copie
     * remise à nouveau, relance demandée par l'enseignant) : le job encore au
     * load balancer est annulé, son résultat éventuel sera ignoré.
     *
     * @param string    $component
     * @param \stdClass $payload
     * @param int       $delay
     */
    public static function restart($component, \stdClass $payload, $delay = 0) {
        $active = self::active_job($component, $payload);
        if ($active !== null && in_array($active->status, array('submitting', 'submitted'), true)) {
            if ((string)$active->lbjobid !== '') {
                try {
                    client::cancel_job((string)$active->lbjobid);
                } catch (\Throwable $e) {
                    // Au pire, le load balancer termine un calcul inutile.
                    debugging('local_aifeedback: annulation impossible — ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            self::finish($active, 'cancelled', null);
        }
        task\run_job::queue($component, $payload, (int)$delay);
    }

    /** Empreinte d'un payload (dédoublonnage). */
    public static function payload_key($component, \stdClass $payload) {
        return sha1((string)$component . '|' . json_encode($payload));
    }

    /**
     * Job en vie pour ce payload, ou null.
     *
     * @return \stdClass|null
     */
    public static function active_job($component, \stdClass $payload) {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal(self::ACTIVE_STATUSES);
        array_unshift($params, (string)$component, self::payload_key($component, $payload));
        $row = $DB->get_record_select(self::TABLE,
            'component = ? AND payloadkey = ? AND status ' . $insql, $params, '*', IGNORE_MULTIPLE);
        return $row ?: null;
    }

    /**
     * Handler du composant, ou null.
     *
     * @param string $component
     * @return async_handler|null
     */
    public static function handler($component) {
        if (!preg_match('/^[a-z][a-z0-9]*_[a-z0-9_]+$/', (string)$component)) {
            return null;
        }
        $class = '\\' . $component . '\\job_handler';
        if (!class_exists($class)) {
            return null;
        }
        $handler = new $class();
        return ($handler instanceof async_handler) ? $handler : null;
    }

    // =====================================================================
    //  SOUMISSION
    // =====================================================================

    /**
     * Traite un payload (tâche run_job) : prépare la demande, puis la soumet au
     * load balancer, ou l'exécute directement si l'activité impose une API
     * externe.
     *
     * @param string    $component
     * @param \stdClass $payload
     * @return array ['outcome' => self::RUN_*, 'retry' => délai de replanification (0 = aucune)]
     */
    public static function run($component, \stdClass $payload) {
        $handler = self::handler($component);
        if ($handler === null) {
            debugging('local_aifeedback: handler introuvable pour ' . $component, DEBUG_DEVELOPER);
            return self::outcome(self::RUN_SKIPPED);
        }

        // Un seul processus à la fois pour un même payload : sans cela, deux
        // tâches pourraient soumettre (et faire facturer) la même génération.
        $factory = \core\lock\lock_config::get_lock_factory('local_aifeedback');
        $lock    = $factory->get_lock('job_' . self::payload_key($component, $payload), 0, 600);
        if (!$lock) {
            return self::outcome(self::RUN_SKIPPED);
        }

        try {
            $active = self::active_job($component, $payload);
            if ($active !== null && $active->status !== 'submitting') {
                return self::outcome(self::RUN_SKIPPED); // déjà au load balancer
            }

            try {
                $request = $handler->build_request($payload);
            } catch (\Throwable $e) {
                if ($active !== null) {
                    self::finish($active, 'failed', self::describe($e));
                }
                return self::handle_failure($handler, $component, $payload, self::describe($e), false);
            }
            if ($request === null) {
                if ($active !== null) {
                    self::finish($active, 'cancelled', null);
                }
                return self::outcome(self::RUN_SKIPPED);
            }

            if ($request->is_direct()) {
                return self::run_direct($handler, $component, $payload, $request);
            }
            return self::submit($handler, $component, $payload, $request, $active);
        } finally {
            $lock->release();
        }
    }

    /**
     * Activité configurée sur une API externe : l'appel part directement, hors
     * load balancer, depuis la tâche de fond (comme avant).
     */
    private static function run_direct(async_handler $handler, $component, \stdClass $payload, request $request) {
        try {
            $result = api::call($request->messages, $request->call_options());
            $next   = $handler->complete($payload, $result, null);
        } catch (\Throwable $e) {
            return self::handle_failure($handler, $component, $payload, self::describe($e), false);
        }
        if ($next !== null) {
            self::enqueue($component, $next);
        }
        return self::outcome(self::RUN_DONE);
    }

    /**
     * Soumet la demande au load balancer (POST /v1/jobs).
     *
     * @param \stdClass|null $row ligne « submitting » à resoumettre (même clé
     *                            d'idempotence : le load balancer rend le job
     *                            déjà créé si la réponse précédente s'est perdue)
     */
    private static function submit(async_handler $handler, $component, \stdClass $payload,
            request $request, $row = null) {
        global $DB;

        try {
            $built = api::build_request($request->messages, $request->call_options());
        } catch (\moodle_exception $e) {
            if (in_array($e->errorcode, array('lb_notconfigured', 'lb_nomodel'), true)) {
                // Configuration incomplète : on attend l'administrateur sans
                // compter d'échec.
                return self::outcome(self::RUN_RETRY, self::RETRY_NOTCONFIGURED);
            }
            if ($row !== null) {
                self::finish($row, 'failed', self::describe($e));
            }
            return self::handle_failure($handler, $component, $payload, self::describe($e), true);
        }

        $now = time();
        if ($row === null) {
            $row = (object)array(
                'component'        => (string)$component,
                'payload'          => json_encode($payload),
                'payloadkey'       => self::payload_key($component, $payload),
                'userid'           => (int)$request->userid,
                'model'            => (string)$built['model'],
                'lbjobid'          => '',
                'lbstatus'         => '',
                'eventid'          => '',
                'status'           => 'submitting',
                'attempt'          => 1,
                'expectjson'       => $request->expects_json() ? 1 : 0,
                'result'           => null,
                'error'            => null,
                'prompttokens'     => 0,
                'completiontokens' => 0,
                'timecreated'      => $now,
                'timesubmitted'    => 0,
                'timecompleted'    => 0,
                'timemodified'     => $now,
            );
            $row->id = (int)$DB->insert_record(self::TABLE, $row);
        }

        $body = $built['payload'];
        $body['callbackURL'] = client::callback_url((int)$row->id);
        try {
            $job = client::submit_job($body, (int)$request->userid,
                client::idempotency_key((int)$row->id, (int)$row->attempt));
        } catch (lb_exception $e) {
            if ($e->transient) {
                // Quota, file saturée, service injoignable : la ligne reste
                // « submitting » et la même demande sera renvoyée plus tard.
                $DB->update_record(self::TABLE, (object)array(
                    'id'           => (int)$row->id,
                    'error'        => self::describe($e),
                    'timemodified' => time(),
                ));
                $delay = ($e->retryafter > 0) ? min($e->retryafter + 5, 6 * HOURSECS) : self::RETRY_TRANSIENT;
                return self::outcome(self::RUN_RETRY, $delay);
            }
            self::finish($row, 'failed', self::describe($e));
            return self::handle_failure($handler, $component, $payload, self::describe($e), true);
        }

        // Le callback a pu arriver avant cette réponse : il a alors déjà
        // renseigné le job et changé le statut (mise à jour conditionnelle).
        $DB->execute('UPDATE {' . self::TABLE . '}
                         SET lbjobid = ?, status = ?, error = NULL, timesubmitted = ?, timemodified = ?
                       WHERE id = ? AND status = ?',
            array(isset($job['id']) ? (string)$job['id'] : '', 'submitted', time(), time(),
                (int)$row->id, 'submitting'));
        return self::outcome(self::RUN_SUBMITTED);
    }

    // =====================================================================
    //  RÉCEPTION DES RÉSULTATS
    // =====================================================================

    /**
     * Enregistre durablement le résultat d'un job (callback du load balancer
     * ou réconciliation). Idempotent : un même résultat reçu deux fois n'est
     * enregistré qu'une fois.
     *
     * @param int   $rowid ligne {local_aifeedback_job}
     * @param array $event {id, job_id, status, result, error}
     * @return string 'received' (à traiter), 'duplicate', 'mismatch' ou 'unknown'
     */
    public static function receive($rowid, array $event) {
        global $DB;

        $row = $DB->get_record(self::TABLE, array('id' => (int)$rowid));
        if (!$row) {
            return 'unknown'; // ligne purgée : rien à faire
        }
        $jobid = isset($event['job_id']) ? (string)$event['job_id'] : '';
        if ((string)$row->lbjobid !== '' && $jobid !== (string)$row->lbjobid) {
            return 'mismatch'; // ancienne soumission remplacée depuis
        }
        $status = isset($event['status']) ? (string)$event['status'] : '';
        if (!in_array($status, array('succeeded', 'failed', 'cancelled', 'expired'), true)) {
            return 'duplicate';
        }
        if (!in_array($row->status, array('submitting', 'submitted'), true)) {
            return 'duplicate';
        }

        $result = isset($event['result']) ? $event['result'] : null;
        $usage  = (is_array($result) && isset($result['usage']) && is_array($result['usage']))
            ? $result['usage'] : array();
        $error  = (isset($event['error']) && $event['error'] !== null)
            ? json_encode($event['error'], JSON_UNESCAPED_UNICODE) : null;
        $eventid = isset($event['id']) ? substr((string)$event['id'], 0, 64) : '';
        $now     = time();

        // Une seule requête, conditionnelle : de deux réceptions simultanées
        // (callback et réconciliation), une seule passe.
        $DB->execute('UPDATE {' . self::TABLE . '}
                         SET status = ?, lbjobid = ?, lbstatus = ?, eventid = ?, result = ?, error = ?,
                             prompttokens = ?, completiontokens = ?, timecompleted = ?, timemodified = ?
                       WHERE id = ? AND status IN (?, ?)',
            array('received', $jobid, $status, $eventid,
                ($result === null) ? null : json_encode($result, JSON_UNESCAPED_UNICODE), $error,
                isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0,
                isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0,
                $now, $now, (int)$row->id, 'submitting', 'submitted'));

        $fresh = $DB->get_record(self::TABLE, array('id' => (int)$row->id), 'id, status, eventid');
        return ($fresh && $fresh->status === 'received' && (string)$fresh->eventid === $eventid)
            ? 'received' : 'duplicate';
    }

    /**
     * Applique un résultat reçu : analyse de la réponse, puis complete() du
     * handler (note, feedback, questions…). Sur échec, le handler décide d'un
     * nouvel essai.
     *
     * @param int $rowid
     * @return bool false si la ligne est déjà traitée ou tenue par un autre processus
     */
    public static function process($rowid) {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_aifeedback');
        $lock    = $factory->get_lock('lbjob_' . (int)$rowid, 0, 900);
        if (!$lock) {
            return false;
        }

        try {
            $row = $DB->get_record(self::TABLE, array('id' => (int)$rowid));
            if (!$row || !in_array($row->status, array('received', 'processing'), true)) {
                return false;
            }
            $DB->update_record(self::TABLE, (object)array(
                'id' => (int)$row->id, 'status' => 'processing', 'timemodified' => time()));
            self::setup_user();

            $payload = json_decode((string)$row->payload);
            if (!is_object($payload)) {
                $payload = new \stdClass();
            }
            $handler = self::handler((string)$row->component);
            if ($handler === null) {
                self::finish($row, 'failed', 'handler introuvable : ' . $row->component);
                return true;
            }

            if ($row->lbstatus !== 'succeeded') {
                $error = self::event_error($row);
                self::finish($row, ($row->lbstatus === 'cancelled') ? 'cancelled' : 'failed', $error);
                self::handle_failure($handler, (string)$row->component, $payload, $error,
                    $row->lbstatus === 'cancelled');
                return true;
            }

            try {
                $response = json_decode((string)$row->result, true);
                $options  = !empty($row->expectjson)
                    ? array('response_format' => array('type' => 'json_schema')) : array();
                $result   = api::parse_completion(is_array($response) ? $response : array(), $options,
                    '[model=' . $row->model . ', job=' . $row->lbjobid . ']');
                $usage    = array(
                    'prompt_tokens'     => (int)$row->prompttokens,
                    'completion_tokens' => (int)$row->completiontokens,
                );
                $next = $handler->complete($payload, $result, $usage);
            } catch (\Throwable $e) {
                self::finish($row, 'failed', self::describe($e));
                self::handle_failure($handler, (string)$row->component, $payload, self::describe($e), false);
                return true;
            }

            self::finish($row, 'done', null);
            if ($next !== null) {
                self::continue_with((string)$row->component, $next);
            }
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Étape suivante d'un job à plusieurs générations : soumise aussitôt,
     * ou confiée à la file si la soumission immédiate échoue.
     */
    private static function continue_with($component, \stdClass $next) {
        try {
            $out = self::run($component, $next);
            if ($out['outcome'] === self::RUN_SKIPPED && self::active_job($component, $next) === null) {
                // Tenu par un autre processus : la file le reprendra.
                self::enqueue($component, $next, self::RETRY_TRANSIENT);
            } else if ($out['retry'] > 0) {
                task\run_job::queue($component, $next, $out['retry']);
            }
        } catch (\Throwable $e) {
            debugging('local_aifeedback: étape suivante différée — ' . $e->getMessage(), DEBUG_DEVELOPER);
            self::enqueue($component, $next);
        }
    }

    /**
     * Traitement d'un résultat reçu, confié à une tâche de fond (échec du
     * traitement immédiat dans le callback).
     */
    public static function schedule_process($rowid) {
        $task = new task\process_job();
        $task->set_custom_data((object)array('rowid' => (int)$rowid));
        \core\task\manager::queue_adhoc_task($task, true);
    }

    // =====================================================================
    //  RÉCONCILIATION (tâche planifiée)
    // =====================================================================

    /**
     * Filet de sécurité : récupère les résultats dont le callback n'est pas
     * arrivé, termine les traitements interrompus, remet en file les travaux
     * orphelins et purge l'historique.
     *
     * @param bool $verbose écrire un compte rendu (mtrace)
     */
    public static function reconcile($verbose = true) {
        global $DB;
        $now = time();
        $log = function($msg) use ($verbose) {
            if ($verbose) {
                mtrace('local_aifeedback: ' . $msg);
            }
        };

        // 1) Jobs soumis sans nouvelles : on interroge le load balancer.
        if (client::configured()) {
            $rows = $DB->get_records_select(self::TABLE, 'status = ? AND timesubmitted < ?',
                array('submitted', $now - self::RECONCILE_SUBMITTED), 'timesubmitted ASC', '*', 0, 200);
            foreach ($rows as $row) {
                try {
                    $job = client::get_job((string)$row->lbjobid);
                } catch (lb_exception $e) {
                    if ($e->httpstatus === 404) {
                        $log('job ' . $row->lbjobid . ' inconnu du load balancer, nouvelle soumission');
                        self::resubmit($row, 'job introuvable sur le load balancer');
                        continue;
                    }
                    $log('load balancer injoignable : ' . $e->getMessage());
                    break; // inutile d'insister pour les suivants
                }
                $status = isset($job['status']) ? (string)$job['status'] : '';
                if (!in_array($status, array('succeeded', 'failed', 'cancelled', 'expired'), true)) {
                    continue; // toujours en file ou en cours
                }
                if ($status === 'succeeded' && (!empty($job['content_expired']) || empty($job['result']))) {
                    self::resubmit($row, 'résultat expiré sur le load balancer');
                    continue;
                }
                $outcome = self::receive((int)$row->id, array(
                    'id'     => 'poll-' . $row->lbjobid,
                    'job_id' => (string)$row->lbjobid,
                    'status' => $status,
                    'result' => isset($job['result']) ? $job['result'] : null,
                    'error'  => isset($job['error']) ? $job['error'] : null,
                ));
                if ($outcome === 'received') {
                    $log('résultat récupéré sans callback pour le job ' . $row->lbjobid);
                    self::process((int)$row->id);
                }
            }

            // 2) Soumissions restées en suspens (réponse perdue, tâche perdue) :
            //    renvoyées avec la même clé d'idempotence.
            $rows = $DB->get_records_select(self::TABLE, 'status = ? AND timemodified < ?',
                array('submitting', $now - self::RECONCILE_SUBMITTED), 'id ASC', '*', 0, 100);
            foreach ($rows as $row) {
                $payload = json_decode((string)$row->payload);
                if (is_object($payload)) {
                    $out = self::run((string)$row->component, $payload);
                    if ($out['retry'] > 0) {
                        task\run_job::queue((string)$row->component, $payload, $out['retry']);
                    }
                }
            }
        }

        // 3) Résultats reçus mais pas (complètement) appliqués.
        list($insql, $params) = $DB->get_in_or_equal(array('received', 'processing'));
        $params[] = $now - self::RECONCILE_RECEIVED;
        $ids = $DB->get_fieldset_select(self::TABLE, 'id', 'status ' . $insql . ' AND timemodified < ?', $params);
        foreach ($ids as $id) {
            try {
                self::process((int)$id);
            } catch (\Throwable $e) {
                $log('échec du traitement du job ' . $id . ' : ' . $e->getMessage());
            }
        }

        // 4) Travaux métier en attente sans job actif (mise en file perdue).
        foreach (self::handlers() as $component => $handler) {
            try {
                $payloads = $handler->pending_payloads($now - self::RECONCILE_ORPHANS);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($payloads as $payload) {
                if (self::enqueue($component, $payload)) {
                    $log('travail orphelin remis en file (' . $component . ')');
                }
            }
        }

        // 5) Purge de l'historique (le résultat est effacé dès l'application).
        list($insql, $params) = $DB->get_in_or_equal(array('done', 'failed', 'cancelled'));
        $params[] = $now - self::PURGE_AFTER;
        $DB->delete_records_select(self::TABLE, 'status ' . $insql . ' AND timemodified < ?', $params);
    }

    /**
     * Soumet à nouveau une ligne dont le job a été perdu ou a expiré côté load
     * balancer, avec une nouvelle clé d'idempotence.
     */
    private static function resubmit(\stdClass $row, $why) {
        global $DB;
        $payload = json_decode((string)$row->payload);
        if (!is_object($payload)) {
            $payload = new \stdClass();
        }
        if ((int)$row->attempt >= self::MAX_SUBMISSIONS) {
            self::finish($row, 'failed', $why);
            $handler = self::handler((string)$row->component);
            if ($handler !== null) {
                self::handle_failure($handler, (string)$row->component, $payload, $why, false);
            }
            return;
        }
        $DB->update_record(self::TABLE, (object)array(
            'id'           => (int)$row->id,
            'status'       => 'submitting',
            'attempt'      => (int)$row->attempt + 1,
            'lbjobid'      => '',
            'error'        => $why,
            'timemodified' => time(),
        ));
        $out = self::run((string)$row->component, $payload);
        if ($out['retry'] > 0) {
            task\run_job::queue((string)$row->component, $payload, $out['retry']);
        }
    }

    /**
     * Handlers installés (plugins locaux, types de question, feedbacks de devoir).
     *
     * @return async_handler[] indexés par composant
     */
    public static function handlers() {
        $out = array();
        foreach (array('local', 'qtype', 'assignfeedback') as $type) {
            foreach (array_keys(\core_component::get_plugin_list($type)) as $name) {
                $component = $type . '_' . $name;
                $handler   = self::handler($component);
                if ($handler !== null) {
                    $out[$component] = $handler;
                }
            }
        }
        return $out;
    }

    // =====================================================================
    //  STATISTIQUES (page d'état)
    // =====================================================================

    /**
     * Nombre de jobs par composant et par statut, créés depuis $since.
     *
     * @return array component => status => n
     */
    public static function stats($since) {
        global $DB;
        $rs = $DB->get_recordset_sql('SELECT component, status, COUNT(*) AS n
                                        FROM {' . self::TABLE . '}
                                       WHERE timecreated >= ?
                                    GROUP BY component, status', array((int)$since));
        $out = array();
        foreach ($rs as $r) {
            $out[(string)$r->component][(string)$r->status] = (int)$r->n;
        }
        $rs->close();
        return $out;
    }

    /**
     * Tokens consommés par les jobs terminés depuis $since.
     *
     * @return \stdClass {prompt, completion}
     */
    public static function token_totals($since) {
        global $DB;
        $r = $DB->get_record_sql('SELECT COALESCE(SUM(prompttokens), 0) AS prompt,
                                         COALESCE(SUM(completiontokens), 0) AS completion
                                    FROM {' . self::TABLE . '}
                                   WHERE timecompleted >= ?', array((int)$since));
        return (object)array('prompt' => (int)$r->prompt, 'completion' => (int)$r->completion);
    }

    /**
     * Échecs récents des jobs de ce payload (pour les handlers qui n'ont pas
     * leur propre compteur de tentatives).
     *
     * @param string    $component
     * @param \stdClass $payload
     * @param int       $since
     * @return int
     */
    public static function recent_failures($component, \stdClass $payload, $since) {
        global $DB;
        return (int)$DB->count_records_select(self::TABLE,
            'component = ? AND payloadkey = ? AND status = ? AND timemodified >= ?',
            array((string)$component, self::payload_key($component, $payload), 'failed', (int)$since));
    }

    /**
     * Dernières erreurs.
     *
     * @return \stdClass[]
     */
    public static function recent_errors($limit = 10) {
        global $DB;
        return $DB->get_records_select(self::TABLE, 'error IS NOT NULL AND status IN (?, ?, ?)',
            array('failed', 'cancelled', 'submitting'), 'timemodified DESC',
            'id, component, status, model, lbjobid, error, timemodified', 0, (int)$limit);
    }

    /**
     * Jobs en vie, les plus anciens d'abord.
     *
     * @return \stdClass[]
     */
    public static function oldest_active($limit = 10) {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal(self::ACTIVE_STATUSES);
        return $DB->get_records_select(self::TABLE, 'status ' . $insql, $params, 'timecreated ASC',
            'id, component, status, model, lbjobid, attempt, timecreated, timesubmitted', 0, (int)$limit);
    }

    // =====================================================================
    //  OUTILS
    // =====================================================================

    /**
     * Échec : le handler met à jour ses compteurs et décide d'un nouvel essai.
     */
    private static function handle_failure(async_handler $handler, $component, \stdClass $payload,
            $error, $permanent) {
        try {
            $delay = $handler->fail($payload, (string)$error, (bool)$permanent);
        } catch (\Throwable $e) {
            debugging('local_aifeedback: fail() a échoué — ' . $e->getMessage(), DEBUG_DEVELOPER);
            $delay = null;
        }
        if ($delay !== null) {
            task\run_job::queue($component, $payload, max(0, (int)$delay));
        }
        return self::outcome(self::RUN_FAILED);
    }

    /** Statut final d'une ligne. Le résultat brut n'est pas conservé au-delà. */
    private static function finish(\stdClass $row, $status, $error) {
        global $DB;
        $update = (object)array(
            'id'           => (int)$row->id,
            'status'       => (string)$status,
            'timemodified' => time(),
            'result'       => null,
        );
        if ($error !== null) {
            $update->error = (string)$error;
        }
        $DB->update_record(self::TABLE, $update);
    }

    /** Message lisible de l'erreur d'un job terminé en échec. */
    private static function event_error(\stdClass $row) {
        $err = json_decode((string)$row->error, true);
        $msg = (is_array($err) && isset($err['message'])) ? (string)$err['message'] : '';
        $code = (is_array($err) && isset($err['code'])) ? (string)$err['code'] : '';
        return get_string('lb_jobfailed', 'local_aifeedback', (object)array(
            'status'  => (string)$row->lbstatus,
            'message' => trim(($code !== '' ? '[' . $code . '] ' : '') . $msg),
        ));
    }

    /** Message + détail technique d'une exception. */
    public static function describe(\Throwable $e) {
        $msg = $e->getMessage();
        if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
            $msg .= ' — ' . $e->debuginfo;
        }
        return $msg;
    }

    /**
     * Les handlers appliquent les résultats hors de toute session : on agit
     * comme le cron, sous l'administrateur. Une page déjà connectée (bouton
     * d'administration) garde son utilisateur.
     */
    private static function setup_user() {
        if (!CLI_SCRIPT && isloggedin() && !isguestuser()) {
            return;
        }
        if (class_exists('\core\cron') && method_exists('\core\cron', 'setup_user')) {
            \core\cron::setup_user();
        } else if (function_exists('cron_setup_user')) {
            cron_setup_user();
        }
    }

    /** @return array ['outcome' => string, 'retry' => int] */
    private static function outcome($outcome, $retry = 0) {
        return array('outcome' => $outcome, 'retry' => (int)$retry);
    }
}
