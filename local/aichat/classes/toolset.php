<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Un ou plusieurs outils proposés au modèle pour UNE réponse du tuteur.
 *
 * generator::run() ne connaît que cette interface : un outil seul
 * (websearch\tool, reader\tool) ou un assemblage (toolbox) s'utilisent de la
 * même façon. Chaque implémentation porte les compteurs de la réponse en
 * cours et ne lève jamais d'exception vers le tuteur.
 */
interface toolset {

    /** Définitions au format OpenAI (champ « tools » de la requête). */
    public function definitions();

    /** Appels d'outils autorisés pour cette réponse. */
    public function maxcalls();

    /** La limite est-elle atteinte ? (plus d'outil à proposer) */
    public function exhausted();

    /**
     * Exécute un appel demandé par le modèle.
     *
     * @param string        $name
     * @param string|array  $argsjson arguments bruts du modèle
     * @param callable|null $onsearch function(string $label, string $kind) :
     *                                statut affiché à l'élève
     * @return string contenu du message « tool » renvoyé au modèle
     */
    public function execute($name, $argsjson, $onsearch = null);

    /** @return array[] pages à citer {title, url, kind: read|search} */
    public function sources();

    /** @return array[] journal pour la transcription enseignant */
    public function log();

    /** @return int[] {websearches: recherches facturables, pagereads: lectures} */
    public function counters();
}
