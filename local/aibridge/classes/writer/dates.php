<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Dates reçues de l'agent : « AAAA-MM-JJTHH:MM », heure du serveur (Paris).
 */
class dates {

    /**
     * @param mixed  $value horodatage, chaîne « AAAA-MM-JJTHH:MM », ou vide (0)
     * @param string $field nom du champ, pour le message d'erreur
     */
    public static function parse($value, string $field): int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }
        $date = \DateTime::createFromFormat('Y-m-d\TH:i', trim((string)$value),
            \core_date::get_server_timezone_object());
        if ($date === false) {
            throw new \moodle_exception('error_date', 'local_aibridge', '', $field . ' = ' . $value);
        }
        return $date->getTimestamp();
    }
}
