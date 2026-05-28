<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Contrat que chaque plugin consommateur doit implémenter pour pouvoir
 * insérer des jobs dans la queue partagée de local_aifeedback.
 *
 * Le routage se fait par nom de classe complet, sérialisé dans le customdata
 * de la tâche ad-hoc. Le dispatcher (\local_aifeedback\task\run_job) instancie
 * la classe via autoload Moodle puis appelle execute($payload).
 *
 * Convention : la classe d'implémentation s'appelle
 * \{frankenstyle_component}\job_handler (ex: \assignfeedback_ai\job_handler).
 */
interface job_handler {

    /**
     * Traite UN job. Le lock LLM global est déjà acquis par le dispatcher au
     * moment de l'appel — pas besoin de le re-prendre.
     *
     * L'implémentation doit :
     *   - gérer ses propres compteurs d'attempts et son passage en status=failed
     *     en cas d'échec répété ;
     *   - throw en cas d'erreur transitoire (l'appelant n'enregistre rien
     *     d'autre que le faildelay Moodle classique).
     *
     * @param \stdClass $payload structure libre fournie au moment du enqueue
     * @return void
     * @throws \Throwable en cas d'erreur transitoire
     */
    public function execute(\stdClass $payload): void;

    /**
     * Permet à l'implémentation de signaler s'il y a d'autres jobs du même
     * type en attente et de fournir leur payload pour drainage immédiat dans
     * le même tick (tant qu'on tient le lock LLM).
     *
     * Retourner un tableau vide arrête le drainage côté handler — le
     * dispatcher continuera quand même à chercher des jobs d'AUTRES handlers
     * dans la queue ad-hoc Moodle.
     *
     * @return \stdClass[] payloads des jobs à exécuter immédiatement après
     */
    public function find_drainable_payloads(): array;
}
