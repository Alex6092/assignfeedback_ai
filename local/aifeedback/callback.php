<?php
/**
 * Point d'arrivée des callbacks du load balancer d'inférence.
 *
 * Le load balancer appelle cette URL (POST, JSON) quand un job asynchrone se
 * termine. Elle est publique (aucune session) : seule la signature HMAC
 * prouve que l'appel vient bien du load balancer.
 *
 * Ordre imposé par le contrat du load balancer :
 *   1. vérifier la signature et la fraîcheur de l'horodatage AVANT de décoder ;
 *   2. vérifier que l'identifiant signé du corps est celui de X-Event-ID ;
 *   3. enregistrer durablement le résultat (idempotent), puis répondre 2xx,
 *      y compris pour un événement déjà reçu.
 *
 * Le résultat est ensuite appliqué tout de suite (note, feedback…) ; si cela
 * échoue, une tâche de fond reprend le traitement.
 *
 * @package local_aifeedback
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

use local_aifeedback\jobs;
use local_aifeedback\lb\client;
use local_aifeedback\lb\signature;

/**
 * Répond au load balancer et termine le script.
 *
 * @param int    $status code HTTP
 * @param string $message
 */
function local_aifeedback_callback_reply($status, $message) {
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('ok' => ($status >= 200 && $status < 300), 'message' => (string)$message));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    local_aifeedback_callback_reply(405, 'POST attendu');
}

$secret = client::callback_secret();
if ($secret === '') {
    // Pas encore configuré : le load balancer réessaiera (et la réconciliation
    // récupérera de toute façon le résultat).
    local_aifeedback_callback_reply(503, 'secret de callback non configuré');
}

$body      = (string)file_get_contents('php://input');
$timestamp = (string)($_SERVER['HTTP_X_CALLBACK_TIMESTAMP'] ?? '');
$sig       = (string)($_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? '');
$eventid   = (string)($_SERVER['HTTP_X_EVENT_ID'] ?? '');

if (!signature::verify($body, $timestamp, $sig, $secret, time())) {
    local_aifeedback_callback_reply(401, 'signature invalide');
}

$event = json_decode($body, true);
if (!is_array($event) || !isset($event['id']) || (string)$event['id'] !== $eventid) {
    local_aifeedback_callback_reply(400, 'événement invalide');
}

$rowid   = optional_param('id', 0, PARAM_INT);
$outcome = jobs::receive($rowid, $event);
if ($outcome !== 'received') {
    // Déjà reçu, soumission remplacée ou ligne purgée : rien à refaire.
    local_aifeedback_callback_reply(200, $outcome);
}

// Le résultat est enregistré. On l'applique tout de suite, même si le load
// balancer cesse d'attendre la réponse (délai de 10 s) : il réessaiera et
// recevra « duplicate ».
ignore_user_abort(true);
core_php_time_limit::raise(600);
try {
    jobs::process($rowid);
} catch (\Throwable $e) {
    debugging('local_aifeedback: traitement du callback différé — ' . $e->getMessage(), DEBUG_DEVELOPER);
    jobs::schedule_process($rowid);
}
local_aifeedback_callback_reply(200, 'received');
