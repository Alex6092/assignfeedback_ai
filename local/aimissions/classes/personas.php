<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Profils psychologiques du client fictif. Partagé par la génération (Agent 1),
 * la correction (systemprompt de assignfeedback_ai) et les réponses aux tickets
 * (Agent 2), pour que le client reste cohérent partout.
 */
class personas {

    /**
     * Consigne de ton injectée dans les prompts pour un profil donné.
     */
    public static function instruction(string $code): string {
        switch ($code) {
            case 'exigeant':
                return "client EXIGEANT : attentes élevées, ton ferme, insiste sur la qualité et les délais.";
            case 'imprecis':
                return "client IMPRÉCIS : besoin formulé de façon floue, quelques ambiguïtés volontaires "
                     . "que l'étudiant devra lever (sans rendre la demande incompréhensible).";
            case 'versatile':
                return "client VERSATILE : mentionne qu'il a déjà changé d'avis par le passé, ton hésitant.";
            case 'lent':
                return "client peu disponible : ton bref, peu de détails, comme quelqu'un de pressé.";
            case 'nontechnique':
                return "client NON TECHNIQUE : vocabulaire courant uniquement, analogies du quotidien, "
                     . "aucune notion informatique.";
            case 'neutre':
            default:
                return "client coopératif et clair, ton professionnel et bienveillant.";
        }
    }
}
