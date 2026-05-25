<?php
namespace assignfeedback_ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers de chiffrement/déchiffrement pour les secrets stockés en base
 * (typiquement la clé API). Cette classe est volontairement autonome :
 * elle ne dépend ni de mod_assign ni de la classe principale, pour pouvoir
 * être chargée depuis n'importe quel contexte (settings admin notamment).
 */
class secret {

    /** Préfixe interne pour reconnaître une valeur déjà chiffrée. */
    const PREFIX = '__enc1__:';

    /**
     * Chiffre une chaîne via \core\encryption (sodium).
     * Retombe sur la valeur en clair si l'extension sodium n'est pas dispo.
     */
    public static function encrypt($plain) {
        if ($plain === null || $plain === '') {
            return '';
        }
        try {
            return self::PREFIX . \core\encryption::encrypt((string)$plain);
        } catch (\Throwable $e) {
            debugging('assignfeedback_ai: chiffrement indisponible, stockage en clair ('
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
                debugging('assignfeedback_ai: déchiffrement échoué (' . $e->getMessage() . ')',
                    DEBUG_DEVELOPER);
                return '';
            }
        }
        return (string)$value;
    }
}
