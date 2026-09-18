<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Brief pédagogique : ce que le tuteur a le droit de savoir des attendus.
 *
 * Le corrigé et le barème saisis dans la correction IA (assignfeedback_ai) sont
 * exactement ce qu'un élève cherchera à extraire du tuteur. Plutôt que de les
 * lui confier en lui interdisant d'en parler — une consigne qu'un petit modèle
 * finit toujours par lâcher sous la pression — on en dérive HORS LIGNE un brief
 * : critères, notions à mobiliser, erreurs fréquentes, questions à poser. Le
 * tuteur ne peut pas divulguer ce qu'il n'a jamais reçu.
 *
 * Le brief est relu et modifiable par l'enseignant avant d'être utilisé.
 */
class brief {

    /** Schéma de sortie imposé au modèle. */
    private static function schema() {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'brief' => array(
                    'type'        => 'string',
                    'description' => 'Brief pédagogique en markdown, sans aucune solution.',
                ),
            ),
            'required' => array('brief'),
        );
    }

    /**
     * Empreinte de la configuration source : permet de repérer un brief devenu
     * obsolète quand l'enseignant modifie l'énoncé ou le corrigé.
     */
    public static function hash($fbcfg) {
        if ($fbcfg === null) {
            return '';
        }
        return sha1((string)$fbcfg->exercise . '|'
            . (string)$fbcfg->expectedanswer . '|'
            . (string)$fbcfg->competencies);
    }

    /**
     * La génération d'un brief est-elle possible ici ? (correction IA présente
     * et corrigé renseigné)
     *
     * @param \cm_info|\stdClass $cm
     * @return \stdClass|null la configuration de correction, ou null
     */
    public static function source_config($cm) {
        $fbcfg = activity::feedback_config($cm);
        if ($fbcfg === null) {
            return null;
        }
        if (trim((string)$fbcfg->expectedanswer) === '' && trim((string)$fbcfg->exercise) === '') {
            return null;
        }
        return $fbcfg;
    }

    /**
     * Met le brief en file de génération s'il est absent ou périmé.
     *
     * @param int  $cmid
     * @param bool $force régénérer même si l'empreinte n'a pas changé
     * @return bool true si un job a été enfilé
     */
    public static function schedule_if_stale($cmid, $force = false) {
        global $DB;

        $config = activity::get($cmid);
        if ($config === null || empty($config->enabled) || empty($config->includebrief)) {
            return false;
        }

        list($course, $cm) = get_course_and_cm_from_cmid((int)$cmid);
        unset($course);
        $fbcfg = self::source_config($cm);
        if ($fbcfg === null) {
            return false;
        }

        $hash = self::hash($fbcfg);
        if (!$force && $config->briefstatus === 'ready' && $config->briefhash === $hash
                && trim((string)$config->brief) !== '') {
            return false; // brief à jour
        }
        if (!$force && $config->briefstatus === 'pending') {
            return false; // déjà en file
        }

        $DB->update_record(activity::TABLE, (object)array(
            'id'           => (int)$config->id,
            'briefstatus'  => 'pending',
            'brieferror'   => null,
            'timemodified' => time(),
        ));

        $payload = new \stdClass();
        $payload->cmid = (int)$cmid;
        \local_aifeedback\task\run_job::enqueue('local_aichat', $payload);
        return true;
    }

    /**
     * Génère le brief (appel LLM) et l'enregistre. Appelée depuis la file de
     * jobs partagée : c'est une action ponctuelle de l'enseignant, elle peut
     * donc passer par le serveur de correction sans gêner les élèves.
     *
     * @param int $cmid
     * @throws \moodle_exception
     */
    public static function generate($cmid) {
        global $DB;

        $config = activity::get($cmid);
        if ($config === null) {
            return;
        }

        try {
            list($course, $cm) = get_course_and_cm_from_cmid((int)$cmid);
            unset($course);
            $fbcfg = self::source_config($cm);
            if ($fbcfg === null) {
                $DB->update_record(activity::TABLE, (object)array(
                    'id'           => (int)$config->id,
                    'briefstatus'  => 'none',
                    'timemodified' => time(),
                ));
                return;
            }

            $context  = \context_module::instance((int)$cm->id);
            $messages = array(
                array('role' => 'system', 'content' => self::system_prompt()),
                array('role' => 'user',   'content' => self::user_prompt($cm, $fbcfg, $context)),
            );
            $options = array(
                'response_format' => array(
                    'type'        => 'json_schema',
                    'json_schema' => array(
                        'name'   => 'local_aichat_brief',
                        'strict' => true,
                        'schema' => self::schema(),
                    ),
                ),
                'extra_body'  => array('enable_thinking' => false),
                'temperature' => 0.3,
                'max_tokens'  => 1200,
            );

            $result = \local_aifeedback\api::call($messages, $options);
            $text   = isset($result['brief']) ? trim((string)$result['brief']) : '';
            if ($text === '') {
                throw new \moodle_exception('brieferror_empty', 'local_aichat');
            }

            $DB->update_record(activity::TABLE, (object)array(
                'id'           => (int)$config->id,
                'brief'        => $text,
                'briefstatus'  => 'ready',
                'briefhash'    => self::hash($fbcfg),
                'brieferror'   => null,
                'timemodified' => time(),
            ));

        } catch (\Throwable $e) {
            $DB->update_record(activity::TABLE, (object)array(
                'id'           => (int)$config->id,
                'briefstatus'  => 'failed',
                'brieferror'   => \core_text::substr((string)$e->getMessage(), 0, 900),
                'timemodified' => time(),
            ));
            throw $e; // la file de jobs journalise et retentera
        }
    }

    /** Prompt système de fabrication du brief. */
    public static function system_prompt() {
        $p  = "Tu prépares un BRIEF destiné à un tuteur IA qui accompagnera des étudiants de BTS ";
        $p .= "PENDANT la réalisation d'un travail noté. Ce tuteur ne doit jamais pouvoir donner la ";
        $p .= "solution : il n'aura accès qu'à ton brief, pas au corrigé.\n\n";

        $p .= "INTERDIT dans le brief (c'est la règle la plus importante) :\n";
        $p .= "- la solution, même partielle, même reformulée ;\n";
        $p .= "- du code, des valeurs, des résultats attendus, des noms de fonctions à écrire ;\n";
        $p .= "- le barème, les points, la pondération, le nombre d'éléments attendus ;\n";
        $p .= "- toute phrase qui permettrait de reconstituer la réponse.\n\n";

        $p .= "ATTENDU dans le brief, en markdown, environ 200 à 350 mots :\n";
        $p .= "- **Objectif** : ce que l'étudiant doit être capable de faire, en une ou deux phrases.\n";
        $p .= "- **Notions à mobiliser** : les concepts et savoir-faire utiles (sans les appliquer).\n";
        $p .= "- **Points d'attention** : ce qui est souvent négligé ou mal compris.\n";
        $p .= "- **Erreurs fréquentes** : les pièges typiques, formulés comme des symptômes ";
        $p .= "observables par l'étudiant.\n";
        $p .= "- **Questions à poser** : 3 à 5 questions que le tuteur peut poser pour débloquer ";
        $p .= "un étudiant sans rien lui donner.\n\n";

        $p .= "Écris pour le tuteur (pas pour l'étudiant). Reste factuel et concret. ";
        $p .= "La structure de ta réponse JSON est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    /** Message utilisateur : l'énoncé, les compétences et le corrigé source. */
    public static function user_prompt($cm, \stdClass $fbcfg, $context) {
        $parts = array();
        $parts[] = "TITRE DE L'ACTIVITÉ :\n" . format_string($cm->name, true,
            array('context' => $context));

        $record = activity::module_record($cm);
        if ($record && trim((string)$record->intro) !== '') {
            $parts[] = "CONSIGNE DONNÉE AUX ÉTUDIANTS :\n"
                . content::to_plain_text((string)$record->intro, (int)$record->introformat,
                    $context, 4000);
        }
        if (trim((string)$fbcfg->exercise) !== '') {
            $parts[] = "ÉNONCÉ (configuration de la correction IA) :\n"
                . content::truncate(trim((string)$fbcfg->exercise), 4000);
        }
        if (trim((string)$fbcfg->competencies) !== '') {
            $parts[] = "COMPÉTENCES ÉVALUÉES :\n"
                . content::truncate(trim((string)$fbcfg->competencies), 1000);
        }
        if (trim((string)$fbcfg->expectedanswer) !== '') {
            $parts[] = "CORRIGÉ ET BARÈME — CONFIDENTIEL, à NE JAMAIS reproduire ni paraphraser "
                . "dans le brief ; sers-t'en uniquement pour identifier les critères, les points "
                . "d'attention et les erreurs fréquentes :\n"
                . content::truncate(trim((string)$fbcfg->expectedanswer), 6000);
        }
        return implode("\n\n", $parts);
    }
}
