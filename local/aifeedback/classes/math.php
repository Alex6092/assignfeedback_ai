<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Petits utilitaires numériques partagés.
 */
class math {

    /**
     * Arrondit une valeur au quart supérieur le plus proche.
     *
     *   4.12 → 4.25
     *   3.05 → 3.25
     *   2.65 → 2.75
     *   5.00 → 5.00
     *   0.00 → 0.00
     *
     * Utilisé pour convertir une note brute du LLM (mark calculé sur la note
     * max d'une question) en valeur affichable par incréments de 0.25.
     */
    public static function round_up_quarter($value) {
        $value = (float)$value;
        if ($value <= 0) {
            return 0.0;
        }
        return ceil($value * 4) / 4;
    }
}
