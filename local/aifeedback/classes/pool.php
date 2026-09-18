<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Pool de serveurs LLM partagé par tous les usages (correction différée et
 * tuteur interactif).
 *
 * Problème résolu : un serveur LM Studio ne traite en général qu'UNE requête à
 * la fois. Sans régulation, deux élèves qui posent une question en même temps
 * (ou une correction en cours) se retrouvent en concurrence et le serveur
 * s'effondre ou fait attendre tout le monde sans rien dire.
 *
 * Principe :
 *   - l'administrateur déclare jusqu'à 3 serveurs, chacun avec sa simultanéité
 *     maximale et les usages qu'il accepte (« feedback » et/ou « tuteur ») ;
 *   - tout appel LLM régulé prend d'abord un TICKET (table ..._slot) ;
 *   - admit() est la seule section critique : sous verrou, elle répare les
 *     tickets abandonnés puis affecte les tickets en attente aux serveurs qui
 *     ont de la place, en servant le TUTEUR EN PREMIER (un élève attend devant
 *     son écran ; une correction, non).
 *
 * Le ticket suit le cycle : queued → reserved → running → done|failed|cancelled.
 * La réservation est un engagement court (le client doit démarrer dans les
 * quelques secondes) ; l'exécution est surveillée par un battement de cœur, ce
 * qui permet de récupérer la place si un PHP meurt en cours de route.
 */
class pool {

    /** Nombre d'emplacements de serveur configurables. */
    const MAX_SERVERS = 3;

    /** Usages possibles d'un ticket. */
    const PURPOSE_TUTOR    = 'tutor';
    const PURPOSE_FEEDBACK = 'feedback';

    /** Table des tickets. */
    const TABLE_SLOT = 'local_aifeedback_slot';
    /** Table d'état des serveurs (santé, dernière utilisation). */
    const TABLE_SERVER = 'local_aifeedback_server';

    /** Valeurs par défaut des réglages (secondes). */
    const DEFAULT_HEARTBEAT_TTL = 90;
    const DEFAULT_RESERVE_TTL   = 20;
    const DEFAULT_QUEUE_TTL     = 600;
    const DEFAULT_FAILCOOLDOWN  = 60;

    /** Serveur imposé aux appels api::call/stream du processus courant. */
    private static $currentserver = null;

    // =====================================================================
    //  DESCRIPTION DES SERVEURS
    // =====================================================================

    /**
     * Liste des serveurs configurés et actifs, indexés par leur numéro (1..3).
     *
     * L'emplacement 1 réutilise les réglages historiques (apiurl/model/apikey)
     * et reste toujours présent : c'est le serveur « par défaut » du site.
     *
     * @return array[] descripteurs {id, apiurl, model, apikey, maxconcurrency,
     *                 use_feedback, use_tutor}
     */
    public static function servers() {
        $out = array();
        for ($i = 1; $i <= self::MAX_SERVERS; $i++) {
            $prefix = 'server' . $i . '_';
            if ($i === 1) {
                $url   = trim((string)get_config('local_aifeedback', 'apiurl'));
                $model = trim((string)get_config('local_aifeedback', 'model'));
                $key   = secret::decrypt((string)get_config('local_aifeedback', 'apikey'));
                if ($url === '') {
                    // Même défaut que api::prepare() : l'emplacement 1 existe
                    // toujours, sinon plus aucun appel ne serait possible.
                    $url = 'http://localhost:1234/v1/chat/completions';
                }
            } else {
                $url   = trim((string)get_config('local_aifeedback', $prefix . 'apiurl'));
                $model = trim((string)get_config('local_aifeedback', $prefix . 'model'));
                $key   = secret::decrypt((string)get_config('local_aifeedback', $prefix . 'apikey'));
                if ($url === '') {
                    continue; // emplacement non renseigné = désactivé
                }
            }

            $max = (int)get_config('local_aifeedback', $prefix . 'maxconcurrency');
            if ($max <= 0) {
                $max = 1;
            }
            $usefeedback = get_config('local_aifeedback', $prefix . 'use_feedback');
            $usetutor    = get_config('local_aifeedback', $prefix . 'use_tutor');
            // Tant que rien n'a été enregistré, l'emplacement 1 sert à tout.
            if ($usefeedback === false) {
                $usefeedback = ($i === 1) ? 1 : 0;
            }
            if ($usetutor === false) {
                $usetutor = ($i === 1) ? 1 : 0;
            }

            $out[$i] = array(
                'id'             => $i,
                'apiurl'         => $url,
                'model'          => $model,
                'apikey'         => $key,
                'maxconcurrency' => $max,
                'use_feedback'   => !empty($usefeedback),
                'use_tutor'      => !empty($usetutor),
            );
        }
        return $out;
    }

