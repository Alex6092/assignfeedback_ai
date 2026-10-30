<?php
namespace local_aifeedback\lb;

defined('MOODLE_INTERNAL') || die();

/**
 * Refus ou échec renvoyé par le load balancer.
 *
 * Distingue ce qui se résout en attendant (quota de tokens, file saturée,
 * service momentanément injoignable : la demande sera simplement renvoyée
 * plus tard, sans compter comme un échec du job) de ce qui ne se résoudra pas
 * seul (modèle inconnu, contexte trop long, clé refusée : le job échoue).
 */
class lb_exception extends \moodle_exception {

    /** @var int code HTTP (0 = pas de réponse) */
    public $httpstatus;

    /** @var string code d'erreur du load balancer (ex. quota_exceeded) */
    public $lbcode;

    /** @var bool vrai si la même demande peut réussir plus tard */
    public $transient;

    /** @var int délai conseillé avant de réessayer (secondes, 0 = inconnu) */
    public $retryafter;

    /**
     * @param int    $httpstatus
     * @param string $lbcode
     * @param string $message   message lisible (renvoyé par le load balancer)
     * @param bool   $transient
     * @param int    $retryafter
     * @param string $debuginfo
     */
    public function __construct($httpstatus, $lbcode, $message, $transient, $retryafter = 0, $debuginfo = '') {
        $this->httpstatus = (int)$httpstatus;
        $this->lbcode     = (string)$lbcode;
        $this->transient  = (bool)$transient;
        $this->retryafter = max(0, (int)$retryafter);
        $text = ($this->httpstatus > 0 ? 'HTTP ' . $this->httpstatus . ' ' : '')
            . ($this->lbcode !== '' ? '[' . $this->lbcode . '] ' : '') . (string)$message;
        parent::__construct('lb_error', 'local_aifeedback', '', $text, $debuginfo);
    }

    /** Quota de tokens dépassé (application, clé ou utilisateur). */
    public function is_quota() {
        return $this->httpstatus === 429;
    }

    /** File du load balancer saturée. */
    public function is_busy() {
        return $this->httpstatus === 503;
    }
}
