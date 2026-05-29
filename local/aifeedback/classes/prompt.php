<?php
namespace local_aifeedback;

defined('MOODLE_INTERNAL') || die();

/**
 * Fragments de prompt partagés et réglages associés.
 */
class prompt {

    /**
     * Consigne d'accessibilité (tolérance orthographique pour les élèves dys)
     * à ajouter au prompt système, ou chaîne vide si le réglage global est
     * désactivé.
     *
     * Le réglage local_aifeedback/spelling_tolerance est activé par défaut ;
     * tant qu'il n'a pas été explicitement enregistré, on le considère ON.
     */
    public static function accessibility_suffix(): string {
        $enabled = get_config('local_aifeedback', 'spelling_tolerance');
        if ($enabled === false) {
            $enabled = 1; // défaut ON tant que non configuré
        }
        if (empty($enabled)) {
            return '';
        }
        $c  = "\n\nIMPORTANT — Accessibilité : n'évalue JAMAIS l'orthographe, la grammaire, ";
        $c .= "la conjugaison ni la syntaxe. Certains élèves présentent des troubles spécifiques ";
        $c .= "des apprentissages (dyslexie, dysorthographie, dysphasie). Note EXCLUSIVEMENT la ";
        $c .= "justesse des concepts, du raisonnement et des connaissances. Une réponse correcte ";
        $c .= "sur le fond mais comportant des fautes d'orthographe ou de formulation doit obtenir ";
        $c .= "la même note qu'une réponse sans faute.";
        return $c;
    }
}
