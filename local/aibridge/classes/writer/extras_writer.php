<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Réglages des plugins de l'établissement qui ne passent pas par le
 * formulaire de l'activité :
 *   - activité surveillée (local_classhours) : condition « supervised », code
 *     de séance, mode examen, date prévue ;
 *   - durée minimale attendue (local_attemptcheck) ;
 *   - Avancement (block_avancementlapro) : suivi du cours, date de rendu.
 * Chaque plugin est optionnel : absent, son réglage est ignoré avec un avertissement.
 */
class extras_writer {

    /**
     * @param int   $cmid   activité brouillon du pont
     * @param array $extras {supervised?: {planned, sessioncode, exammode}, minduration?: secondes,
     *                       avancement?: {duedate}}
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
}
