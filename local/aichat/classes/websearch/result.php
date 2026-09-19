<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Résultat d'une recherche Web, indépendant du fournisseur.
 *
 * Une recherche ne lève jamais d'exception vers le tuteur : un échec est un
 * résultat comme un autre, avec une raison que le modèle et l'administrateur
 * peuvent comprendre.
 */
class result {

    /** Suspension jusqu'à une intervention de l'administrateur (clé refusée). */
    const BLOCK_MANUAL = -1;

    /** @var bool la recherche a abouti */
    public $ok = false;

    /** @var string raison de l'échec (voir tool::REASONS), '' si ok */
    public $reason = '';

    /** @var array[] résultats {title, url, snippet, age} */
    public $items = array();

    /**
     * @var bool le fournisseur a pu facturer la requête. Dans le doute
     * (délai dépassé, coupure en cours de réponse) on considère que oui.
     */
    public $billable = false;

    /**
     * @var int suspension conseillée du fournisseur, en secondes : 0 = aucune,
     * self::BLOCK_MANUAL = jusqu'à remise en service par l'administrateur.
     */
    public $blockfor = 0;

    /** @var array|null limites annoncées par le fournisseur (en-têtes) */
    public $ratelimit = null;

    /** @var string diagnostic pour l'administrateur (jamais la clé API) */
    public $detail = '';

    /**
     * @param array[]    $items
     * @param array|null $ratelimit
     * @return self
     */
    public static function success(array $items, $ratelimit = null) {
        $r = new self();
        $r->ok        = true;
        $r->items     = $items;
        $r->billable  = true;
        $r->ratelimit = $ratelimit;
        return $r;
    }

    /**
     * @param string     $reason
     * @param bool       $billable
     * @param int        $blockfor
     * @param string     $detail
     * @param array|null $ratelimit
     * @return self
     */
    public static function failure($reason, $billable, $blockfor = 0, $detail = '', $ratelimit = null) {
        $r = new self();
        $r->reason    = (string)$reason;
        $r->billable  = (bool)$billable;
        $r->blockfor  = (int)$blockfor;
        $r->detail    = (string)$detail;
        $r->ratelimit = $ratelimit;
        return $r;
    }
}
