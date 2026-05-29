<?php
namespace qtype_aiessay;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de correction IA pour qtype_aiessay.
 *
 * Toute la mécanique (file, retry, découplage LLM/note, notation quiz) vit dans
 * la base partagée \local_aifeedback\quiz_grader. Ici on ne fournit que ce qui
 * est propre à la composition : le schéma JSON de sortie (allégé, aligné sur
 * ce que la carte rend réellement) et le prompt système par défaut.
 */
class job_handler extends \local_aifeedback\quiz_grader {

    protected static function component_name(): string {
        return 'qtype_aiessay';
    }

    /**
     * Schéma JSON allégé : niveau + score + feedback détaillé. On a
     * volontairement retiré points_forts / points_a_ameliorer / tableau de
     * compétences car la carte rendue côté UI ne les affiche pas — les
     * générer ralentirait la réponse et consommerait du contexte pour rien.
     */
    protected function response_schema(): array {
        $levels = array(
            'Maîtrise insuffisante',
            'Maîtrise fragile',
            'Maîtrise satisfaisante',
            'Très bonne maîtrise',
        );
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'niveau'   => array('type' => 'string', 'enum' => $levels),
                'score'    => array('type' => 'integer', 'minimum' => 0, 'maximum' => 100),
                'feedback' => array('type' => 'string'),
            ),
            'required' => array('niveau', 'score', 'feedback'),
        );
    }

    protected function default_system_prompt(): string {
        $p  = "Tu es un correcteur pédagogique spécialisé dans l'enseignement supérieur ";
        $p .= "technologique français (BTS Informatique / BTS CIEL).\n";
        $p .= "Tu évalues une réponse rédigée à un exercice court.\n\n";
        $p .= "Les niveaux possibles sont STRICTEMENT :\n";
        $p .= "- \"Maîtrise insuffisante\"\n- \"Maîtrise fragile\"\n";
        $p .= "- \"Maîtrise satisfaisante\"\n- \"Très bonne maîtrise\"\n\n";
        $p .= "Critères :\n";
        $p .= "- Très bonne maîtrise (80-100) : réponse complète, concepts corrects.\n";
        $p .= "- Maîtrise satisfaisante (50-79) : notions principales comprises, quelques imprécisions.\n";
        $p .= "- Maîtrise fragile (25-49) : compréhension partielle.\n";
        $p .= "- Maîtrise insuffisante (0-24) : hors sujet ou erreurs majeures.\n\n";
        $p .= "Reste factuel et pédagogique, ne pénalise pas les fautes mineures de français ";
        $p .= "si les concepts techniques sont corrects. La structure de ta réponse JSON est ";
        $p .= "imposée par le schéma fourni dans la requête.";
        return $p;
    }
}
