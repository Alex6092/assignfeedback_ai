<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Clés d'API personnelles de l'élève pour la recherche Web du tuteur.
 *
 * Chaque élève renseigne ses propres clés (page « mes clés de recherche ») :
 * le lycée ne paie rien, et chaque clé a son propre plafond. Sans clé, le
 * tuteur ne va pas sur Internet pour cet élève.
 *
 * Stockage : préférences utilisateur, valeur chiffrée par \core\encryption
 * (comme la clé de test du site). Une clé n'est jamais réaffichée ni envoyée
 * au navigateur ou au modèle ; on n'en montre que les 4 derniers caractères.
 */
class userkeys {

    /** Moteurs, dans l'ordre d'essai : Tavily (gratuit, sans carte), puis Brave. */
    const PROVIDERS = array('tavily', 'brave');

    /** Préfixes des préférences. */
    const PREF_KEY   = 'local_aichat_key_';
    const PREF_STATE = 'local_aichat_keystate_';

    /**
     * Clé en clair d'un élève, ou '' si absente ou indéchiffrable.
     *
     * @param int    $userid
     * @param string $provider
     * @return string
     */
    public static function get($userid, $provider) {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return '';
        }
        $raw = (string)get_user_preferences(self::PREF_KEY . $provider, '', (int)$userid);
        if ($raw === '') {
            return '';
        }
        try {
            return trim((string)\core\encryption::decrypt($raw));
        } catch (\Throwable $e) {
            return ''; // clé de chiffrement du site changée : l'élève doit la ressaisir
        }
    }

    /**
     * Enregistre (ou retire, si vide) la clé d'un élève. Une nouvelle clé
     * efface l'état de l'ancienne (suspension, crédits épuisés).
     *
     * @param int    $userid
     * @param string $provider
     * @param string $key
     */
    public static function set($userid, $provider, $key) {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return;
        }
        $key = trim(preg_replace('/\s+/', '', (string)$key));
        if ($key === '') {
            self::remove($userid, $provider);
            return;
        }
        set_user_preference(self::PREF_KEY . $provider, \core\encryption::encrypt($key), (int)$userid);
        self::clear_state($userid, $provider);
    }

    /** Retire la clé d'un élève et son état. */
    public static function remove($userid, $provider) {
        unset_user_preference(self::PREF_KEY . $provider, (int)$userid);
        self::clear_state($userid, $provider);
    }

    /**
     * Moteurs pour lesquels l'élève a une clé, dans l'ordre d'essai.
     *
     * @param int $userid
     * @return string[]
     */
    public static function providers($userid) {
        $out = array();
        foreach (self::PROVIDERS as $provider) {
            if (self::get($userid, $provider) !== '') {
                $out[] = $provider;
            }
        }
        return $out;
    }

    /** L'élève a-t-il au moins une clé ? */
    public static function has_any($userid) {
        return !empty(self::providers($userid));
    }

    /** Affichage d'une clé : seulement ses 4 derniers caractères. */
    public static function masked($key) {
        $key = (string)$key;
        return (strlen($key) <= 4) ? '…' : '…' . substr($key, -4);
    }

    /**
     * Empreinte d'une clé, pour compter sa consommation dans le registre sans
     * la stocker ni la rattacher à un élève.
     *
     * @param string $provider
     * @param string $key
     * @return string
     */
    public static function keyhash($provider, $key) {
        return sha1((string)$provider . '|' . (string)$key);
    }

    /**
     * État d'une clé : suspendue (refusée par le moteur) ou crédits épuisés,
     * tant que ce n'est pas expiré.
     *
     * @param int    $userid
     * @param string $provider
     * @return \stdClass|null {status, until (-1 = jusqu'à modification), detail, time}
     */
    public static function state($userid, $provider) {
        $raw   = (string)get_user_preferences(self::PREF_STATE . $provider, '', (int)$userid);
        $state = json_decode($raw);
        if (!is_object($state) || empty($state->status)) {
            return null;
        }
        $until = isset($state->until) ? (int)$state->until : 0;
        if ($until !== -1 && $until <= time()) {
            return null;
        }
        return $state;
    }

    /**
     * Mémorise l'état d'une clé après un refus du moteur.
     *
     * @param int    $userid
     * @param string $provider
     * @param string $status  raison (auth_error, provider_quota…)
     * @param int    $seconds durée, ou result::BLOCK_MANUAL (jusqu'à modification de la clé)
     * @param string $detail
     */
    public static function set_state($userid, $provider, $status, $seconds, $detail = '') {
        $until = ((int)$seconds === result::BLOCK_MANUAL) ? -1 : time() + max(60, (int)$seconds);
        set_user_preference(self::PREF_STATE . $provider, json_encode(array(
            'status' => (string)$status,
            'until'  => $until,
            'detail' => substr((string)$detail, 0, 200),
            'time'   => time(),
        )), (int)$userid);
    }

    /** Efface l'état d'une clé (nouvelle clé, test réussi). */
    public static function clear_state($userid, $provider) {
        unset_user_preference(self::PREF_STATE . $provider, (int)$userid);
    }
}