    /**
     * Descripteur d'un serveur par son numéro, ou null s'il n'est plus configuré.
     */
    public static function server($serverid) {
        $servers = self::servers();
        return isset($servers[(int)$serverid]) ? $servers[(int)$serverid] : null;
    }

    /**
     * Options d'appel (api::call / api::stream) pour un descripteur de serveur.
     * Les valeurs vides sont omises pour laisser jouer les réglages globaux.
     */
    public static function server_options(array $server) {
        $opts = array();
        foreach (array('apiurl', 'model', 'apikey') as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                $opts[$key] = $server[$key];
            }
        }
        return $opts;
    }

    // =====================================================================
    //  CONTEXTE COURANT (serveur + ticket tenus par ce processus)
    // =====================================================================

    /**
     * Déclare le serveur et le ticket tenus par ce processus : api::call() et
     * api::stream() les utilisent alors automatiquement (quand l'appelant n'a
     * pas imposé sa propre URL), entretiennent le battement de cœur du ticket
     * pendant l'appel et peuvent basculer sur un autre serveur en cas de panne.
     *
     * @param array  $server    descripteur (voir servers())
     * @param int    $slotid    ticket réservé et démarré
     * @param string $purpose   self::PURPOSE_*
     * @param string $component frankenstyle de l'appelant (repris si basculement)
     */
    public static function set_current(array $server, $slotid, $purpose, $component = 'local_aifeedback') {
        self::$currentserver = array(
            'server'       => $server,
            'slotid'       => (int)$slotid,
            'purpose'      => (string)$purpose,
            'component'    => (string)$component,
            'tried'        => array((int)$server['id']),
            'servererrors' => 0,
            'lastbeat'     => time(),
        );
    }

    /** @return array|null descripteur du serveur tenu par ce processus */
    public static function current() {
        return (self::$currentserver === null) ? null : self::$currentserver['server'];
    }

    /** @return int ticket tenu par ce processus (0 si aucun) */
    public static function current_slot() {
        return (self::$currentserver === null) ? 0 : (int)self::$currentserver['slotid'];
    }

    /** Oublie le contexte courant (après avoir libéré le ticket). */
    public static function clear_current() {
        self::$currentserver = null;
    }

    /**
     * Compatibilité : impose un serveur sans ticket (pas de battement de cœur,
     * pas de basculement), ou lève le contexte avec null.
     *
     * @param array|null $server
     */
    public static function set_current_server($server) {
        if ($server === null) {
            self::clear_current();
            return;
        }
        self::set_current($server, 0, self::PURPOSE_FEEDBACK);
    }

    /** Compatibilité : alias de current(). */
    public static function current_server() {
        return self::current();
    }

    /**
     * Battement de cœur du ticket courant. Appelé par la fonction de
     * progression de curl (environ une fois par seconde, y compris pendant que
     * le serveur calcule sans rien envoyer) : sans lui, une correction de plus
     * de 90 s verrait sa place récupérée alors que le serveur travaille
     * encore. Une écriture en base au plus toutes les 15 s.
     */
    public static function heartbeat_current() {
        if (self::$currentserver === null || self::$currentserver['slotid'] <= 0) {
            return;
        }
        $now = time();
        if ($now - self::$currentserver['lastbeat'] < 15) {
            return;
        }
        self::$currentserver['lastbeat'] = $now;
        try {
            self::heartbeat(self::$currentserver['slotid']);
        } catch (\Throwable $e) {
            // Jamais d'exception depuis un callback curl : au pire, le ticket
            // sera récupéré et l'erreur apparaîtra au prochain battement.
        }
    }

    /**
     * Bascule le processus sur un autre serveur après une panne.
     *
     * - UNREACHABLE : le serveur est mis en quarantaine, puis on essaie chacun
     *   des autres serveurs acceptant l'usage.
     * - SERVER_ERROR : pas de quarantaine (c'est peut-être la requête qui fait
     *   planter le modèle) et un seul nouvel essai, pour qu'une copie
     *   « empoisonnée » ne fasse pas le tour de tous les serveurs.
     *
     * @param server_unavailable_exception $e
     * @return bool true si un autre serveur a été obtenu (l'appel peut être
     *              rejoué), false sinon (le ticket courant est alors libéré)
     */
    public static function failover(server_unavailable_exception $e) {
        global $DB;

        if (self::$currentserver === null || self::$currentserver['slotid'] <= 0) {
            return false; // pas de ticket : appel hors pool, rien à basculer
        }
        $ctx    = self::$currentserver;
        $failed = (int)$ctx['server']['id'];

        if ($e->kind === server_unavailable_exception::UNREACHABLE) {
            self::mark_server_failed($failed);
        } else if ($ctx['servererrors'] >= 1) {
            return false; // déjà rejoué une fois sur un autre serveur
        }

        $old = $DB->get_record(self::TABLE_SLOT, array('id' => (int)$ctx['slotid']));
        self::release($ctx['slotid'], 'failed');
        self::$currentserver['slotid'] = 0;

        $got = self::acquire($ctx['purpose'], self::setting('pool_acquire_wait', 10),
            $ctx['component'], $ctx['tried'],
            $old ? (int)$old->userid : 0, $old ? (int)$old->reference : 0);
        if ($got === null) {
            return false;
        }

        debugging('local_aifeedback: serveur ' . $failed . ' indisponible (' . $e->kind
            . '), bascule sur le serveur ' . (int)$got['server']['id'], DEBUG_DEVELOPER);

        self::$currentserver['server']   = $got['server'];
        self::$currentserver['slotid']   = (int)$got['slotid'];
        self::$currentserver['tried'][]  = (int)$got['server']['id'];
        self::$currentserver['lastbeat'] = time();
        if ($e->kind === server_unavailable_exception::SERVER_ERROR) {
            self::$currentserver['servererrors']++;
        }
        return true;
    }

    /**
     * Exécute un appel synchrone (page web) sous régulation du pool : prend une
     * place, exécute, libère. Si le processus tient déjà une place (appel
     * imbriqué) ou si la répartition est désactivée, exécute directement.
     *
     * @param string   $purpose
     * @param callable $fn
     * @param int      $maxwait attente maximale d'une place (secondes)
     * @param string   $component
     * @return mixed ce que renvoie $fn
     * @throws \moodle_exception poolbusy si aucune place ne s'est libérée
     */
    public static function run($purpose, callable $fn, $maxwait = 30, $component = 'local_aifeedback') {
        if (self::$currentserver !== null || !self::feedback_enabled()) {
            return $fn();
        }
        $got = self::acquire($purpose, $maxwait, $component);
        if ($got === null) {
            throw new \moodle_exception('poolbusy', 'local_aifeedback');
        }
        self::set_current($got['server'], $got['slotid'], $purpose, $component);
        $status = 'failed';
        try {
            $result = $fn();
            $status = 'done';
            return $result;
        } finally {
            self::release(self::current_slot(), $status);
            self::clear_current();
        }
    }

    /**
     * La file de jobs est-elle répartie sur le pool ? (interrupteur de
     * sécurité : activé tant qu'il n'a pas été explicitement désactivé)
     */
    public static function feedback_enabled() {
        $value = get_config('local_aifeedback', 'pool_feedback');
        return ($value === false) ? true : !empty($value);
    }

    /**
     * Un serveur (hors exclusions) accepte-t-il cet usage ? Sinon, inutile de
     * faire la queue.
     *
     * @param string $purpose
     * @param int[]  $exclude
     */
    public static function has_server_for($purpose, array $exclude = array()) {
        $exclude = array_map('intval', $exclude);
        foreach (self::servers() as $id => $server) {
            if (in_array((int)$id, $exclude, true)) {
                continue;
            }
            if (self::server_accepts($server, $purpose)) {
                return true;
            }
        }
        return false;
    }

    /** « 1,3 » → array(1, 3) */
    private static function parse_ids($csv) {
        $csv = trim((string)$csv);
        if ($csv === '') {
            return array();
        }
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    private static function server_accepts(array $server, $purpose) {
        if ($purpose === self::PURPOSE_TUTOR) {
            return !empty($server['use_tutor']);
        }
        return !empty($server['use_feedback']);
    }

    // =====================================================================
    //  CYCLE DE VIE D'UN TICKET
    // =====================================================================

    /**
     * Prend un ticket dans la file. Le ticket est « queued » : il faut ensuite
     * appeler poll() jusqu'à obtenir « reserved » (avec un serveur).
     *
     * @param string $purpose   self::PURPOSE_*
     * @param int    $userid    utilisateur à l'origine (0 = système/cron)
     * @param string $component frankenstyle de l'appelant
     * @param int    $reference identifiant métier libre (ex. id de message)
     * @param int[]  $exclude   serveurs à ne pas utiliser (déjà essayés)
     * @return int id du ticket
     */
    public static function request($purpose, $userid, $component, $reference = 0, array $exclude = array()) {
        global $DB;
        $now = time();
        $row = (object)array(
            'purpose'       => (string)$purpose,
            'serverid'      => 0,
            'status'        => 'queued',
            'userid'        => (int)$userid,
            'component'     => (string)$component,
            'reference'     => (int)$reference,
            'excluded'      => implode(',', array_unique(array_map('intval', $exclude))),
            'timecreated'   => $now,
            'timereserved'  => 0,
            'timestarted'   => 0,
            'timeheartbeat' => 0,
            'timefinished'  => 0,
        );
        return (int)$DB->insert_record(self::TABLE_SLOT, $row);
    }

    /**
     * État d'un ticket, après avoir fait tourner l'admission.
     *
     * @param int $id
     * @return \stdClass {status, position, server|null, serverid}
     *   status : queued|reserved|running|done|failed|cancelled|gone
     *   position : nombre de tickets du même usage devant celui-ci
     */
    public static function poll($id) {
        global $DB;

        self::admit();

        $row = $DB->get_record(self::TABLE_SLOT, array('id' => (int)$id));
        if (!$row) {
            return (object)array('status' => 'gone', 'position' => 0,
                'server' => null, 'serverid' => 0);
        }

        $out = (object)array(
            'status'   => $row->status,
            'position' => 0,
            'server'   => null,
            'serverid' => (int)$row->serverid,
        );
        if ($row->status === 'queued') {
            $out->position = (int)$DB->count_records_select(self::TABLE_SLOT,
                'status = ? AND purpose = ? AND id < ?',
                array('queued', $row->purpose, (int)$row->id));
        } else if ($row->status === 'reserved' || $row->status === 'running') {
            $out->server = self::server((int)$row->serverid);
        }
        return $out;
    }

    /**
     * Passe un ticket réservé en exécution. À appeler juste avant l'appel LLM.
     *
     * @return array|null descripteur du serveur, ou null si le ticket n'est
     *                    plus réservé (réservation expirée, double onglet…)
     */
    public static function start($id) {
        global $DB;
        $row = $DB->get_record(self::TABLE_SLOT, array('id' => (int)$id));
        if (!$row || $row->status !== 'reserved') {
            return null;
        }
        $now = time();
        $DB->update_record(self::TABLE_SLOT, (object)array(
            'id'            => (int)$row->id,
            'status'        => 'running',
            'timestarted'   => $now,
            'timeheartbeat' => $now,
        ));
        return self::server((int)$row->serverid);
    }

    /**
     * Signale que le ticket est toujours vivant (à appeler périodiquement
     * pendant une génération longue). Sans battement, admit() récupère la place.
     */
    public static function heartbeat($id) {
        global $DB;
        $DB->set_field(self::TABLE_SLOT, 'timeheartbeat', time(), array('id' => (int)$id));
    }

    /**
     * Libère un ticket. À appeler dans un finally : c'est ce qui rend la place
     * au suivant dans la file.
     *
     * @param int    $id
     * @param string $status done|failed|cancelled
     */
    public static function release($id, $status = 'done') {
        global $DB;
        $row = $DB->get_record(self::TABLE_SLOT, array('id' => (int)$id));
        if (!$row) {
            return;
        }
        $now = time();
        if (in_array($row->status, array('done', 'failed', 'cancelled'), true)) {
            return; // déjà libéré
        }
        $DB->update_record(self::TABLE_SLOT, (object)array(
            'id'           => (int)$row->id,
            'status'       => (string)$status,
            'timefinished' => $now,
        ));
        if ((int)$row->serverid > 0) {
            self::touch_server((int)$row->serverid, $now);
        }
    }

    /**
     * Annule un ticket qui n'a pas encore démarré (bouton « Arrêter » pendant
     * l'attente). Sans effet sur un ticket déjà en cours.
     *
     * @return bool true si le ticket a bien été annulé
     */
    public static function cancel($id) {
        global $DB;
        $row = $DB->get_record(self::TABLE_SLOT, array('id' => (int)$id));
        if (!$row || !in_array($row->status, array('queued', 'reserved'), true)) {
            return false;
        }
        $DB->update_record(self::TABLE_SLOT, (object)array(
            'id'           => (int)$row->id,
            'status'       => 'cancelled',
            'timefinished' => time(),
        ));
        return true;
    }

    /**
     * Attente bloquante d'une place (file de jobs, appels synchrones,
     * basculement), là où il n'y a pas de navigateur pour faire du polling.
     *
     * L'attente doit rester COURTE en cron : pendant qu'il attend, le processus
     * ne traite aucune autre tâche de fond du site (courriels, sauvegardes…).
     *
     * @param string $purpose
     * @param int    $maxwaitsec durée maximale d'attente
     * @param string $component
     * @param int[]  $exclude    serveurs à ne pas utiliser (déjà essayés)
     * @param int    $userid
     * @param int    $reference
     * @return array|null ['slotid' => int, 'server' => array] (ticket démarré),
     *                    ou null si rien n'a pu être obtenu (ticket annulé)
     */
    public static function acquire($purpose, $maxwaitsec = 30, $component = 'local_aifeedback',
            array $exclude = array(), $userid = 0, $reference = 0) {
        if (!self::has_server_for($purpose, $exclude)) {
            return null; // aucun serveur possible : inutile de faire la queue
        }
        $slotid   = self::request($purpose, $userid, $component, $reference, $exclude);
        $deadline = time() + max(1, (int)$maxwaitsec);
        do {
            $state = self::poll($slotid);
            if ($state->status === 'reserved') {
                $server = self::start($slotid);
                if ($server !== null) {
                    return array('slotid' => $slotid, 'server' => $server);
                }
            }
            if (!in_array($state->status, array('queued', 'reserved'), true)) {
                return null; // annulé / expiré
            }
            if (time() >= $deadline) {
                break;
            }
            sleep(1);
        } while (true);

        self::cancel($slotid);
        return null;
    }

    /** Compatibilité : ancienne signature de acquire(). */
    public static function acquire_blocking($purpose, $maxwaitsec = 30, $component = 'local_aifeedback') {
        return self::acquire($purpose, $maxwaitsec, $component);
    }

    // =====================================================================
    //  ADMISSION (section critique)
    // =====================================================================

    /**
     * Répare les tickets abandonnés puis affecte les tickets en attente aux
     * serveurs disponibles. Idempotente, appelée à chaque poll().
     *
     * Sous verrou court : si un autre processus est déjà en train d'admettre,
     * on ne fait rien (le prochain poll rattrapera, 1,5 s plus tard).
     *
     * @return bool false si le verrou n'a pas pu être pris
     */
    public static function admit() {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_aifeedback');
        $lock    = $factory->get_lock('pool_admission', 2);
        if (!$lock) {
            return false;
        }

        try {
            $now = time();
            self::reclaim($now);

            $servers = self::servers();
            if (empty($servers)) {
                return true;
            }

            // Charge courante par serveur (réservations + exécutions).
            $used = array();
            foreach ($servers as $id => $unused) {
                $used[$id] = 0;
            }
            $load = $DB->get_records_sql(
                'SELECT serverid, COUNT(*) AS n
                   FROM {' . self::TABLE_SLOT . '}
                  WHERE status IN (?, ?) AND serverid > 0
               GROUP BY serverid',
                array('reserved', 'running'));
            foreach ($load as $l) {
                if (isset($used[(int)$l->serverid])) {
                    $used[(int)$l->serverid] = (int)$l->n;
                }
            }

            $state  = self::server_state();
            $queued = $DB->get_records(self::TABLE_SLOT, array('status' => 'queued'), 'id ASC');
            if (empty($queued)) {
                return true;
            }

            // Priorité à l'interactif : un élève attend devant son écran.
            uasort($queued, function($a, $b) {
                $wa = ($a->purpose === self::PURPOSE_TUTOR) ? 0 : 1;
                $wb = ($b->purpose === self::PURPOSE_TUTOR) ? 0 : 1;
                if ($wa !== $wb) {
                    return $wa - $wb;
                }
                return ((int)$a->id) - ((int)$b->id);
            });

            foreach ($queued as $row) {
                $best     = null;
                $bestfree = 0;
                $bestused = 0;
                $excluded = self::parse_ids(isset($row->excluded) ? $row->excluded : '');
                foreach ($servers as $id => $server) {
                    if (!self::server_accepts($server, $row->purpose)) {
                        continue;
                    }
                    if (in_array((int)$id, $excluded, true)) {
                        continue; // déjà essayé par ce job (basculement)
                    }
                    if (isset($state[$id]) && (int)$state[$id]->failinguntil > $now) {
                        continue; // serveur en quarantaine
                    }
                    $free = (int)$server['maxconcurrency'] - (int)$used[$id];
                    if ($free <= 0) {
                        continue;
                    }
                    $lastused = isset($state[$id]) ? (int)$state[$id]->lastused : 0;
                    // Le plus de places libres ; à égalité, le moins récemment servi.
                    if ($best === null || $free > $bestfree
                            || ($free === $bestfree && $lastused < $bestused)) {
                        $best     = $id;
                        $bestfree = $free;
                        $bestused = $lastused;
                    }
                }
                if ($best === null) {
                    // Rien de libre pour cet usage : les suivants du même usage
                    // ne passeront pas non plus, mais un autre usage peut avoir
                    // des serveurs disponibles → on continue la boucle.
                    continue;
                }
                $DB->update_record(self::TABLE_SLOT, (object)array(
                    'id'           => (int)$row->id,
                    'status'       => 'reserved',
                    'serverid'     => (int)$best,
                    'timereserved' => $now,
                ));
                $used[$best]++;
            }
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Récupère les places perdues :
     *   - exécution sans battement de cœur (PHP tué, réseau coupé) → failed ;
     *   - réservation jamais démarrée (onglet fermé entre deux polls) → cancelled ;
     *   - attente trop longue (client parti) → cancelled.
     * Purge par ailleurs les tickets terminés de plus de 24 h.
     */
    private static function reclaim($now) {
        global $DB;

        $hbttl    = self::setting('pool_heartbeat_ttl', self::DEFAULT_HEARTBEAT_TTL);
        $resttl   = self::setting('pool_reserve_ttl', self::DEFAULT_RESERVE_TTL);
        $queuettl = self::setting('pool_queue_ttl', self::DEFAULT_QUEUE_TTL);

        $table = '{' . self::TABLE_SLOT . '}';

        $DB->execute("UPDATE $table SET status = ?, timefinished = ?
                       WHERE status = ? AND timeheartbeat < ?",
            array('failed', $now, 'running', $now - $hbttl));

        $DB->execute("UPDATE $table SET status = ?, timefinished = ?
                       WHERE status = ? AND timereserved < ?",
            array('cancelled', $now, 'reserved', $now - $resttl));

        $DB->execute("UPDATE $table SET status = ?, timefinished = ?
                       WHERE status = ? AND timecreated < ?",
            array('cancelled', $now, 'queued', $now - $queuettl));

        // Purge légère, au plus une fois par heure (évite de grossir sans fin).
        $lastpurge = (int)get_config('local_aifeedback', 'pool_lastpurge');
        if ($now - $lastpurge > HOURSECS) {
            set_config('pool_lastpurge', $now, 'local_aifeedback');
            $DB->delete_records_select(self::TABLE_SLOT,
                'status IN (?, ?, ?) AND timefinished > 0 AND timefinished < ?',
                array('done', 'failed', 'cancelled', $now - DAYSECS));
        }
    }

    // =====================================================================
    //  ÉTAT / SANTÉ DES SERVEURS
    // =====================================================================

    /**
     * Lignes d'état des serveurs, indexées par numéro de serveur.
     * @return \stdClass[]
     */
    public static function server_state() {
        global $DB;
        $rows = $DB->get_records(self::TABLE_SERVER);
        $out  = array();
        foreach ($rows as $row) {
            $out[(int)$row->serverid] = $row;
        }
        return $out;
    }

    /**
     * Met un serveur en quarantaine après un échec réseau : on cesse de lui
     * envoyer du travail pendant le délai de refroidissement, ce qui bascule
     * automatiquement les requêtes suivantes vers l'autre machine.
     */
    public static function mark_server_failed($serverid, $cooldownsec = null) {
        global $DB;
        $serverid = (int)$serverid;
        if ($serverid <= 0) {
            return;
        }
        if ($cooldownsec === null) {
            $cooldownsec = self::setting('pool_failcooldown', self::DEFAULT_FAILCOOLDOWN);
        }
        $row = self::ensure_server_row($serverid);
        $DB->update_record(self::TABLE_SERVER, (object)array(
            'id'           => (int)$row->id,
            'failinguntil' => time() + (int)$cooldownsec,
            'failures'     => (int)$row->failures + 1,
        ));
    }

    /** Remet un serveur en service immédiatement (action d'administration). */
    public static function clear_server_failure($serverid) {
        global $DB;
        $row = self::ensure_server_row((int)$serverid);
        $DB->update_record(self::TABLE_SERVER, (object)array(
            'id'           => (int)$row->id,
            'failinguntil' => 0,
        ));
    }

    /**
     * Synthèse pour l'administration : configuration + charge + santé.
     * @return array[] un élément par serveur configuré
     */
    public static function server_status() {
        global $DB;
        $servers = self::servers();
        $state   = self::server_state();
        $load    = $DB->get_records_sql(
            'SELECT serverid, COUNT(*) AS n
               FROM {' . self::TABLE_SLOT . '}
              WHERE status IN (?, ?) AND serverid > 0
           GROUP BY serverid',
            array('reserved', 'running'));

        // Bilan de la dernière heure, par serveur et par issue.
        $recent = $DB->get_recordset_sql(
            'SELECT serverid, status, COUNT(*) AS n
               FROM {' . self::TABLE_SLOT . '}
              WHERE serverid > 0 AND timefinished > ?
           GROUP BY serverid, status',
            array(time() - HOURSECS));
        $lasthour = array();
        foreach ($recent as $r) {
            $lasthour[(int)$r->serverid][(string)$r->status] = (int)$r->n;
        }
        $recent->close();

        $out = array();
        foreach ($servers as $id => $server) {
            $server['busy']         = isset($load[$id]) ? (int)$load[$id]->n : 0;
            $server['failinguntil'] = isset($state[$id]) ? (int)$state[$id]->failinguntil : 0;
            $server['failures']     = isset($state[$id]) ? (int)$state[$id]->failures : 0;
            $server['lastused']     = isset($state[$id]) ? (int)$state[$id]->lastused : 0;
            $server['done1h']       = isset($lasthour[$id]['done']) ? $lasthour[$id]['done'] : 0;
            $server['failed1h']     = isset($lasthour[$id]['failed']) ? $lasthour[$id]['failed'] : 0;
            $out[$id] = $server;
        }
        return $out;
    }

    /** Nombre de tickets en attente, par usage. */
    public static function queue_length($purpose) {
        global $DB;
        return (int)$DB->count_records(self::TABLE_SLOT,
            array('status' => 'queued', 'purpose' => (string)$purpose));
    }

    private static function touch_server($serverid, $now) {
        global $DB;
        $row = self::ensure_server_row((int)$serverid);
        $DB->update_record(self::TABLE_SERVER, (object)array(
            'id'       => (int)$row->id,
            'lastused' => (int)$now,
        ));
    }

    /** Crée à la demande la ligne d'état d'un serveur. */
    private static function ensure_server_row($serverid) {
        global $DB;
        $row = $DB->get_record(self::TABLE_SERVER, array('serverid' => (int)$serverid));
        if ($row) {
            return $row;
        }
        $new = (object)array(
            'serverid'     => (int)$serverid,
            'failinguntil' => 0,
            'failures'     => 0,
            'lastused'     => 0,
        );
        try {
            $new->id = $DB->insert_record(self::TABLE_SERVER, $new);
        } catch (\dml_exception $e) {
            // Course entre deux processus sur la clé unique : on relit.
            $existing = $DB->get_record(self::TABLE_SERVER, array('serverid' => (int)$serverid));
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
        return $new;
    }

    /** Réglage entier avec repli sur la valeur par défaut. */
    private static function setting($name, $default) {
        $value = (int)get_config('local_aifeedback', $name);
        return ($value > 0) ? $value : (int)$default;
    }
}
