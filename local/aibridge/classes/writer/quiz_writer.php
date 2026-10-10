<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Structure d'un test créé par le pont : questions fixes (par idnumber, dans
 * l'ordre) et tirages aléatoires dans une catégorie de la banque du cours.
 *
 * Les tirages aléatoires sont posés par slot_random avec son filtre : la
 * méthode structure::add_random_questions() ne transmet pas le filtre de
 * catégorie aux emplacements créés (constat de local_aiquizgen sur 5.x).
 */
class quiz_writer {

    /**
     * Remplit un test vide.
     *
     * @param int   $cmid   test brouillon du pont, sans question ni tentative
     * @param array $layout {slots: [{idnumber, maxmark?} | {random: {categoryid}, count, maxmark?}],
     *                       shufflequestions?: bool, heading?: string}
     * @param bool  $dryrun
     * @return array
     */
    public static function set_layout(int $cmid, array $layout, bool $dryrun): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        list($course, $cm) = guard::require_draft_cm($cmid, 'quiz');
        $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
        $quiz->cmid = $cmid;
        if ($DB->record_exists('quiz_slots', array('quizid' => $quiz->id))) {
            throw new \moodle_exception('error_quiznotempty', 'local_aibridge');
        }
        if ($DB->record_exists('quiz_attempts', array('quiz' => $quiz->id))) {
            throw new \moodle_exception('error_quizattempts', 'local_aibridge');
        }

        // Résolution de tout avant d'écrire : une question introuvable n'ajoute rien.
        $plan = array();
        foreach (array_values((array)($layout['slots'] ?? array())) as $i => $slot) {
            $maxmark = isset($slot['maxmark']) ? (float)$slot['maxmark'] : null;
            if (isset($slot['random'])) {
                $categoryid = (int)($slot['random']['categoryid'] ?? 0);
                $category = $DB->get_record('question_categories', array('id' => $categoryid), 'id, contextid, name');
                if (!$category || !self::category_in_course($category, (int)$course->id)) {
                    throw new \moodle_exception('error_randomcategory', 'local_aibridge', '', $categoryid);
                }
                $available = $DB->count_records('question_bank_entries', array('questioncategoryid' => $categoryid));
                $count = max(1, (int)($slot['count'] ?? 1));
                if ($available < $count) {
                    throw new \moodle_exception('error_randomcount', 'local_aibridge', '',
                        $category->name . ' : ' . $available . ' < ' . $count);
                }
                $plan[] = array('type' => 'random', 'category' => $category, 'count' => $count,
                    'maxmark' => $maxmark === null ? 1.0 : $maxmark);
            } else {
                $idnumber = trim((string)($slot['idnumber'] ?? ''));
                $questionid = self::question_by_idnumber($idnumber, (int)$course->id);
                if (!$questionid) {
                    throw new \moodle_exception('error_questionidnumber', 'local_aibridge', '', $idnumber);
                }
                $plan[] = array('type' => 'fixed', 'idnumber' => $idnumber, 'questionid' => $questionid,
                    'maxmark' => $maxmark);
            }
        }
        if (!$plan) {
            throw new \moodle_exception('error_quizlayout', 'local_aibridge');
        }
        $shuffle = !empty($layout['shufflequestions']) ? 1 : 0;
        if ($dryrun) {
            return array('dryrun' => true, 'cmid' => $cmid, 'shufflequestions' => $shuffle, 'slots' => array_map(
                function($p) {
                    return $p['type'] === 'random'
                        ? array('random' => $p['category']->name, 'count' => $p['count'], 'maxmark' => $p['maxmark'])
                        : array('idnumber' => $p['idnumber'], 'questionid' => $p['questionid'], 'maxmark' => $p['maxmark']);
                }, $plan));
        }

        $usingcontext = \context_module::instance($cmid);
        foreach ($plan as $item) {
            if ($item['type'] === 'fixed') {
                // Page 0 : Moodle pagine selon « questions par page » du test.
                \quiz_add_quiz_question($item['questionid'], $quiz, 0, $item['maxmark']);
                continue;
            }
            $filter = json_encode(array('filter' => array('category' => array(
                'jointype'      => 1,
                'values'        => array((int)$item['category']->id),
                'filteroptions' => array('includesubcategories' => false),
            ))));
            for ($n = 0; $n < $item['count']; $n++) {
                $random = new \mod_quiz\local\structure\slot_random((object)array(
                    'quizid'             => (int)$quiz->id,
                    'questionscontextid' => (int)$item['category']->contextid,
                    'usingcontextid'     => (int)$usingcontext->id,
                    'maxmark'            => $item['maxmark'],
                ));
                $random->set_quiz($quiz);
                $random->set_filter_condition($filter);
                $random->insert(0);
            }
        }

        $section = array('shufflequestions' => $shuffle);
        if (isset($layout['heading'])) {
            $section['heading'] = (string)$layout['heading'];
        }
        $sectionid = $DB->get_field('quiz_sections', 'id', array('quizid' => $quiz->id, 'firstslot' => 1));
        if ($sectionid) {
            $DB->update_record('quiz_sections', (object)(array('id' => $sectionid) + $section));
        }

        // sumgrades : somme des points des emplacements (quiz_update_sumgrades n'existe plus en 5.x).
        $sumgrades = (float)$DB->get_field_sql('SELECT COALESCE(SUM(maxmark), 0) FROM {quiz_slots} WHERE quizid = ?',
            array($quiz->id));
        $DB->set_field('quiz', 'sumgrades', $sumgrades, array('id' => $quiz->id));
        \mod_quiz\quiz_settings::create((int)$quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        rebuild_course_cache((int)$course->id, true);

        return array(
            'cmid'      => $cmid,
            'slots'     => (int)$DB->count_records('quiz_slots', array('quizid' => $quiz->id)),
            'sumgrades' => (float)$DB->get_field('quiz', 'sumgrades', array('id' => $quiz->id)),
            'grade'     => (float)$quiz->grade,
            'shufflequestions' => $shuffle,
        );
    }

    /** La catégorie appartient-elle à une banque (activité) de ce cours ? */
    private static function category_in_course(\stdClass $category, int $courseid): bool {
        $context = \context::instance_by_id((int)$category->contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_MODULE) {
            return false;
        }
        $coursecontext = $context->get_course_context(false);
        return $coursecontext && (int)$coursecontext->instanceid === $courseid;
    }

    /**
     * Dernière version de la question d'idnumber donné, dans une banque du cours.
     *
     * @return int|null
     */
    private static function question_by_idnumber(string $idnumber, int $courseid): ?int {
        global $DB;
        if ($idnumber === '') {
            return null;
        }
        $sql = "SELECT q.id
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = :level
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                 WHERE qbe.idnumber = :idnumber AND cm.course = :courseid
              ORDER BY qv.version DESC";
        $rows = $DB->get_records_sql($sql, array('level' => CONTEXT_MODULE, 'idnumber' => $idnumber,
            'courseid' => $courseid), 0, 1);
        return $rows ? (int)reset($rows)->id : null;
    }
}
