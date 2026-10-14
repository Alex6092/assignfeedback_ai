<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Réglages des plugins de l'établissement qui ne passent pas par le
 * formulaire de l'activité :
 *   - activité surveillée (local_classhours) : condition « supervised », code
 *     de séance, mode examen, date prévue ;
 *   - activités à achever d'abord (cœur de Moodle, condition « achèvement d'activité ») : le TP
 *     bonus n'ouvre qu'à ceux qui ont rendu les TP évalués de la séance ;
 *   - durée minimale attendue (local_attemptcheck) ;
 *   - Avancement (block_avancementlapro) : suivi du cours, date de rendu.
 * Chaque plugin est optionnel : absent, son réglage est ignoré avec un avertissement.
 */
class extras_writer {

    /**
     * @param int   $cmid   activité brouillon du pont
     * @param array $extras {supervised?: {planned, sessioncode, exammode}, requires?: [cmid, …],
     *                       minduration?: secondes, avancement?: {duedate}}
     * @param bool  $dryrun
     * @return array
     */
    public static function apply(int $cmid, array $extras, bool $dryrun): array {
        global $DB;
        list($course, $cm) = guard::require_draft_cm($cmid);
        $courseid = (int)$course->id;
        $done = array();
        $warnings = array();

        if (isset($extras['supervised'])) {
            $options = (array)$extras['supervised'];
            $planned = dates::parse($options['planned'] ?? 0, 'supervised.planned');
            if (!class_exists('\local_classhours\gate') || !class_exists('\local_classhours\plan')) {
                $warnings[] = 'Activité surveillée demandée, mais local_classhours n\'est pas installé.';
            } else if (!\local_classhours\availability_json::condition_enabled('supervised')) {
                $warnings[] = 'Condition « activité surveillée » désactivée sur le site : réglage ignoré.';
            } else {
                if (!$dryrun) {
                    $json = $DB->get_field('course_modules', 'availability', array('id' => $cmid));
                    if (!\local_classhours\gate::is_supervised_json($json)) {
                        \local_classhours\availability_json::store($courseid, $cmid,
                            \local_classhours\gate::add_supervised($json));
                    }
                    \local_classhours\plan::save_options($courseid, $cmid, !empty($options['sessioncode']),
                        !empty($options['exammode']));
                    \local_classhours\plan::save_dates($courseid, $cmid, $planned ? array(0 => $planned) : array());
                    \local_classhours\availability_json::rebuild($courseid);
                }
                $done['supervised'] = array('sessioncode' => !empty($options['sessioncode']),
                    'exammode' => !empty($options['exammode']), 'planned' => $planned ? date('c', $planned) : null);
            }
        }

        if (isset($extras['requires'])) {
            $required = array_values(array_unique(array_map('intval', (array)$extras['requires'])));
            if (empty($course->enablecompletion)) {
                $warnings[] = 'Achèvement désactivé dans le cours : condition « activités à achever » ignorée.';
            } else {
                $json = $DB->get_field('course_modules', 'availability', array('id' => $cmid));
                foreach ($required as $reqid) {
                    $req = $DB->get_record('course_modules', array('id' => $reqid, 'course' => $courseid),
                        'id, completion');
                    if (!$req || $reqid === $cmid) {
                        throw new \moodle_exception('error_requires', 'local_aibridge', '', $reqid);
                    }
                    if (empty($req->completion)) {
                        $warnings[] = "L'activité $reqid n'a pas de suivi d'achèvement : la condition ne sera jamais remplie.";
                    }
                    $json = self::add_completion($json, $reqid);
                }
                if (!$dryrun && $required) {
                    $DB->set_field('course_modules', 'availability', $json, array('id' => $cmid));
                    \course_modinfo::purge_course_module_cache($courseid, $cmid);
                    rebuild_course_cache($courseid, false, true);
                }
                $done['requires'] = $required;
            }
        }

        if (isset($extras['minduration'])) {
            if (!class_exists('\local_attemptcheck\review')) {
                $warnings[] = 'Durée minimale demandée, mais local_attemptcheck n\'est pas installé.';
            } else {
                if (!$dryrun) {
                    \local_attemptcheck\review::set_minduration($cmid, $courseid, (int)$extras['minduration']);
                }
                $done['minduration'] = (int)$extras['minduration'];
            }
        }

        if (isset($extras['avancement'])) {
            $due = dates::parse(((array)$extras['avancement'])['duedate'] ?? 0, 'avancement.duedate');
            if (!class_exists('\block_avancementlapro\manager')) {
                $warnings[] = 'Avancement demandé, mais block_avancementlapro n\'est pas installé.';
            } else {
                if (!$dryrun) {
                    \block_avancementlapro\manager::ensure_tracked($courseid);
                    \block_avancementlapro\manager::sync_course($courseid);
                    $item = \block_avancementlapro\manager::get_item_by_cmid($cmid);
                    if ($item && $due) {
                        // Sur un test ou une leçon, l'échéance native est la date de FERMETURE : la date de
                        // rendu de l'Avancement ne doit pas la poser (la correction d'un sommatif se publie
                        // à la main, quand tout le monde l'a passé).
                        if (in_array($cm->modname, array('quiz', 'lesson'), true) && !empty($item->nativesync)) {
                            $DB->set_field(\block_avancementlapro\manager::TABLE, 'nativesync', 0,
                                array('id' => $item->id));
                            $item->nativesync = 0;
                        }
                        \block_avancementlapro\scheduler::set_single($item, $due);
                    }
                    $item = \block_avancementlapro\manager::get_item_by_cmid($cmid);
                    $done['avancement'] = $item ? array('sortorder' => (int)$item->sortorder,
                        'duedate' => $item->duedate ? date('c', (int)$item->duedate) : null,
                        'nativesync' => (int)$item->nativesync) : null;
                } else {
                    $done['avancement'] = array('duedate' => $due ? date('c', $due) : null);
                }
            }
        }

        return array('cmid' => $cmid, 'dryrun' => $dryrun, 'applied' => $done, 'warnings' => $warnings);
    }

