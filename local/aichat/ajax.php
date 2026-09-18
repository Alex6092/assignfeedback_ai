<?php
/**
 * Point d'entrée JSON du tuteur IA (tout sauf le flux de génération).
 *
 * Actions : history | newconv | send | poll | cancel
 * Toutes exigent la connexion, le sesskey, la capacité local/aichat:use et une
 * activité où le tuteur est activé (voir activity::require_access()).
 *
 * @package local_aichat
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_aichat\activity;
use local_aichat\conversation;
use local_aichat\quota;
use local_aifeedback\pool;

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
        $messages = array();
        foreach (conversation::messages($conv->id) as $row) {
            $messages[] = conversation::export($row);
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
        $content = trim(required_param('content', PARAM_RAW));
        if ($content === '') {
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
                get_string('error_quota', 'local_aichat', format_time($quota->retryafter)),
                array('quota' => (array)$quota));
        }

        if (!pool::has_server_for(pool::PURPOSE_TUTOR)) {
            local_aichat_error('noserver', get_string('error_noserver', 'local_aichat'));
        }

        $pair   = conversation::add_exchange($conv, $content);
        $slotid = pool::request(pool::PURPOSE_TUTOR, $USER->id, 'local_aichat',
            $pair->assistantid);
        conversation::attach_slot($pair->assistantid, $slotid);

        local_aichat_reply(array(
            'ok'          => true,
            'messageid'   => (int)$pair->assistantid,
            'usermessage' => conversation::export(
                $DB->get_record('local_aichat_message', array('id' => $pair->usermsgid))),
            'quota'       => (array)quota::check($USER->id),
        ));
        break;

    // -----------------------------------------------------------------
    //  Attente : place dans la file, ou réponse prête
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

        // Génération déjà lancée ailleurs : on laisse le flux se terminer.
        if ($message->status === 'streaming') {
            local_aichat_reply(array('ok' => true, 'status' => 'running', 'position' => 0));
        }

        $state = pool::poll((int)$message->slotid);
        if ($state->status === 'queued') {
            local_aichat_reply(array(
                'ok'       => true,
                'status'   => 'queued',
                'position' => (int)$state->position,
            ));
        }
        if ($state->status === 'reserved') {
            // Le serveur est réservé : le widget peut ouvrir le flux.
            local_aichat_reply(array('ok' => true, 'status' => 'ready', 'position' => 0));
        }
        if ($state->status === 'running') {
            local_aichat_reply(array('ok' => true, 'status' => 'running', 'position' => 0));
        }

        // Ticket perdu (réservation expirée, file purgée, serveur tombé).
        conversation::fail_message($messageid, 'failed',
            get_string('error_expired', 'local_aichat'), $access->context);
        local_aichat_error('expired', get_string('error_expired', 'local_aichat'));
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
            // Si la génération a déjà démarré, c'est l'abandon de la requête
            // HTTP côté navigateur qui l'interrompt ; ici on libère la place.
            pool::cancel((int)$message->slotid);
            conversation::fail_message($messageid, 'cancelled', null, $access->context);
        }
        local_aichat_reply(array('ok' => true, 'status' => 'cancelled'));
        break;

    default:
        local_aichat_error('badaction', get_string('error_badaction', 'local_aichat'));
}
