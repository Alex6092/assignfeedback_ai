<?php
namespace local_aichat\reader;

defined('MOODLE_INTERNAL') || die();

/**
 * Adresses que read_page a le droit de lire pendant UNE réponse.
 *
 * Le modèle ne choisit pas librement ce que le serveur télécharge : il ne peut
 * lire qu'une page trouvée par web_search dans cette réponse, un document lié
 * depuis une page déjà lue, ou une adresse donnée par l'élève lui-même dans son
 * message. (Les adresses internes sont en plus bloquées par le « security
 * helper » de la classe curl de Moodle, voir fetcher.)
 */
class allowlist {

    /** Au-delà, les ajouts sont ignorés (une réponse n'en lit que quelques-unes). */
    const MAX = 100;

    /** @var bool[] adresses normalisées */
    private $urls = array();

    /**
     * Ajoute une adresse http(s).
     *
     * @param string $url
     */
    public function add($url) {
        $key = self::normalise($url);
        if ($key !== '' && count($this->urls) < self::MAX) {
            $this->urls[$key] = true;
        }
    }

    /**
     * Ajoute les adresses http(s) écrites par l'élève dans son message.
     *
     * @param string $text
     */
    public function add_from_text($text) {
        if (preg_match_all('#https?://[^\s<>"\'\)\]]+#i', (string)$text, $matches)) {
            foreach ($matches[0] as $url) {
                $this->add(rtrim($url, '.,;:!?'));
            }
        }
    }

    /** @return bool l'adresse peut-elle être lue ? */
    public function allows($url) {
        $key = self::normalise($url);
        return $key !== '' && isset($this->urls[$key]);
    }

    /** @return int nombre d'adresses lisibles */
    public function count() {
        return count($this->urls);
    }

    /**
     * Forme comparable d'une adresse : http(s) uniquement, sans fragment,
     * hôte en minuscules, sans barre finale. '' si l'adresse est refusée
     * (autre schéma, identifiants dans l'URL, trop longue).
     *
     * @param string $url
     * @return string
     */
    public static function normalise($url) {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 2000 || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $out = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . (int)$parts['port'] : '')
            . (isset($parts['path']) ? rtrim($parts['path'], '/') : '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return $out;
    }
}
