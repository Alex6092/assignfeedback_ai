<?php
define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
            !hash_equals('Bearer ' . \local_notebook\client::secret('moodletoken'), $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($body) > 8192) {
        throw new invalid_parameter_exception('Request too large');
    }
    $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (($data['action'] ?? '') === 'exchange') {
        echo json_encode(\local_notebook\manager::exchange((string)$data['code']), JSON_THROW_ON_ERROR);
    } else if (($data['action'] ?? '') === 'access') {
        $lease = $DB->get_record('local_notebook_launch', ['lease' => (string)$data['lease'], 'used' => 1]);
        echo json_encode(['allowed' => $lease && \local_notebook\manager::allowed($lease->cmid, $lease->userid, (bool)$lease->teacher)]);
    } else {
        throw new invalid_parameter_exception('Unknown action');
    }
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Request refused']);
}
