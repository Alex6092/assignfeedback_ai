<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Contrat OPTIONNEL d'un job_handler dont certains jobs visent une API
 * imposée par l'activité (URL surchargée : OpenAI, Anthropic…) plutôt que
 * les serveurs du pool.
 *
 * La file doit le savoir AVANT de réserver une place : un job destiné à une
 * API externe ne doit ni occuper un serveur du lycée, ni attendre qu'un
 * serveur du lycée se libère, ni être bloqué par leur panne.
 */
interface routable_job_handler {

    /**
     * URL imposée par l'activité pour ce job, ou null si le job doit être
     * traité par les serveurs du pool.
     *
     * Doit être rapide et sans effet de bord (simple lecture en base) : elle
     * est appelée par la file avant chaque job.
     *
     * @param \stdClass $payload
     * @return string|null
     */
    public function external_endpoint(\stdClass $payload): ?string;
}
