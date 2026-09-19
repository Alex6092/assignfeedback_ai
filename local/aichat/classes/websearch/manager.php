<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Point d'entrée de la recherche Web du tuteur : réglages, moteurs de
 * l'élève et disponibilité pour une réponse donnée.
 *
 * Chaque élève cherche avec SES clés (userkeys), dans l'ordre Tavily puis
 * Brave. Sans clé, le tuteur ne va pas sur Internet pour lui. Les clés du
 * plugin (réglages) ne servent qu'aux tests de la page de diagnostic.
 */
class manager {

    /** Valeurs par défaut (voir settings.php). */
    const DEFAULT_CAP        = 900;  // par clé Brave, sur 31 jours
    const DEFAULT_TAVILYCAP  = 1000; // par clé Tavily, sur 31 jours
    const DEFAULT_PERUSER    = 10;
    const DEFAULT_MAXCALLS   = 2;
    const DEFAULT_MAXRESULTS = 5;
    const DEFAULT_TIMEOUT    = 6;
    const DEFAULT_CACHEDAYS  = 7;

    /** Mode « recherche de matériel » (voir MODE_MATERIAL). */
    const DEFAULT_TOOLCALLS       = 5;
    const DEFAULT_READSPERUSER    = 30;
    const DEFAULT_MAXMB           = 8;
    const DEFAULT_READTIMEOUT     = 10;
    const DEFAULT_PAGECACHEHOURS  = 24;

    /** Recherches du tuteur sur une activité ({local_aichat_activity}.websearch). */
    const MODE_NONE     = 0;
    const MODE_WEB      = 1; // recherche Web ponctuelle
    const MODE_MATERIAL = 2; // recherche de matériel : web_search + read_page

    /** Sites de référence gardés au plus par activité. */
    const MAX_SITES = 15;

    /** Nom affiché de chaque moteur. */
    const NAMES = array('tavily' => 'Tavily', 'brave' => 'Brave Search');

    /** Interrupteur du site (administrateur). */
    public static function site_enabled() {
        return !empty(get_config('local_aichat', 'websearch_enabled'));
    }

    /**
     * Mode de recherche effectif d'une activité (MODE_NONE si la recherche
     * est désactivée pour le site).
     *
     * @param \stdClass|null $config ligne de {local_aichat_activity}
     * @return int
     */
    public static function mode($config) {
        if (!self::site_enabled() || $config === null || empty($config->websearch)) {
            return self::MODE_NONE;
        }
        return ((int)$config->websearch === self::MODE_MATERIAL) ? self::MODE_MATERIAL : self::MODE_WEB;
    }

    /**
     * Recherche autorisée sur cette activité : interrupteur du site ET choix
     * de l'enseignant (l'élève doit en plus avoir une clé).
     *
     * @param \stdClass|null $config ligne de {local_aichat_activity}
     * @return bool
     */
    public static function activity_enabled($config) {
        return self::mode($config) !== self::MODE_NONE;
    }

    /**
     * L'activité permet la recherche mais l'élève n'a aucune clé : le widget
     * lui indique où l'ajouter.
     *
     * @param \stdClass|null $config
     * @param int            $userid
     * @return bool
     */
    public static function needs_keys($config, $userid) {
        return self::activity_enabled($config) && !userkeys::has_any($userid);
    }

