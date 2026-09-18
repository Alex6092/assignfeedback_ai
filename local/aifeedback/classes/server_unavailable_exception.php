<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Échec imputable au SERVEUR LLM (et non à la requête) : il justifie de
 * retenter sur un autre serveur du pool.
 *
 * Même code d'erreur et même message que l'échec d'appel ordinaire
 * ('apicallfailed') : les handlers qui attrapent \Throwable, et les messages
 * d'erreur qu'ils enregistrent, restent strictement identiques.
 *
 * Deux natures, traitées différemment par pool::failover() :
 *   - UNREACHABLE : impossible de joindre le serveur (DNS, connexion refusée,
 *     délai de connexion). Le serveur est en panne : quarantaine, puis
 *     basculement sur chacun des autres serveurs si besoin.
 *   - SERVER_ERROR : le serveur a été joint mais a échoué (HTTP 5xx, réponse
 *     vide, connexion coupée en cours). Ce peut être la requête elle-même
 *     (une copie « empoisonnée » qui fait planter le modèle) : pas de
 *     quarantaine, un seul nouvel essai sur un autre serveur.
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
