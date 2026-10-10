<?php
namespace local_aibridge\extension;

defined('MOODLE_INTERNAL') || die();

/**
 * Adaptateur d'un plugin qui ajoute des réglages à une activité (Correction
 * IA, Tuteur IA, EFE, activités surveillées...).
 *
 * Chaque adaptateur est optionnel à l'exécution : si le plugin n'est pas
 * installé, is_available() renvoie false et le pont l'ignore. Il ne lit
 * jamais de donnée d'élève.
 */
interface extension {

    /** Clé de l'extension dans les réponses (aifeedback, aichat, efe...). */
    public function key(): string;

    /** Composant Moodle lu (assignfeedback_ai, local_aichat...). */
    public function component(): string;

    /** Le plugin est-il installé (tables présentes) ? */
    public function is_available(): bool;

    /**
     * Réglages de l'activité, ou null si le plugin n'est pas réglé pour elle.
     *
     * @param \cm_info $cm
     * @return array|null
     */
    public function read(\cm_info $cm): ?array;

    /**
     * Résumé d'une ligne pour le plan du cours (ex. « EFE C04 »), ou null.
     *
     * @param array $data ce que read() a renvoyé
     */
    public function summary(array $data): ?string;
}