    /**
     * Sites de référence donnés par l'enseignant (un domaine par ligne ; les
     * lignes qui ne sont pas des noms de domaine sont ignorées).
     *
     * @param \stdClass|null $config
     * @return string[]
     */
    public static function sites($config) {
        if ($config === null || empty($config->websearchsites)) {
            return array();
        }
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string)$config->websearchsites) as $site) {
            $site = strtolower(trim(preg_replace('#^https?://#i', '', $site), " /"));
            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $site)) {
                $out[$site] = $site;
            }
            if (count($out) >= self::MAX_SITES) {
                break;
            }
        }
        return array_values($out);
    }

    /**
     * Fabrique un moteur pour une clé.
     *
     * @param string $provider tavily|brave
     * @param string $key      clé en clair
     * @return provider
     */
    public static function provider($provider, $key) {
        if ($provider === 'tavily') {
            return new tavily_provider($key, self::timeout());
        }
        return new brave_provider($key, self::timeout());
    }

    /**
     * Clé de TEST du site (page de diagnostic uniquement : jamais utilisée
     * pour un élève). Stockée chiffrée, jamais réaffichée.
     *
     * @param string $provider tavily|brave
     * @return string '' si absente ou indéchiffrable
     */
    public static function site_key($provider) {
        $name = ($provider === 'tavily') ? 'websearch_tavilykey' : 'websearch_apikey';
        $raw  = (string)get_config('local_aichat', $name);
        if ($raw === '') {
            return '';
        }
        try {
            return trim((string)\core\encryption::decrypt($raw));
        } catch (\Throwable $e) {
            return ''; // clé de chiffrement du site changée : la saisir à nouveau
        }
    }

    /**
     * Plafond d'une clé sur 31 jours glissants. Brave : 0 = moteur inutilisé
     * (il débite la carte au-delà des crédits). Tavily : 0 = pas de plafond
     * local (sans carte, il refuse au lieu de facturer).
     *
     * @param string $provider
     * @return int
     */
    public static function keycap($provider) {
        if ($provider === 'tavily') {
            $cap = self::int_setting('websearch_tavilycap', self::DEFAULT_TAVILYCAP, 0, PHP_INT_MAX);
            return ($cap > 0) ? $cap : PHP_INT_MAX;
        }
        return self::int_setting('websearch_cap', self::DEFAULT_CAP, 0, PHP_INT_MAX);
    }

    /**
     * Moteurs de l'élève, dans l'ordre d'essai (Tavily, puis Brave).
     *
     * @param int $userid
     * @return \stdClass[] {id, name, provider, keyhash, cap, userid}
     */
    public static function engines($userid) {
        $out = array();
        foreach (userkeys::PROVIDERS as $id) {
            $key = userkeys::get($userid, $id);
            $cap = self::keycap($id);
            if ($key === '' || $cap <= 0) {
                continue;
            }
            $out[] = (object)array(
                'id'       => $id,
                'name'     => self::NAMES[$id],
                'provider' => self::provider($id, $key),
                'keyhash'  => userkeys::keyhash($id, $key),
                'cap'      => $cap,
                'userid'   => (int)$userid,
            );
        }
        return $out;
    }

    /**
     * Pourquoi un moteur de l'élève est inutilisable en ce moment, ou ''.
     *
     * @param \stdClass $engine voir engines()
     * @return string
     */
    public static function engine_unavailable(\stdClass $engine) {
        if (budget::blocked($engine->id) !== null) {
            return 'provider_unavailable';
        }
        $state = userkeys::state($engine->userid, $engine->id);
        if ($state !== null) {
            return (string)$state->status;
        }
        if (budget::used($engine->id, $engine->keyhash) >= $engine->cap) {
            return 'quota_exhausted';
        }
        return '';
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

    /** Délai total d'une requête au moteur, en secondes. */
    public static function timeout() {
        return self::int_setting('websearch_timeout', self::DEFAULT_TIMEOUT, 2, 20);
    }

    /** Appels d'outils par réponse en mode matériel (recherches + lectures). */
    public static function toolcalls() {
        return self::int_setting('websearch_toolcalls', self::DEFAULT_TOOLCALLS, 2, 8);
    }

    /** Lectures de pages par élève et par fenêtre du quota (0 = pas de limite). */
    public static function readsperuser() {
        return self::int_setting('websearch_readsperuser', self::DEFAULT_READSPERUSER, 0, PHP_INT_MAX);
    }

    /** Taille maximale d'un document lu, en octets. */
    public static function maxbytes() {
        return self::int_setting('websearch_maxmb', self::DEFAULT_MAXMB, 1, 50) * 1048576;
    }

    /** Délai de téléchargement d'une page, en secondes. */
    public static function readtimeout() {
        return self::int_setting('websearch_readtimeout', self::DEFAULT_READTIMEOUT, 3, 30);
    }

    /** Validité du cache des pages lues, en heures (0 = pas de cache). */
    public static function pagecachehours() {
        return self::int_setting('websearch_pagecachehours', self::DEFAULT_PAGECACHEHOURS, 0, 720);
    }

    /**
     * La recherche peut-elle être PROPOSÉE au modèle pour cette réponse ?
     *
     * Ce qu'on sait déjà impossible n'est pas proposé : cela évite un tour de
     * génération inutile (le serveur relirait tout le prompt pour rien).
     *
     * @param \stdClass|null $config ligne de {local_aichat_activity}
     * @param int            $userid
     * @return string '' = disponible ; 'disabled' = activité sans recherche OU
     *                élève sans clé (le tuteur n'en entend pas parler) ;
     *                sinon raison d'indisponibilité (signalée au modèle)
     */
    public static function availability($config, $userid) {
        if (!self::activity_enabled($config)) {
            return 'disabled';
        }
        $engines = self::engines($userid);
        if (empty($engines)) {
            return 'disabled';
        }
        $reason = '';
        foreach ($engines as $engine) {
            $why = self::engine_unavailable($engine);
            if ($why === '') {
                $reason = '';
                break;
            }
            if ($reason === '') {
                $reason = $why; // raison du moteur prioritaire
            }
        }
        if ($reason !== '') {
            return $reason;
        }
        $peruser = self::peruser();
        if ($peruser > 0 && budget::user_used($userid) >= $peruser) {
            return 'user_quota_exhausted';
        }
        return '';
    }

    /**
     * Outil prêt pour UNE réponse du tuteur (il porte les compteurs de cette
     * réponse : recherches tentées, décomptées, journal).
     *
     * @param \stdClass      $user   l'élève (ses clés ; son identité est retirée des requêtes)
     * @param \stdClass|null $config activité (statistiques par activité)
     * @return tool
     */
    public static function new_tool(\stdClass $user, $config = null) {
        return new tool(self::engines((int)$user->id), $user, self::maxcalls(), self::maxresults(),
            budget::user_used((int)$user->id), self::peruser(),
            ($config !== null && !empty($config->cmid)) ? (int)$config->cmid : 0);
    }

    /**
     * Lecture de pages disponible pour cet élève ? (mode matériel)
     *
     * @param int $userid
     * @return string '' ou 'reads_exhausted'
     */
    public static function reader_availability($userid) {
        $peruser = self::readsperuser();
        if ($peruser > 0 && budget::user_reads($userid) >= $peruser) {
            return 'reads_exhausted';
        }
        return '';
    }

    /**
     * Outil read_page pour UNE réponse du tuteur.
     *
     * @param \local_aichat\reader\allowlist $allowlist adresses lisibles
     * @param \stdClass                      $user
     * @return \local_aichat\reader\tool
     */
    public static function new_reader(\local_aichat\reader\allowlist $allowlist, \stdClass $user) {
        return new \local_aichat\reader\tool($allowlist,
            new \local_aichat\reader\fetcher(self::maxbytes(), self::readtimeout()),
            self::toolcalls(), budget::user_reads((int)$user->id), self::readsperuser(),
            self::pagecachehours() * HOURSECS);
    }

    /**
     * Libellé d'une raison d'indisponibilité (transcriptions, diagnostic).
     *
     * @param string $reason
     * @return string
     */
    public static function reason_label($reason) {
        if (in_array($reason, tool::REASONS, true) || in_array($reason, \local_aichat\reader\tool::REASONS, true)) {
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
