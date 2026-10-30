<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler factice pour les tests de la file (\local_aifeedback\jobs) :
 * enregistre les appels reçus.
 */
class job_handler implements async_handler {

    /** @var array appels reçus */
    public static $calls = array();

    /** @var int|null délai renvoyé par fail() */
    public static $faildelay = 60;

    public function build_request(\stdClass $payload): ?request {
        self::$calls[] = array('build', $payload);
        return new request(array(array('role' => 'user', 'content' => 'x')),
            array('response_format' => array('type' => 'json_schema')), 'modele', 7);
    }

    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass {
        self::$calls[] = array('complete', $payload, $result, $usage);
        return null;
    }

    public function fail(\stdClass $payload, string $error, bool $permanent): ?int {
        self::$calls[] = array('fail', $payload, $error, $permanent);
        return self::$faildelay;
    }

    public function pending_payloads(int $before): array {
        return array();
    }
}