    /**
     * Ajoute à la racine de l'accès la condition « l'activité $reqid doit être achevée », sauf si elle y
     * est déjà ; les autres conditions (activité surveillée, heures de cours…) sont conservées.
     *
     * @param string|null $json  accès actuel de l'activité
     * @param int         $reqid activité à achever d'abord
     * @return string nouveau JSON
     */
    private static function add_completion(?string $json, int $reqid): string {
        $child = (object)array('type' => 'completion', 'cm' => $reqid, 'e' => COMPLETION_COMPLETE);
        $tree = $json ? json_decode($json) : null;
        if (!is_object($tree) || !isset($tree->op) || !isset($tree->c) || !is_array($tree->c)) {
            return json_encode((object)array('op' => '&', 'c' => array($child), 'showc' => array(true)));
        }
        if ($tree->op === '&') {
            foreach ($tree->c as $existing) {
                if (is_object($existing) && ($existing->type ?? '') === 'completion'
                        && (int)($existing->cm ?? 0) === $reqid) {
                    return $json;
                }
            }
            $showc = (isset($tree->showc) && is_array($tree->showc))
                ? array_values($tree->showc) : array_fill(0, count($tree->c), true);
            $tree->c = array_values($tree->c);
            $tree->c[] = $child;
            $showc[] = true;
            $tree->showc = $showc;
            return json_encode($tree);
        }
        // Racine OU / NON : l'ancien arbre devient un sous-arbre du nouveau ET (comme local_classhours).
        if (isset($tree->showc) && is_array($tree->showc)) {
            $oldshow = !in_array(false, $tree->showc, true);
        } else {
            $oldshow = !isset($tree->show) || (bool)$tree->show;
        }
        $nested = clone $tree;
        unset($nested->show, $nested->showc);
        return json_encode((object)array('op' => '&', 'c' => array($nested, $child), 'showc' => array($oldshow, true)));
    }
}
