<?php
namespace qtype_aishortanswer;

defined('MOODLE_INTERNAL') || die();

/**
 * Handler de correction IA pour qtype_aishortanswer.
 *
 * Toute la mécanique vit dans \local_aifeedback\quiz_grader. Ici, schéma JSON
 * ALLÉGÉ (niveau + score + court feedback) et prompt qui impose un retour bref.
 */
class job_handler extends \local_aifeedback\quiz_grader {

    protected static function component_name(): string {
        return 'qtype_aishortanswer';
    }

    /**
     * Schéma allégé : pas de listes ni de tableau de compétences. La concision
     * est portée par la forme réduite du schéma + le prompt (on évite maxLength,
     * non supporté en mode strict par certains backends).
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
        $p .= "Tu évalues une RÉPONSE COURTE (un mot, une définition, une phrase) à une question.\n\n";
        $p .= "Les niveaux possibles sont STRICTEMENT :\n";
        $p .= "- \"Maîtrise insuffisante\"\n- \"Maîtrise fragile\"\n";
        $p .= "- \"Maîtrise satisfaisante\"\n- \"Très bonne maîtrise\"\n\n";
        $p .= "Barème indicatif :\n";
        $p .= "- Très bonne maîtrise (80-100) : réponse exacte et complète.\n";
        $p .= "- Maîtrise satisfaisante (50-79) : globalement correcte, imprécision mineure.\n";
        $p .= "- Maîtrise fragile (25-49) : partiellement correcte.\n";
        $p .= "- Maîtrise insuffisante (0-24) : fausse ou hors sujet.\n\n";
        $p .= "Compare la réponse de l'étudiant au corrigé attendu fourni. ";
        $p .= "Donne un feedback BREF et DIRECT, en 1 à 2 phrases maximum, sans liste ni énumération. ";
        $p .= "La structure de ta réponse JSON est imposée par le schéma fourni dans la requête.";
        return $p;
    }
}
