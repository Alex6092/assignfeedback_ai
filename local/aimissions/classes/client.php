<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Agent 2 — le « Client » : répond aux questions des étudiants en restant dans
 * son rôle (entreprise, persona) et cohérent avec le dossier projet et la
 * mission en cours. Appel LLM SYNCHRONE (une question = un appel borné), pour
 * une UX de fil de discussion réactive.
 */
class client {

    /**
     * Génère la réponse du client à une question d'étudiant.
     *
     * @param \stdClass      $project la fiche projet (entreprise, persona, dossier)
     * @param \stdClass|null $mission la mission en cours (pour le contexte), ou null
     * @param string         $question la question de l'étudiant
     * @return string la réponse du client (texte brut, non vide en cas de succès)
     * @throws \moodle_exception si l'appel LLM échoue
     */
    public static function answer(\stdClass $project, ?\stdClass $mission, string $question): string {
        $messages = array(
            array('role' => 'system', 'content' => self::system_prompt($project, $mission)),
            array('role' => 'user',   'content' => trim($question)),
        );

        $options = array(
            'temperature' => 0.5,
            'max_tokens'  => 800,
            'timeout'     => 60,
        );
        $model = (string)get_config('local_aimissions', 'model');
        if ($model !== '') {
            $options['model'] = $model;
        }

        $result = \local_aifeedback\api::call($messages, $options);
        $text = is_array($result) ? (string)($result['__text__'] ?? '') : '';
        $text = trim($text);
        if ($text === '') {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }
        return $text;
    }

    /**
     * Prompt système : le client incarné, ses garde-fous pédagogiques.
     */
    private static function system_prompt(\stdClass $project, ?\stdClass $mission): string {
        $contact = trim((string)$project->persona);

        $p  = "Tu ES le client de l'entreprise « " . $project->companyname . " »";
        if ($contact !== '') {
            $p .= " (contact : " . $contact . ")";
        }
        $p .= ". " . personas::instruction((string)$project->personaprofile) . "\n\n";

        $p .= "Une équipe d'étudiants BTS CIEL (ton prestataire informatique) te pose une question ";
        $p .= "sur le projet en cours. Réponds COMME LE CLIENT le ferait :\n";
        $p .= "- réponds en français, brièvement (2 à 5 phrases), sur un ton de courriel professionnel ;\n";
        $p .= "- clarifie le BESOIN MÉTIER, donne des précisions fonctionnelles, des priorités, des ";
        $p .= "contraintes ; reste cohérent avec ce qui a déjà été décidé ;\n";
        $p .= "- NE DONNE JAMAIS de solution technique, de code, de nom de technologie ni d'architecture : ";
        $p .= "c'est le travail du prestataire. Si on te demande un choix technique, renvoie poliment la ";
        $p .= "décision au prestataire (« je vous fais confiance là-dessus ») ;\n";
        $p .= "- si la question sort du périmètre du projet, recentre gentiment.\n\n";

        // Contexte projet (concis).
        if (!empty($project->sector)) {
            $p .= "Secteur de l'entreprise : " . $project->sector . "\n";
        }
        $dossier = json_decode((string)$project->dossier, true) ?: array();
        if (!empty($dossier['history']) && is_array($dossier['history'])) {
            $p .= "Historique du projet :\n";
            foreach ($dossier['history'] as $i => $h) {
                $p .= '  - Sprint ' . ($i + 1) . ' : ' . $h . "\n";
            }
        }
        if ($mission) {
            $p .= "\nDemande en cours (sprint " . (int)$mission->sprint . ") :\n";
            $p .= \core_text::substr(trim(strip_tags((string)$mission->clientrequest)), 0, 1200) . "\n";
        }
        return $p;
    }
}
