<?php
/**
 * Point d'entrée JSON du tuteur IA (tout sauf le flux de génération).
 *
 * Actions : history | newconv | send | poll | cancel
 *
 * La génération elle-même passe par stream.php, qui attend son tour dans la
 * file du load balancer d'inférence ; « poll » renvoie pendant ce temps le
 * rang estimé de la demande.
 * Toutes exigent la connexion, le sesskey, la capacité local/aichat:use et une
 * activité où le tuteur est activé (voir activity::require_access()).
 *
 * @package local_aichat
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_aichat\activity;
use local_aichat\attachment;
use local_aichat\conversation;
use local_aichat\moderation;
use local_aichat\quota;
use local_aichat\tutor;
use local_aifeedback\lb\client;

/**
 * Émet la réponse JSON et termine.
 */
function local_aichat_reply(array $payload) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Réponse d'erreur métier (HTTP 200 : c'est une réponse applicative, pas une
 * panne — le widget sait l'afficher).
 */
function local_aichat_error($code, $message, array $extra = array()) {
    local_aichat_reply(array_merge(array(
        'ok'      => false,
        'error'   => (string)$code,
        'message' => (string)$message,
    ), $extra));
}

$cmid   = required_param('cmid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$access = activity::require_access($cmid);
$conv   = conversation::active($cmid, $USER->id);

switch ($action) {

    // -----------------------------------------------------------------
    //  Historique (chargement initial du widget, reprise après F5)
    // -----------------------------------------------------------------
    case 'history':
        $rows        = conversation::messages($conv->id);
        $attachments = attachment::for_messages(array_keys($rows));
        $messages    = array();
        foreach ($rows as $row) {
            $messages[] = conversation::export($row,
                isset($attachments[(int)$row->id]) ? $attachments[(int)$row->id] : array());
        }
        $busy = conversation::busy_message($conv->id);
        local_aichat_reply(array(
            'ok'             => true,
            'conversationid' => (int)$conv->id,
            'messages'       => $messages,
            'busymessageid'  => ($busy !== null) ? (int)$busy->id : 0,
            'quota'          => (array)quota::check($USER->id),
        ));
        break;

    // -----------------------------------------------------------------
    //  Nouvelle discussion
    // -----------------------------------------------------------------
    case 'newconv':
        $conv = conversation::restart($cmid, $USER->id);
        local_aichat_reply(array(
            'ok'             => true,
            'conversationid' => (int)$conv->id,
            'messages'       => array(),
            'busymessageid'  => 0,
            'quota'          => (array)quota::check($USER->id),
        ));
        break;

    // -----------------------------------------------------------------
    //  Envoi d'une question
    // -----------------------------------------------------------------
    case 'send':
        $content = trim(optional_param('content', '', PARAM_RAW));
        $uploads = attachment::uploads();
        // Des fichiers seuls suffisent (« regarde ma capture »).
        if ($content === '' && empty($uploads)) {
            local_aichat_error('empty', get_string('error_empty', 'local_aichat'));
        }
        $maxchars = (int)get_config('local_aichat', 'maxmessagechars');
        if ($maxchars <= 0) {
            $maxchars = 2000;
        }
        if (core_text::strlen($content) > $maxchars) {
            local_aichat_error('toolong',
                get_string('error_toolong', 'local_aichat', $maxchars));
        }

        // Une seule génération à la fois par conversation : évite le double
        // envoi (double-clic, deuxième onglet) et le mélange des réponses.
        $busy = conversation::busy_message($conv->id);
        if ($busy !== null) {
            local_aichat_error('busy', get_string('error_busy', 'local_aichat'),
                array('messageid' => (int)$busy->id));
        }

        $quota = quota::check($USER->id);
        if (!$quota->ok) {
            local_aichat_error('quota',
                get_string('error_quota', 'local_aichat', (object)array(
                    'time'  => $quota->resetlabel,
                    'delay' => format_time((int)ceil($quota->retryafter / MINSECS) * MINSECS),
                )),
                array('quota' => (array)$quota));
        }

        if (!client::configured() || tutor::model() === '') {
            local_aichat_error('noserver', get_string('error_noserver', 'local_aichat'));
        }

        // Fichiers lus et vérifiés avant tout enregistrement : un refus ne
        // laisse ni message ni fichier derrière lui.
        try {
            $prepared = attachment::prepare($uploads);
        } catch (\moodle_exception $e) {
            local_aichat_error('attach', $e->getMessage());
        }

        // Premier message depuis la fin de la fenêtre précédente : la
        // nouvelle démarre maintenant (avant l'enregistrement du message).
        quota::open_window($USER->id);
        $pair   = conversation::add_exchange($conv, $content);
        if ($prepared) {
            try {
                attachment::save($prepared, $pair->usermsgid, $USER->id, $access->context);
            } catch (\Throwable $e) {
                debugging('local_aichat: fichiers non enregistrés — ' . $e->getMessage(), DEBUG_DEVELOPER);
                conversation::delete_exchange($conv, $pair);
                local_aichat_error('attach', get_string('attach_saveerror', 'local_aichat'));
            }
        }
        // Analyse de modération en parallèle, via la file de jobs : sans effet
        // sur la réponse du tuteur. Un échec de mise en file ne doit jamais
        // empêcher l'élève d'obtenir sa réponse.
        try {
            if ($content !== '') {
                moderation::schedule($pair->usermsgid);
            }
        } catch (\Throwable $e) {
            debugging('local_aichat: modération non planifiée — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        local_aichat_reply(array(
            'ok'          => true,
            'messageid'   => (int)$pair->assistantid,
            'usermessage' => conversation::export(
                $DB->get_record('local_aichat_message', array('id' => $pair->usermsgid)),
                attachment::for_messages(array($pair->usermsgid))[$pair->usermsgid] ?? array()),
            'quota'       => (array)quota::check($USER->id),
        ));
        break;

    // -----------------------------------------------------------------
    //  Attente : rang dans la file du load balancer, ou réponse prête
    // -----------------------------------------------------------------
    case 'poll':
        $messageid = required_param('messageid', PARAM_INT);
        $message   = $DB->get_record('local_aichat_message',
            array('id' => $messageid, 'conversationid' => $conv->id, 'userid' => $USER->id));
        if (!$message) {
            local_aichat_error('notfound', get_string('error_notfound', 'local_aichat'));
        }

        // Déjà terminé (par cet onglet ou un autre).
        if (!in_array($message->status, conversation::BUSY_STATUSES, true)) {
            local_aichat_reply(array(
                'ok'      => true,
                'status'  => ($message->status === 'done') ? 'done' : $message->status,
                'html'    => (string)$message->contenthtml,
                'error'   => (string)$message->error,
                'quota'   => (array)quota::check($USER->id),
            ));
        }

        // Flux pas encore ouvert (nouvel onglet, rechargement) : le widget
        // peut l'ouvrir ; la demande prendra alors sa place dans la file.
        if ($message->status === 'pending') {
            local_aichat_reply(array('ok' => true, 'status' => 'ready', 'position' => 0));
        }

        // Flux ouvert : la demande attend son tour au load balancer, ou est
        // déjà en cours de génération. Le rang est une estimation du service.
        $reply = array('ok' => true, 'status' => 'queued', 'position' => null, 'reason' => '');
        if ((string)$message->lbjobid !== '') {
            try {
                $job = client::get_job((string)$message->lbjobid);
                if (isset($job['status']) && $job['status'] === 'queued') {
                    $queue = (isset($job['queue']) && is_array($job['queue'])) ? $job['queue'] : array();
                    $reply['position'] = (isset($queue['estimated_jobs_ahead'])
                        && $queue['estimated_jobs_ahead'] !== null) ? (int)$queue['estimated_jobs_ahead'] : null;
                    $reply['reason'] = isset($queue['reason']) ? (string)$queue['reason'] : '';
                } else if (isset($job['status']) && $job['status'] !== 'running'
                        && !empty($job['finished_at']) && strtotime((string)$job['finished_at']) < time() - 120) {
                    // Génération terminée depuis longtemps mais message jamais
                    // finalisé : le processus du flux a disparu.
                    conversation::fail_message($messageid, 'failed',
                        get_string('error_expired', 'local_aichat'), $access->context);
                    local_aichat_error('expired', get_string('error_expired', 'local_aichat'));
                } else {
                    $reply = array('ok' => true, 'status' => 'running', 'position' => 0);
                }
            } catch (\Throwable $e) {
                // Rang momentanément inconnu : le flux, lui, continue.
                debugging('local_aichat: rang indisponible — ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        local_aichat_reply($reply);
        break;

    // -----------------------------------------------------------------
    //  Annulation pendant l'attente
    // -----------------------------------------------------------------
    case 'cancel':
        $messageid = required_param('messageid', PARAM_INT);
        $message   = $DB->get_record('local_aichat_message',
            array('id' => $messageid, 'conversationid' => $conv->id, 'userid' => $USER->id));
        if (!$message) {
            local_aichat_error('notfound', get_string('error_notfound', 'local_aichat'));
        }
        if (in_array($message->status, conversation::BUSY_STATUSES, true)) {
            // Le job est annulé au load balancer, qu'il attende encore son tour
            // ou qu'il soit en cours (le navigateur coupe aussi le flux).
            conversation::cancel_lbjob($message);
            conversation::fail_message($messageid, 'cancelled', null, $access->context);
        }
        local_aichat_reply(array('ok' => true, 'status' => 'cancelled'));
        break;

    default:
        local_aichat_error('badaction', get_string('error_badaction', 'local_aichat'));
}
