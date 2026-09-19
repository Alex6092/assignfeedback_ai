<?php
namespace local_aichat\reader;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php'); // classe curl

/**
 * Téléchargement d'une page ou d'un document pour read_page.
 *
 * Sécurité (le modèle ne doit pas pouvoir faire télécharger n'importe quoi au
 * serveur) :
 *   - http/https uniquement, sans identifiants dans l'URL (allowlist) ;
 *   - classe curl de Moodle : son « security helper » refuse les hôtes et
 *     plages d'adresses bloqués ($CFG->curlsecurityblockedhosts, qui couvre par
 *     défaut localhost et les réseaux privés) et les ports non autorisés
 *     ($CFG->curlsecurityallowedport), à chaque redirection ;
 *   - taille bornée (Content-Length annoncé ET octets reçus) et délai borné.
 */
class fetcher {

    /** @var int */
    private $maxbytes;

    /** @var int */
    private $timeout;

    /**
     * @param int $maxbytes taille maximale d'un document
     * @param int $timeout  délai total, en secondes
     */
    public function __construct($maxbytes, $timeout) {
        $this->maxbytes = max(10000, (int)$maxbytes);
        $this->timeout  = max(2, (int)$timeout);
    }

    /**
     * @param string $url
     * @return \stdClass {ok, reason, detail, body, type (html|pdf|text), title}
     */
    public function fetch($url) {
        if (allowlist::normalise($url) === '') {
            return self::failure('url_not_allowed', 'adresse refusée');
        }

        $max  = $this->maxbytes;
        $curl = new \curl();
        $curl->setHeader(array(
            'Accept: text/html,application/xhtml+xml,application/pdf;q=0.9,text/plain;q=0.8,*/*;q=0.5',
            'Accept-Language: fr,en;q=0.8',
        ));
        $body = $curl->get($url, array(), array(
            'CURLOPT_TIMEOUT'        => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_USERAGENT'      => 'Mozilla/5.0 (compatible; MoodleAITutor/1.0)',
            'CURLOPT_MAXFILESIZE'    => $max,
            // Coupe le transfert dès que la taille est dépassée, même sans
            // Content-Length annoncé.
            'CURLOPT_NOPROGRESS'       => false,
            'CURLOPT_PROGRESSFUNCTION' => function($resource, $dltotal, $dlnow) use ($max) {
                return ($dlnow > $max || $dltotal > $max) ? 1 : 0;
            },
        ));

        $errno    = (int)$curl->get_errno();
        $info     = is_array($curl->info) ? $curl->info : array();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;

        if ($errno === 28) {
            return self::failure('timeout', 'délai dépassé (' . $this->timeout . ' s)');
        }
        if ($errno === 63 || $errno === 42) {
            return self::failure('too_large', 'plus de ' . round($max / 1048576, 1) . ' Mo');
        }
        if ($errno !== 0) {
            return self::failure('fetch_error', 'réseau : curl ' . $errno . ' ' . (string)$curl->error);
        }
        if ($httpcode === 0) {
            // Pas de requête HTTP du tout : adresse bloquée par le security
            // helper de Moodle (hôte interne, port interdit…).
            return self::failure('fetch_error', 'adresse bloquée par la politique de sécurité du site');
        }
        if ($httpcode !== 200) {
            return self::failure('fetch_error', 'HTTP ' . $httpcode);
        }
        $body = (string)$body;
        if (strlen($body) > $max) {
            return self::failure('too_large', 'plus de ' . round($max / 1048576, 1) . ' Mo');
        }
        if (trim($body) === '') {
            return self::failure('empty_content', 'réponse vide');
        }

        $contenttype = isset($info['content_type']) ? strtolower((string)$info['content_type']) : '';
        if (strncmp($body, '%PDF-', 5) === 0) {
            $type = 'pdf';
        } else if (strpos($contenttype, 'html') !== false
                || preg_match('/^\s*(<!doctype html|<html|<head|<body|<div|<\?xml)/i', $body)) {
            $type = 'html';
        } else if (strpos($contenttype, 'text/plain') === 0) {
            $type = 'text';
        } else {
            return self::failure('unsupported_type', $contenttype !== '' ? $contenttype : 'type inconnu');
        }

        return (object)array('ok' => true, 'reason' => '', 'detail' => '', 'body' => $body, 'type' => $type);
    }

    private static function failure($reason, $detail) {
        return (object)array('ok' => false, 'reason' => $reason, 'detail' => (string)$detail,
            'body' => '', 'type' => '');
    }
}
