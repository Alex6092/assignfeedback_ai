<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Échec imputable au SERVEUR joint (load balancer ou API externe), et non à
 * la requête : la même demande pourra réussir plus tard.
 *
 * Même code d'erreur et même message que l'échec d'appel ordinaire
 * ('apicallfailed') : les handlers qui attrapent \Throwable, et les messages
 * d'erreur qu'ils enregistrent, restent strictement identiques.
 *
 * Deux natures :
 *   - UNREACHABLE : impossible de joindre le serveur (DNS, connexion refusée,
 *     délai de connexion) ;
 *   - SERVER_ERROR : le serveur a été joint mais a échoué (HTTP 5xx, réponse
 *     vide, connexion coupée en cours).
 */
class server_unavailable_exception extends \moodle_exception {

    const UNREACHABLE  = 'unreachable';
    const SERVER_ERROR = 'servererror';

    /** @var string self::UNREACHABLE ou self::SERVER_ERROR */
    public $kind;

    /**
     * @param string $kind      self::UNREACHABLE ou self::SERVER_ERROR
     * @param string $debuginfo détail technique (code, URL, modèle)
     */
    public function __construct($kind, $debuginfo) {
        $this->kind = (string)$kind;
        parent::__construct('apicallfailed', 'local_aifeedback', '', null, $debuginfo);
    }
}
