<?php
/**
 * Flux de génération du tuteur IA (Server-Sent Events).
 *
 * Le script tient la connexion ouverte pendant toute la génération et pousse
 * chaque fragment de texte dès qu'il arrive du serveur LLM, ce qui donne
 * l'affichage progressif attendu d'un chat.
 *
 * Contraintes respectées ici :
 *   - NO_OUTPUT_BUFFERING avant config.php (Moodle ferme alors les tampons de
 *     sortie, désactive la compression zlib et pose X-Accel-Buffering: no) ;
 *   - session refermée au plus tôt : sans cela, la session verrouillée
 *     bloquerait toutes les autres requêtes de l'élève pendant la génération ;
 *   - ticket du pool libéré dans tous les cas (finally).
 *
 * @package local_aichat
 */

define('NO_OUTPUT_BUFFERING', true);
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_aichat\activity;
use local_aichat\conversation;
use local_aichat\generator;
use local_aichat\quota;
use local_aichat\tutor;
use local_aichat\websearch\manager as websearch;
use local_aifeedback\pool;

/** Émet un événement SSE et pousse immédiatement vers le navigateur. */
function local_aichat_sse($event, array $data) {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

$cmid      = required_param('cmid', PARAM_INT);
$messageid = required_param('messageid', PARAM_INT);

$access = activity::require_access($cmid);
$conv   = conversation::active($cmid, $USER->id);

$message = $DB->get_record('local_aichat_message',
    array('id' => $messageid, 'conversationid' => $conv->id, 'userid' => $USER->id));
if (!$message) {
    throw new moodle_exception('error_notfound', 'local_aichat');
}
if ($message->status !== 'pending') {
    // Déjà en cours dans un autre onglet, ou déjà terminée : rien à streamer.
    throw new moodle_exception('error_busy', 'local_aichat');
}

// Prend possession du serveur réservé par le pool. Null = réservation expirée
// (l'élève a laissé passer trop de temps entre l'attente et l'ouverture du flux).
$server = pool::start((int)$message->slotid);
if ($server === null) {
    conversation::fail_message($messageid, 'failed',
        get_string('error_expired', 'local_aichat'), $access->context);
    throw new moodle_exception('error_expired', 'local_aichat');
}

$serverid = (int)$server['id'];
conversation::start_message($messageid, $serverid);

// Le processus tient désormais ce serveur et ce ticket : api::stream() les
// utilise, entretient le battement de cœur pendant tout l'appel (y compris
// pendant le calcul du premier mot, qui peut être long) et bascule sur un
// autre serveur du tuteur si celui-ci est en panne avant le premier fragment.
pool::set_current($server, (int)$message->slotid, pool::PURPOSE_TUTOR, 'local_aichat');

// --- Mode flux -------------------------------------------------------------
@header('Content-Type: text/event-stream; charset=utf-8');
@header('Cache-Control: no-cache, no-store, must-revalidate');
@header('Connection: keep-alive');
@header('X-Accel-Buffering: no');
// Certains frontaux compressent par défaut : la compression annule le streaming.
@header('Content-Encoding: identity');

// Rembourrage initial : force les tampons intermédiaires (mod_deflate, proxy)
// à laisser passer les premiers octets.
echo ':' . str_repeat(' ', 2048) . "\n\n";
flush();

// La génération peut durer : on rend la main sur la session, sinon l'élève ne
// peut plus charger aucune autre page Moodle pendant ce temps.
\core\session\manager::write_close();
\core_php_time_limit::raise(600);
// On veut être notifié de l'abandon (connection_aborted) SANS être tué, afin
// de libérer proprement le ticket et de conserver le texte déjà produit.
ignore_user_abort(true);

local_aichat_sse('start', array('messageid' => (int)$messageid));

// Recherche Web : proposée au modèle seulement si elle est possible pour cette
// réponse ; sinon (activité sans recherche) la requête est celle d'avant.
// Capacité optionnelle : un incident ici ne doit jamais empêcher la réponse.
$material  = null;
$forcetool = null;
try {
    $websearch = websearch::availability($access->config, (int)$USER->id);
    $webtool   = ($websearch === '') ? websearch::new_tool($USER, $access->config) : null;
    $tool      = $webtool;
    $question  = conversation::student_question((int)$conv->id, (int)$messageid);
    // 'disabled' : activité sans recherche, ou élève sans clé personnelle —
    // sans clé, le tuteur ne va pas sur Internet (ni recherche, ni lecture).
    if (websearch::mode($access->config) === websearch::MODE_MATERIAL && $websearch !== 'disabled') {
        // Recherche de matériel : web_search + read_page. Sont lisibles les
        // pages trouvées, les documents liés d'une page lue, et les adresses
        // données par l'élève dans sa question.
        $allowlist = new \local_aichat\reader\allowlist();
        $allowlist->add_from_text($question);
        $reader = null;
        if (websearch::reader_availability((int)$USER->id) === ''
                && ($webtool !== null || $allowlist->count() > 0)) {
            $reader = websearch::new_reader($allowlist, $USER);
        }
        $tools    = array_values(array_filter(array($webtool, $reader)));
        $tool     = empty($tools) ? null
            : new \local_aichat\toolbox($tools, websearch::toolcalls(), $allowlist);
        $material = array('read' => $reader !== null, 'sites' => websearch::sites($access->config));
    }
    // Demande explicite de l'élève (« lance une recherche », adresse collée) :
    // l'appel est imposé au premier tour, sinon le modèle se contente souvent
    // de l'annoncer.
    $forcetool = \local_aichat\toolbox::requested_tool($question, $tool);
    // Recherche de matériel : au tout premier message, le tuteur garde son
    // tour pour demander les critères du cahier des charges. Une adresse
    // donnée par l'élève reste lue tout de suite.
    if ($material !== null && $forcetool === \local_aichat\websearch\tool::NAME
            && conversation::student_turns((int)$conv->id, (int)$messageid) <= 1) {
        $forcetool = null;
    }
} catch (\Throwable $e) {
    debugging('local_aichat: recherche Web indisponible — ' . $e->getMessage(), DEBUG_DEVELOPER);
    $websearch = websearch::activity_enabled($access->config) ? 'provider_unavailable' : 'disabled';
    $tool      = null;
    $material  = null;
    $forcetool = null;
}

$messages    = tutor::build_messages($access, (int)$conv->id, (int)$messageid, $websearch, $material);
$prompttext  = '';
foreach ($messages as $m) {
    if (is_string($m['content'])) {
        $prompttext .= $m['content'];
    }
}

// Pas d'URL imposée : le serveur vient du contexte du pool, ce qui autorise
// le basculement.
$options = tutor::generation_options();

$buffer    = '';
$lastsave  = time();
$aborted   = false;

// Recherche ou lecture lancée : le widget l'affiche (sinon quelques secondes
// de silence). $kind : 'web' (requête) ou 'read' (domaine de la page lue).
$onsearch = function($label, $kind = 'web') {
    local_aichat_sse('search', array('q' => (string)$label, 'tool' => (string)$kind));
};

try {
    $result = generator::run($messages, $options,
        function($delta) use (&$buffer, &$lastsave, &$aborted, $messageid) {
            $buffer .= $delta;
            local_aichat_sse('delta', array('t' => $delta));

            // L'élève a fermé l'onglet ou cliqué « Arrêter » : on coupe la
            // génération, ce qui rend la main au suivant dans la file.
            if (connection_aborted()) {
                $aborted = true;
                return false;
            }

            // Sauvegarde du partiel : si ce PHP meurt, rien n'est perdu. (Le
            // battement de cœur du ticket, lui, est entretenu par api::stream.)
            $now = time();
            if ($now - $lastsave >= 5) {
                $lastsave = $now;
                conversation::store_partial($messageid, $buffer);
            }
            return true;
        }, $onsearch, $tool, $forcetool);

    // Recherches de cette réponse : quota de l'élève et transcription.
    conversation::record_searches($messageid, $tool, $websearch);

    // Serveur finalement utilisé (différent du premier en cas de basculement).
    $final = pool::current();
    if ($final !== null && (int)$final['id'] !== $serverid) {
        $DB->set_field('local_aichat_message', 'serverid', (int)$final['id'],
            array('id' => $messageid));
    }

    if (!empty($result['aborted']) || $aborted) {
        conversation::store_partial($messageid, $result['content']);
        conversation::fail_message($messageid, 'cancelled', null, $access->context);
        pool::release(pool::current_slot(), 'cancelled');
        pool::clear_current();
        exit;
    }

    conversation::finish_message($messageid, $result['content'], $access->context, 'done');
    quota::record($messageid, isset($result['usage']) ? $result['usage'] : null,
        $prompttext, (string)$result['content'], $result['toolchars']);
    pool::release(pool::current_slot(), 'done');
    pool::clear_current();

    $updated = $DB->get_record('local_aichat_message', array('id' => $messageid));
    local_aichat_sse('done', array(
        'messageid' => (int)$messageid,
        'html'      => (string)$updated->contenthtml,
        'quota'     => (array)quota::check($USER->id),
    ));

} catch (\Throwable $e) {
    // Les pannes de serveur ont déjà été traitées par le pool (quarantaine,
    // essai sur un autre serveur du tuteur) : si l'on arrive ici, aucun
    // serveur n'a pu répondre, ou la panne est survenue en cours de réponse.
    $detail = ($e instanceof \moodle_exception && !empty($e->debuginfo))
        ? (string)$e->debuginfo : (string)$e->getMessage();
    debugging('local_aichat: échec du flux — ' . $detail, DEBUG_DEVELOPER);

    // Les recherches déjà faites comptent, même si la réponse a échoué.
    conversation::record_searches($messageid, $tool, $websearch);
    conversation::store_partial($messageid, $buffer);
    conversation::fail_message($messageid, 'failed',
        get_string('error_llm', 'local_aichat'), $access->context);
    pool::release(pool::current_slot(), 'failed');
    pool::clear_current();

    local_aichat_sse('error', array(
        'message' => get_string('error_llm', 'local_aichat'),
    ));
}
