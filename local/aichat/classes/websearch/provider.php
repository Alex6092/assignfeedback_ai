<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Fournisseur de recherche Web.
 *
 * Tout ce qui est propre à un moteur (URL, authentification, format de
 * réponse, codes d'erreur, en-têtes de quota) reste dans son implémentation :
 * le reste du tuteur ne manipule que des result. Pour ajouter un moteur, il
 * suffit d'une nouvelle implémentation, d'une entrée dans manager::provider()
 * et dans userkeys::PROVIDERS (ordre d'essai).
 */
interface provider {

    /** Nom affiché (administration, mention de la source). */
    public function name(): string;

    /** Le fournisseur a-t-il ce qu'il faut pour être appelé (clé API) ? */
    public function is_configured(): bool;

    /**
     * Lance une recherche. Ne lève jamais d'exception : tout échec est rendu
     * sous la forme d'un result en échec.
     *
     * @param string $query requête déjà nettoyée et bornée par tool
     * @param int    $count nombre de résultats souhaités
     * @return result
     */
    public function search(string $query, int $count): result;
}
