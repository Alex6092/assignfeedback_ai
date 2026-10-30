<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Contrat d'un plugin qui confie des générations à la file partagée
 * (\local_aifeedback\jobs → load balancer).
 *
 * Le traitement d'un job est découpé en deux temps, parce que la génération
 * est faite AILLEURS et plus tard (job asynchrone du load balancer) :
 *   1. build_request() prépare la demande (lecture de la copie, prompts…) ;
 *   2. complete() applique le résultat quand il revient (callback du load
 *      balancer, ou réconciliation périodique), en dehors de toute session
 *      utilisateur (l'utilisateur courant est l'administrateur, comme en cron).
 *
 * Le routage se fait par nom de classe : \{frankenstyle}\job_handler
 * (ex. \assignfeedback_ai\job_handler). Les payloads sont de petits objets
 * sérialisables (identifiants de lignes métier).
 */
interface async_handler {

    /**
     * Demande de génération pour ce payload.
     *
     * @param \stdClass $payload
     * @return request|null null = plus rien à faire (ligne supprimée, déjà
     *                      traitée, résultat déjà disponible et appliqué)
     * @throws \Throwable erreur de préparation (comptée comme un échec du job)
     */
    public function build_request(\stdClass $payload): ?request;

    /**
     * Applique le résultat de la génération.
     *
     * @param \stdClass  $payload
     * @param array      $result JSON décodé, ou ['__text__' => string]
     * @param array|null $usage  {prompt_tokens, completion_tokens} si connu
     * @return \stdClass|null payload d'une ÉTAPE SUIVANTE à générer (même
     *                        composant), ou null si le job est terminé
     * @throws \Throwable si le résultat ne peut pas être appliqué (compté comme un échec)
     */
    public function complete(\stdClass $payload, array $result, ?array $usage): ?\stdClass;

    /**
     * Enregistre un échec (compteurs, message visible de l'enseignant).
     *
     * @param \stdClass $payload
     * @param string    $error     message technique
     * @param bool      $permanent vrai si la même demande échouera encore
     *                             (modèle inconnu, contexte trop long…)
     * @return int|null délai avant un nouvel essai (secondes), ou null pour
     *                  abandonner définitivement
     */
    public function fail(\stdClass $payload, string $error, bool $permanent): ?int;

    /**
     * Travaux en attente depuis longtemps sans job actif (mise en file perdue,
     * incident) : la réconciliation périodique les remet en file.
     *
     * @param int $before ne renvoyer que ce qui attend depuis avant cet horodatage
     * @return \stdClass[] payloads
     */
    public function pending_payloads(int $before): array;
}
