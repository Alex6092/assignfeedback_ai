<?php
/**
 * MoodleSearch — demande d'ouverture du site d'un clic pour la classe de
 * l'élève (page d'attente de go.php). Répond en JSON.
 *
 * L'appel au pare-feu peut durer plusieurs secondes (reconfiguration
 * d'OPNsense) : c'est pour cela qu'il se fait ici, derrière une page d'attente.
 *
 * @package local_moodlesearch
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_moodlesearch\clicks;
use local_moodlesearch\opnsense_bridge;
use local_moodlesearch\output;

require_login(null, false);
require_sesskey();

$clickid = required_param('clickid', PARAM_INT);
$click = clicks::get($clickid, (int)$USER->id);

header('Content-Type: application/json; charset=utf-8');
if (!$click) {
    echo json_encode(array('status' => 'invalid') + output::click_outcome('invalid'));
    exit;
}

// Une seule demande par clic (page d'attente rechargée : même réponse).
$status = (string)$click->opnstatus;
if ($status === '') {
    \core_php_time_limit::raise(90);
    $status = opnsense_bridge::open((int)$USER->id, (string)$click->url);
    clicks::set_status($clickid, $status);
}
echo json_encode(array('status' => $status) + output::click_outcome($status));
