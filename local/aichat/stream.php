<?php
/**
 * Flux de génération du tuteur IA (Server-Sent Events).
 *
 * Le script tient la connexion ouverte pendant toute la génération et pousse
 * chaque fragment de texte dès qu'il arrive du serveur IA, ce qui donne
 * l'affichage progressif attendu d'un chat.
 *
 * La demande passe par le mode streaming du load balancer d'inférence : elle
 * y attend d'abord son tour (le widget affiche alors le rang estimé, obtenu
 * par ajax.php?action=poll), puis la génération arrive fragment par fragment.
 *
 * Contraintes respectées ici :
 *   - NO_OUTPUT_BUFFERING avant config.php (Moodle ferme alors les tampons de
 *     sortie, désactive la compression zlib et pose X-Accel-Buffering: no) ;
 *   - session refermée au plus tôt : sans cela, la session verrouillée
 *     bloquerait toutes les autres requêtes de l'élève pendant la génération ;
 *   - abandon de l'élève détecté AUSSI pendant l'attente (les battements du
 *     load balancer sont relayés au navigateur) : la connexion est coupée et
 *     le load balancer annule la demande.
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
use local_aifeedback\lb\lb_exception;

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

// Le flux est ouvert : la demande va prendre sa place dans la file du load
// balancer (un second onglet verra le message « en cours »).
if (!conversation::start_message($messageid)) {
    throw new moodle_exception('error_busy', 'local_aichat');
}

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
// Attente dans la file du load balancer (10 min au plus) puis génération.
\core_php_time_limit::raise(1500);
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

$messages = tutor::build_messages($access, (int)$conv->id, (int)$messageid, $websearch, $material);

$options = tutor::generation_options((int)$USER->id);
// Une réponse abandonnée en cours de génération n'est pas coupée au serveur
// IA (le load balancer garderait sa place bloquée) : elle est lue jusqu'au
// bout sans être transmise.
$options['drain_on_abort'] = true;

$buffer    = '';
$lastsave  = time();
$aborted   = false;
$lbjobid   = '';

// Identifiant du job au load balancer (un par tour de génération) : le
// widget s'en sert, via ajax.php, pour afficher le rang dans la file.
$options['onjobid'] = function($jobid) use ($messageid, &$lbjobid) {
    $lbjobid = (string)$jobid;
    conversation::attach_lbjob($messageid, $jobid);
    local_aichat_sse('queued', array('messageid' => (int)$messageid));
};
// Battement du load balancer pendant l'attente : relayé au navigateur (garde
// la connexion ouverte à travers les proxys) ; c'est aussi le moment de
// détecter que l'élève est parti. Si sa question attend encore son tour, la
// connexion est coupée et le load balancer libère la place ; si la
// génération a déjà commencé, on la laisse finir.
$options['onwait'] = function() use (&$aborted, &$lbjobid) {
    if (!$aborted) {
        echo ": wait\n\n";
        flush();
    }
    if (!connection_aborted()) {
        return true;
    }
    $aborted = true;
    try {
        $job = \local_aifeedback\lb\client::get_job($lbjobid);
        return !(isset($job['status']) && $job['status'] === 'queued');
    } catch (\Throwable $e) {
        return true;
    }
};

// Recherche ou lecture lancée : le widget l'affiche (sinon quelques secondes
// de silence). $kind : 'web' (requête) ou 'read' (domaine de la page lue).
$onsearch = function($label, $kind = 'web') {
    local_aichat_sse('search', array('q' => (string)$label, 'tool' => (string)$kind));
};

try {
    $result = generator::run($messages, $options,
        function($delta) use (&$buffer, &$lastsave, &$aborted, $messageid) {
            // L'élève a fermé l'onglet ou cliqué « Arrêter » : la suite de la
            // réponse n'est plus transmise (voir drain_on_abort).
            if ($aborted) {
                return false;
            }
            $buffer .= $delta;
            local_aichat_sse('delta', array('t' => $delta));
            if (connection_aborted()) {
                $aborted = true;
                return false;
            }

            // Sauvegarde du partiel : si ce PHP meurt, rien n'est perdu.
            $now = time();
            if ($now - $lastsave >= 5) {
                $lastsave = $now;
                conversation::store_partial($messageid, $buffer);
            }
            return true;
        }, $onsearch, $tool, $forcetool);

    // Recherches de cette réponse : quota de l'élève et transcription.
    conversation::record_searches($messageid, $tool, $websearch);

    // Quota : la question (fichiers joints compris) et ce que l'élève a vu de
    // la réponse (voir quota).
    $asked    = conversation::student_message((int)$conv->id, (int)$messageid);
    $question = ($asked !== null) ? (string)$asked->content : '';
    $filetokens = ($asked !== null) ? \local_aichat\attachment::tokens_for_message((int)$asked->id) : 0;

    // Arrêt demandé pendant que le flux continuait (bouton « Arrêter ») :
    // traité comme un abandon.
    $cancelled = ($DB->get_field('local_aichat_message', 'status', array('id' => $messageid)) === 'cancelled');
    if (!empty($result['aborted']) || $aborted || $cancelled) {
        conversation::store_partial($messageid, $buffer);
        conversation::fail_message($messageid, 'cancelled', null, $access->context);
        quota::record($messageid, $question, $buffer, $filetokens);
        exit;
    }

    conversation::finish_message($messageid, $result['content'], $access->context, 'done');
    quota::record($messageid, $question, (string)$result['content'], $filetokens);

    $updated = $DB->get_record('local_aichat_message', array('id' => $messageid));
    local_aichat_sse('done', array(
        'messageid' => (int)$messageid,
        'html'      => (string)$updated->contenthtml,
        'quota'     => (array)quota::check($USER->id),
    ));

} catch (\Throwable $e) {
    // Le load balancer réessaie lui-même avant le premier fragment : si l'on
    // arrive ici, la demande a été refusée (quota, file saturée), a échoué,
    // ou la panne est survenue en cours de réponse.
    $detail = ($e instanceof \moodle_exception && !empty($e->debuginfo))
        ? (string)$e->debuginfo : (string)$e->getMessage();
    debugging('local_aichat: échec du flux — ' . $detail, DEBUG_DEVELOPER);

    // Les recherches déjà faites comptent, même si la réponse a échoué.
    conversation::record_searches($messageid, $tool, $websearch);
    conversation::store_partial($messageid, $buffer);

    // Annulé entre-temps (bouton « Arrêter », nouvelle discussion) : le load
    // balancer a interrompu la génération à notre demande.
    $current = $DB->get_field('local_aichat_message', 'status', array('id' => $messageid));
    if ($current === 'cancelled') {
        $asked = conversation::student_message((int)$conv->id, (int)$messageid);
        quota::record($messageid, ($asked !== null) ? (string)$asked->content : '', $buffer,
            ($asked !== null) ? \local_aichat\attachment::tokens_for_message((int)$asked->id) : 0);
        conversation::fail_message($messageid, 'cancelled', null, $access->context);
        exit;
    }

    $errorkey = 'error_llm';
    if ($e instanceof lb_exception) {
        if ($e->is_quota()) {
            $errorkey = 'error_lbquota';
        } else if ($e->is_busy()) {
            $errorkey = 'error_lbbusy';
        }
    }
    conversation::fail_message($messageid, 'failed',
        get_string($errorkey, 'local_aichat'), $access->context);

    local_aichat_sse('error', array(
        'message' => get_string($errorkey, 'local_aichat'),
    ));
}
