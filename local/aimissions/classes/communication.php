<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Évaluation de la COMMUNICATION CLIENT d'un étudiant (compétence transversale
 * type C01 « Communiquer »), à partir des messages qu'il a adressés au client
 * (tickets) et des réponses obtenues. Produit une couleur EFE + un commentaire.
 */
class communication {

    /**
     * Évalue la communication d'un étudiant.
     *
     * @param \stdClass $project la fiche projet (entreprise, contexte)
     * @param array     $tickets tickets de l'étudiant (objets {question, answer})
     * @return array{colour:string, score:int, comment:string}
     * @throws \moodle_exception si l'appel LLM échoue
     */
    public static function evaluate_student(\stdClass $project, array $tickets): array {
        $convo = '';
        foreach ($tickets as $t) {
            $q = trim((string)$t->question);
            if ($q === '') {
                continue;
            }
            $convo .= "Message de l'étudiant : " . $q . "\n";
            $a = trim((string)$t->answer);
            if ($a !== '') {
                $convo .= "Réponse du client : " . $a . "\n";
            }
            $convo .= "\n";
        }
        if (trim($convo) === '') {
            // Aucun message exploitable : pas d'évaluation possible.
            throw new \moodle_exception('comm_notickets', 'local_aimissions');
        }

        $messages = array(
            array('role' => 'system', 'content' => self::system_prompt($project)),
            array('role' => 'user',   'content' => "Échanges de l'étudiant avec le client :\n\n" . $convo),
        );
        $options = array(
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'local_aimissions_comm',
                    'strict' => true,
                    'schema' => self::schema(),
                ),
            ),
            'temperature' => 0.3,
            'max_tokens'  => 700,
        );
        $model = (string)get_config('local_aimissions', 'model');
        if ($model !== '') {
            $options['model'] = $model;
        }

        $result = \local_aifeedback\api::call($messages, $options);
        if (!is_array($result) || empty($result['colour'])) {
            throw new \moodle_exception('error_llm_invalid', 'local_aimissions');
        }
        $colour = in_array($result['colour'], array('vert', 'bleu', 'jaune', 'rouge'), true)
            ? $result['colour'] : 'jaune';
        $score   = max(0, min(100, (int)($result['score'] ?? 0)));
        $comment = trim((string)($result['comment'] ?? ''));

        return array('colour' => $colour, 'score' => $score, 'comment' => $comment);
    }

    /**
     * Schéma JSON strict de la réponse d'évaluation.
     */
    private static function schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'colour'  => array('type' => 'string', 'enum' => array('vert', 'bleu', 'jaune', 'rouge')),
                'score'   => array('type' => 'integer'),
                'comment' => array('type' => 'string'),
            ),
            'required' => array('colour', 'score', 'comment'),
        );
    }

    /**
     * Prompt système : on évalue la QUALITÉ DE COMMUNICATION, pas la technique.
     */
    private static function system_prompt(\stdClass $project): string {
        $p  = "Tu évalues la COMMUNICATION PROFESSIONNELLE d'un étudiant BTS CIEL avec son client ";
        $p .= "(l'entreprise « " . $project->companyname . " »). Tu disposes des messages que ";
        $p .= "l'étudiant a adressés au client et des réponses obtenues.\n\n";
        $p .= "Évalue UNIQUEMENT la communication (PAS la justesse technique) :\n";
        $p .= "- clarté et structure des messages ;\n";
        $p .= "- professionnalisme et courtoisie (formules, ton adapté à un client) ;\n";
        $p .= "- pertinence : a-t-il posé les BONNES questions pour lever les ambiguïtés du besoin ?\n";
        $p .= "- capacité à reformuler le besoin métier.\n\n";
        $p .= "Rends un niveau sous forme de COULEUR : « vert » (excellente maîtrise), « bleu » ";
        $p .= "(maîtrise satisfaisante), « jaune » (maîtrise fragile), « rouge » (insuffisante) ; ";
        $p .= "un score 0-100 cohérent ; et un commentaire bref EN FRANÇAIS adressé à l'enseignant, ";
        $p .= "justifiant le niveau. Réponds selon le schéma JSON imposé.";
        return $p;
    }
}
