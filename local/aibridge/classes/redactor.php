<?php
namespace local_aibridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Masquage des secrets dans tout ce que le pont renvoie.
 *
 * Une clé est masquée quand son nom, ou son dernier segment après « _ », est
 * un nom de secret : apikey, api_key, password, secret, token, ...key. Les
 * drapeaux associés restent lisibles (apikey_override, sessioncode). Une
 * valeur vide est renvoyée telle quelle, pour qu'on sache que rien n'est
 * réglé ; sinon elle est remplacée par MASK.
 */
class redactor {

    /** Valeur renvoyée à la place d'un secret renseigné. */
    const MASK = '[masqué]';

    /** Noms de secrets : apikey, api_key, api_secret, password, tavilykey... */
    const PATTERN = '/(^|_)(apikey|api_key|api_secret|secret|password|passwd|token|wstoken|key|[a-z]+key)$/i';

    /**
     * La clé désigne-t-elle un secret ?
     *
     * @param int|string $key
     */
    public static function is_secret_key($key): bool {
        return is_string($key) && preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * Copie de $value sans secrets. Les objets deviennent des tableaux
     * associatifs (même rendu JSON).
     *
     * @param mixed $value
     * @return mixed
     */
    public static function clean($value) {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = array();
        foreach ($value as $key => $item) {
            if (self::is_secret_key($key) && !is_array($item) && !is_object($item)) {
                $out[$key] = ($item === null || $item === '') ? $item : self::MASK;
            } else {
                $out[$key] = self::clean($item);
            }
        }
        return $out;
    }
}
