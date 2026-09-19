<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

use local_aifeedback\api;

/**
 * Génération d'une réponse du tuteur, avec ou sans outils.
 *
 * Sans outil : un seul api::stream(), exactement comme avant.
 *
 * Avec des outils (web_search, read_page…) : une boucle de tours, tous
 * streamés.
 *   tour N : le modèle écrit (les fragments partent vers l'élève) et/ou
 *            demande des appels d'outils ;
 *   si appels : le PHP les exécute, ajoute à l'historique le message
 *            « assistant » (avec ses tool_calls) puis un message « tool » par
 *            appel, et relance un tour.
 * La boucle se termine forcément : chaque tour avec appels consomme au moins
 * un appel de la limite de la réponse, et une fois la limite atteinte les
 * outils ne sont plus proposés (le modèle ne peut alors que répondre).
 */
class generator {

    /**
     * @param array         $messages messages OpenAI (prompt système + historique)
     * @param array         $options  options de api::stream (sans 'tools')
     * @param callable      $ondelta  function(string): bool — false = arrêter
     * @param callable|null $onsearch function(string $label, string $kind) avant chaque appel d'outil
     * @param toolset|null  $tool     null = aucun outil proposé
     * @return array ['content', 'finish_reason', 'usage', 'aborted', 'rounds',
     *               'toolchars' (taille des résultats injectés)]. Avec des outils,
     *               'usage' vaut : prompt du DERNIER tour (le contexte réel,
     *               résultats compris ; le serveur garde en cache le début commun
     *               des tours) + tout le texte généré. Null si un tour ne l'a pas
     *               fourni.
     * @throws \moodle_exception comme api::stream (serveur indisponible)
     */
    public static function run(array $messages, array $options, callable $ondelta,
            $onsearch = null, toolset $tool = null) {
        if ($tool === null) {
            $result = api::stream($messages, $options, $ondelta);
            $result['rounds']    = 1;
            $result['toolchars'] = 0;
            return $result;
        }

        $content     = '';
        $lastprompt  = 0;
        $completions = 0;
        $hasusage    = true;
        $toolchars   = 0;
        $maxrounds   = $tool->maxcalls() + 1;

        for ($round = 1; ; $round++) {
            $offer = ($round < $maxrounds) && !$tool->exhausted();
            $opts  = $options;
            if ($offer) {
                $opts['tools'] = $tool->definitions();
            }

            // Le texte d'un nouveau tour est séparé de celui du précédent
            // (« Je vérifie… » puis la réponse) ; le séparateur passe par le
            // flux, pour que le texte enregistré soit celui que l'élève a vu.
            $separator = ($content !== '' && !preg_match('/\s$/u', $content)) ? "\n\n" : '';
            $sent      = '';
            $wrapped   = function($delta) use ($ondelta, &$separator, &$sent) {
                if ($separator !== '') {
                    $sent      = $separator;
                    $separator = '';
                    if ($ondelta($sent) === false) {
                        return false;
                    }
                }
                return $ondelta($delta);
            };

            $result   = api::stream($messages, $opts, $wrapped);
            $content .= $sent . $result['content'];
            if (is_array($result['usage']) && isset($result['usage']['prompt_tokens'])) {
                $lastprompt   = (int)$result['usage']['prompt_tokens'];
                $completions += isset($result['usage']['completion_tokens'])
                    ? (int)$result['usage']['completion_tokens'] : 0;
            } else {
                $hasusage = false;
            }

            $calls = isset($result['tool_calls']) ? $result['tool_calls'] : array();
            // Fin : abandon de l'élève, réponse sans appel, ou appel alors
            // qu'aucun outil n'était proposé (on garde le texte obtenu).
            if (!empty($result['aborted']) || empty($calls) || !$offer) {
                $aborted = !empty($result['aborted']);
                if (!$aborted && trim($content) === '') {
                    // Uniquement des appels d'outil, jamais de texte : même
                    // traitement qu'un flux vide.
                    throw new \moodle_exception('apicallfailed', 'local_aifeedback', '', null,
                        'réponse vide après ' . $round . ' tour(s) d\'appel d\'outil');
                }
                if (!$aborted) {
                    $aborted = !self::append_sources($content, $tool, $ondelta);
                }
                return array(
                    'content'       => $content,
                    'finish_reason' => $result['finish_reason'],
                    'usage'         => $hasusage ? array(
                        'prompt_tokens'     => $lastprompt,
                        'completion_tokens' => $completions,
                        'total_tokens'      => $lastprompt + $completions,
                    ) : null,
                    'aborted'       => $aborted,
                    'rounds'        => $round,
                    'toolchars'     => $toolchars,
                );
            }

            $assistant = array(
                'role'       => 'assistant',
                'content'    => ($result['content'] !== '') ? $result['content'] : null,
                'tool_calls' => array(),
            );
            foreach ($calls as $call) {
                $assistant['tool_calls'][] = array(
                    'id'       => $call['id'],
                    'type'     => 'function',
                    'function' => array('name' => $call['name'], 'arguments' => $call['arguments']),
                );
            }
            $messages[] = $assistant;
            foreach ($calls as $call) {
                $output = $tool->execute($call['name'], $call['arguments'], $onsearch);
                $toolchars += \core_text::strlen($output);
                $messages[] = array(
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'],
                    'content'      => $output,
                );
            }
        }
    }

    /**
     * Ajoute à la fin de la réponse la liste des pages transmises au modèle,
     * par le flux, pour que le texte enregistré soit celui que l'élève a vu.
     *
     * @param string   $content texte de la réponse (complété sur place)
     * @param toolset  $tool
     * @param callable $ondelta
     * @return bool false si l'élève a interrompu pendant l'envoi
     */
    private static function append_sources(&$content, toolset $tool, callable $ondelta) {
        $block = toolbox::sources_markdown($tool->sources());
        if ($block === '') {
            return true;
        }
        if (preg_match('/\n\n$/', $content)) {
            $separator = '';
        } else {
            $separator = preg_match('/\n$/', $content) ? "\n" : "\n\n";
        }
        $delta    = $separator . $block;
        $content .= $delta;
        return ($ondelta($delta) !== false);
    }
}
