<?php
namespace local_aibridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Journal des appels au pont : qui, quelle fonction, sur quel cours ou
 * quelle activité, avec quel résultat et en combien de temps.
 */
class audit {

    /** Longueur maximale des paramètres et des erreurs conservés. */
    const MAXLEN = 2000;

    /**
     * Exécute $fn et journalise l'appel, réussi ou non. L'exception éventuelle
     * est relancée telle quelle.
     *
     * @param string   $function nom court (get_course...)
     * @param array    $params   paramètres validés
     * @param int      $courseid
     * @param int      $cmid
     * @param callable $fn
     * @return mixed ce que renvoie $fn
     */
    public static function run(string $function, array $params, int $courseid, int $cmid, callable $fn) {
        $start = microtime(true);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::write($function, $params, $courseid, $cmid, 'error', $e->getMessage(), $start);
            throw $e;
        }
        self::write($function, $params, $courseid, $cmid, 'ok', null, $start);
        return $result;
    }

    private static function write(string $function, array $params, int $courseid, int $cmid,
            string $status, ?string $error, float $start): void {
        global $DB, $USER;
        try {
            $DB->insert_record('local_aibridge_log', (object)array(
                'userid'      => (int)$USER->id,
                'function'    => $function,
                'courseid'    => $courseid,
                'cmid'        => $cmid,
                'params'      => \core_text::substr(json_encode(redactor::clean($params),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, self::MAXLEN),
                'status'      => $status,
                'error'       => $error === null ? null : \core_text::substr($error, 0, self::MAXLEN),
                'durationms'  => (int)round((microtime(true) - $start) * 1000),
                'timecreated' => time(),
            ));
        } catch (\Throwable $e) {
            // Le journal ne doit jamais faire échouer un appel.
            debugging('local_aibridge : journal impossible : ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
