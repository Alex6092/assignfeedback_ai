<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers de chiffrement/déchiffrement pour les secrets stockés en base
 * (typiquement la clé API). Cette classe est volontairement autonome : elle
 * ne dépend de rien d'autre que \core\encryption.
 */
class secret {

    /** Préfixe interne pour reconnaître une valeur déjà chiffrée. */
    const PREFIX = '__enc1__:';

    /**
     * Chiffre une chaîne via \core\encryption (sodium).
     * Retombe sur la valeur en clair si sodium n'est pas dispo (avec debug log).
     */
    public static function encrypt($plain) {
        if ($plain === null || $plain === '') {
            return '';
        }
        try {
            return self::PREFIX . \core\encryption::encrypt((string)$plain);
        } catch (\Throwable $e) {
            debugging('local_aifeedback: chiffrement indisponible, stockage en clair ('
                . $e->getMessage() . ')', DEBUG_DEVELOPER);
            return (string)$plain;
        }
    }

    /**
     * Déchiffre une valeur précédemment chiffrée. Si elle n'a pas le préfixe
     * (legacy ou fallback), la retourne telle quelle.
     */
    public static function decrypt($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (strpos($value, self::PREFIX) === 0) {
            try {
                return \core\encryption::decrypt(substr($value, strlen(self::PREFIX)));
            } catch (\Throwable $e) {
                debugging('local_aifeedback: déchiffrement échoué (' . $e->getMessage() . ')',
                    DEBUG_DEVELOPER);
                return '';
            }
        }
        return (string)$value;
    }
}
