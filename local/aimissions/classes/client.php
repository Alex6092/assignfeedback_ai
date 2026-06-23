<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Agent 2 — le « Client » : répond (en DIFFÉRÉ, via un job) à un LOT de messages
 * d'étudiants, en restant dans son rôle (entreprise, persona) et cohérent avec
 * le dossier projet et la mission en cours. Le client juge AUSSI, en personnage,
 * si le ton ou le harcèlement de relances justifie un recadrage, voire la
 * rupture de la collaboration.
 */
class client {

    /**
     * Génère la réponse du client à un lot de messages + sa réaction.
     *
     * @param \stdClass      $project la fiche projet (entreprise, persona, dossier, clientstatus)
     * @param \stdClass|null $mission la mission en cours (contexte), ou null
     * @param array          $batch   tickets en attente (anciens → récents), objets {question, userid, timecreated}
     * @param array          $metrics métriques de relance : count, senders, mininterval, sincefirst (secondes)
     * @return array{reply:string, reaction:string} reaction ∈ {none, warning, ended}
     * @throws \moodle_exception si l'appel LLM échoue
     */
    public static function respond(\stdClass $project, ?\stdClass $mission, array $batch, array $metrics): array {
        $messages = array(
            array('role' => 'system', 'content' => self::system_prompt($project, $mission)),
            array('role' => 'user',   'content' => self::user_prompt($batch, $metrics)),
        );
        $options = array(
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'local_aimissions_reply',
                    'strict' => true,
                    'schema' => self::schema(),
                ),
            ),
            'temperature' => 0.5,
            'max_tokens'  => 800,
        );
        $model = (string)get_config('local_aimissions', 'model');
        if ($model !== '') {
            $options['model'] = $model;
        }

        $result = \local_aifeedback\api::call($messages, $options);
        if (!is_array($result) || empty($result['reply'])) {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }
        $reaction = (isset($result['reaction'])
            && in_array($result['reaction'], array('none', 'warning', 'ended'), true))
            ? $result['reaction'] : 'none';

        return array('reply' => trim((string)$result['reply']), 'reaction' => $reaction);
    }

    /**
     * Schéma JSON strict : la réponse + la réaction du client.
     */
    private static function schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'reply'    => array('type' => 'string'),
                'reaction' => array('type' => 'string', 'enum' => array('none', 'warning', 'ended')),
            ),
            'required' => array('reply', 'reaction'),
        );
    }

    /**
     * Prompt système : le client incarné, ses garde-fous, et sa capacité à réagir.
     */
    private static function system_prompt(\stdClass $project, ?\stdClass $mission): string {
        $contact = trim((string)$project->persona);

        $p  = "Tu ES le client de l'entreprise « " . $project->companyname . " »";
        if ($contact !== '') {
            $p .= " (contact : " . $contact . ")";
        }
        $p .= ". " . personas::instruction((string)$project->personaprofile) . "\n\n";

        $p .= "Une équipe d'étudiants BTS CIEL (ton prestataire informatique) t'a écrit. Tu réponds ";
        $p .= "MAINTENANT, en une seule fois, à l'ensemble de leurs messages. Réponds COMME LE CLIENT :\n";
        $p .= "- en français, brièvement (2 à 5 phrases), ton de courriel professionnel ;\n";
        $p .= "- clarifie le BESOIN MÉTIER (précisions fonctionnelles, priorités, contraintes), cohérent ";
        $p .= "avec ce qui a déjà été décidé ;\n";
        $p .= "- NE DONNE JAMAIS de solution technique, de code, de techno ni d'architecture : c'est le ";
        $p .= "travail du prestataire. Renvoie poliment les choix techniques (« je vous fais confiance »).\n\n";

        $p .= "RÉACTION (champ \"reaction\") — juge selon TON CARACTÈRE :\n";
        $p .= "- échange normal → \"none\".\n";
        $p .= "- ton irrespectueux, OU HARCÈLEMENT (plusieurs messages très rapprochés sans te laisser le ";
        $p .= "temps de répondre) → recadre poliment mais fermement → \"warning\".\n";
        $p .= "- comportement gravement déplacé, ou qui PERSISTE après un recadrage → tu peux METTRE FIN à ";
        $p .= "la collaboration → \"ended\" (tu ne répondras plus).\n";
        $p .= "IMPORTANT : une relance POLIE après une longue attente est LÉGITIME — ne la sanctionne pas. ";
        $p .= "Adapte ta tolérance à ton persona.\n";
        if ((string)$project->clientstatus === 'warned') {
            $p .= "Note : tu as DÉJÀ recadré ce prestataire au moins une fois. Si le problème persiste, ";
            $p .= "n'hésite pas à rompre.\n";
        }
        $p .= "\n";

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

    /**
     * Prompt utilisateur : le lot de messages + le contexte de relance.
     */
    private static function user_prompt(array $batch, array $metrics): string {
        $now = time();
        $u = "Messages reçus du prestataire (du plus ancien au plus récent) :\n";
        foreach ($batch as $t) {
            $ago = self::human_delay($now - (int)$t->timecreated);
            $u .= '- (il y a ' . $ago . ') ' . trim((string)$t->question) . "\n";
        }

        $count = (int)($metrics['count'] ?? count($batch));
        if ($count > 1) {
            $u .= "\nContexte : le prestataire a envoyé " . $count . " messages SANS attendre ta réponse";
            if (!empty($metrics['mininterval'])) {
                $u .= " (le plus rapproché à " . self::human_delay((int)$metrics['mininterval']) . " d'intervalle)";
            }
            $u .= ". Tiens-en compte pour ta réaction.\n";
        } else {
            $sincefirst = (int)($metrics['sincefirst'] ?? 0);
            $u .= "\nContexte : un seul message, envoyé il y a " . self::human_delay($sincefirst) . ".\n";
        }
        $u .= "\nRédige ta réponse et choisis ta réaction selon le schéma JSON imposé.";
        return $u;
    }

    /**
     * Formate une durée en secondes de façon lisible et courte (fr).
     */
    private static function human_delay(int $sec): string {
        $sec = max(0, $sec);
        if ($sec < 90) {
            return $sec . ' s';
        }
        if ($sec < 5400) {
            return max(1, (int)round($sec / 60)) . ' min';
        }
        if ($sec < 172800) {
            return max(1, (int)round($sec / 3600)) . ' h';
        }
        return max(1, (int)round($sec / 86400)) . ' j';
    }
}
