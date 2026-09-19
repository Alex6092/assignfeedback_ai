<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Point d'entrée de la recherche Web du tuteur : réglages, choix du
 * fournisseur et disponibilité pour une réponse donnée.
 */
class manager {

    /** Valeurs par défaut (voir settings.php). */
    const DEFAULT_CAP        = 900;
    const DEFAULT_PERUSER    = 10;
    const DEFAULT_MAXCALLS   = 2;
    const DEFAULT_MAXRESULTS = 5;
    const DEFAULT_TIMEOUT    = 6;
    const DEFAULT_CACHEDAYS  = 7;

    /** Interrupteur du site (administrateur). */
    public static function site_enabled() {
        return !empty(get_config('local_aichat', 'websearch_enabled'));
    }

    /**
     * Recherche autorisée sur cette activité : interrupteur du site ET case de
     * l'enseignant.
     *
     * @param \stdClass|null $config ligne de {local_aichat_activity}
     * @return bool
     */
    public static function activity_enabled($config) {
        return self::site_enabled() && $config !== null && !empty($config->websearch);
    }

    /**
     * Fournisseur configuré. Un seul pour l'instant ; un nouveau moteur
     * s'ajoute ici et dans le menu des réglages.
     *
     * @return provider
     */
    public static function provider() {
        switch ((string)get_config('local_aichat', 'websearch_provider')) {
            case 'brave':
            default:
                return new brave_provider();
        }
    }

    /**
     * Clé API en clair. Stockée chiffrée (admin_setting_encryptedpassword) :
     * jamais réaffichée dans les réglages, jamais envoyée au navigateur.
     *
     * @return string '' si absente ou indéchiffrable
     */
    public static function apikey() {
        $raw = (string)get_config('local_aichat', 'websearch_apikey');
        if ($raw === '') {
            return '';
        }
        try {
            return trim((string)\core\encryption::decrypt($raw));
        } catch (\Throwable $e) {
            return ''; // clé de chiffrement du site changée : la saisir à nouveau
        }
    }

    /** Plafond du site sur 31 jours glissants (0 = aucune recherche). */
    public static function cap() {
        return self::int_setting('websearch_cap', self::DEFAULT_CAP, 0, PHP_INT_MAX);
    }

    /** Plafond par élève et par fenêtre du quota (0 = pas de limite). */
    public static function peruser() {
        return self::int_setting('websearch_peruser', self::DEFAULT_PERUSER, 0, PHP_INT_MAX);
    }

    /** Recherches maximales pour une même réponse du tuteur. */
    public static function maxcalls() {
        return self::int_setting('websearch_maxcalls', self::DEFAULT_MAXCALLS, 1, 5);
    }

    /** Résultats transmis au modèle par recherche. */
    public static function maxresults() {
        return self::int_setting('websearch_maxresults', self::DEFAULT_MAXRESULTS, 1, 10);
    }

    /** Validité du cache des recherches, en jours (0 = pas de cache). */
    public static function cachedays() {
        return self::int_setting('websearch_cachedays', self::DEFAULT_CACHEDAYS, 0, 90);
    }

    /** Délai total d'une requête au fournisseur, en secondes. */
    public static function timeout() {
        return self::int_setting('websearch_timeout', self::DEFAULT_TIMEOUT, 2, 20);
    }

    /**
     * La recherche peut-elle être PROPOSÉE au modèle pour cette réponse ?
     *
     * Ce qu'on sait déjà impossible n'est pas proposé : cela évite un tour de
     * génération inutile (le serveur relirait tout le prompt pour rien).
     *
     * @param \stdClass|null $config ligne de {local_aichat_activity}
     * @param int            $userid
     * @return string '' = disponible ; 'disabled' = non activée (le tuteur
     *                n'en entend pas parler) ; sinon raison d'indisponibilité
     *                (signalée au modèle dans le prompt)
     */
    public static function availability($config, $userid) {
        if (!self::activity_enabled($config)) {
            return 'disabled';
        }
        if (!self::provider()->is_configured()) {
            return 'not_configured';
        }
        if (budget::blocked() !== null) {
            return 'provider_unavailable';
        }
        $cap = self::cap();
        if ($cap <= 0 || budget::used() >= $cap) {
            return 'quota_exhausted';
        }
        $peruser = self::peruser();
        if ($peruser > 0 && budget::user_used($userid) >= $peruser) {
            return 'user_quota_exhausted';
        }
        return '';
    }

    /**
     * Outil prêt pour UNE réponse du tuteur (il porte les compteurs de cette
     * réponse : recherches tentées, facturées, journal).
     *
     * @param \stdClass $user l'élève (son identité est retirée des requêtes)
     * @return tool
     */
    public static function new_tool(\stdClass $user) {
        return new tool(self::provider(), $user, self::maxcalls(), self::maxresults(),
            budget::user_used((int)$user->id), self::peruser(), self::cap());
    }

    /**
     * Libellé d'une raison d'indisponibilité (transcriptions, diagnostic).
     *
     * @param string $reason
     * @return string
     */
    public static function reason_label($reason) {
        if (in_array($reason, tool::REASONS, true)) {
            return get_string('ws_reason_' . $reason, 'local_aichat');
        }
        return (string)$reason;
    }

    private static function int_setting($name, $default, $min, $max) {
        $value = get_config('local_aichat', $name);
        if ($value === false || $value === '' || $value === null) {
            $value = $default;
        }
        return (int)max($min, min($max, (int)$value));
    }
}
