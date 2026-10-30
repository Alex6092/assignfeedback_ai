<?php
namespace local_aifeedback\lb;

defined('MOODLE_INTERNAL') || die();

/**
 * Vérification des callbacks signés par le load balancer.
 *
 * En-têtes reçus :
 *   - X-Callback-Timestamp : secondes Unix, renouvelé à chaque tentative ;
 *   - X-Callback-Signature : « sha256= » suivi du HMAC-SHA256 hexadécimal de
 *     timestamp . "." . corps JSON brut, avec le secret de callback de
 *     l'application.
 *
 * Classe sans dépendance à Moodle : testable isolément.
 */
class signature {

    /** Écart maximal toléré entre l'horloge du load balancer et la nôtre (secondes). */
    const MAX_SKEW = 300;

    /**
     * Signature attendue pour un corps et un horodatage.
     *
     * @param string $secret
     * @param string $timestamp
     * @param string $body corps brut, tel que reçu
     * @return string « sha256=… »
     */
    public static function sign($secret, $timestamp, $body) {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, (string)$secret);
    }

    /**
     * Le callback est-il authentique et récent ? À appeler AVANT de décoder le
     * corps. Comparaison à temps constant.
     *
     * @param string $body      corps brut
     * @param string $timestamp en-tête X-Callback-Timestamp
     * @param string $signature en-tête X-Callback-Signature
     * @param string $secret    secret de callback de l'application
     * @param int    $now       horodatage courant
     * @return bool
     */
    public static function verify($body, $timestamp, $signature, $secret, $now) {
        $timestamp = (string)$timestamp;
        if ((string)$secret === '' || $timestamp === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs((int)$now - (int)$timestamp) > self::MAX_SKEW) {
            return false;
        }
        return hash_equals(self::sign($secret, $timestamp, (string)$body), (string)$signature);
    }
}
