<?php
namespace local_aibridge\reader;

use local_aibridge\extension\registry;
use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Une activité vue par le pont : réglages communs (achèvement, accès,
 * groupes), enregistrement de l'instance, éléments de note, contenu propre au
 * type (pages de leçon, structure du test, plugins du devoir...) et réglages
 * des extensions (Correction IA, Tuteur IA, EFE, activités surveillées...).
 *
 * Tout est lu directement en base, sans passer par les formulaires : aucun
 * effet de bord (pas de zone de brouillon créée), aucune donnée d'élève.
 */
class module_reader {

    /** Champs de date des instances, rendus lisibles dans « _dates ». */
    const DATE_FIELDS = array('timeopen', 'timeclose', 'duedate', 'allowsubmissionsfromdate', 'cutoffdate',
        'gradingduedate', 'available', 'deadline', 'timeavailablefrom', 'timeavailableto', 'timecreated', 'timemodified');

    /** Types de page de leçon (mod/lesson/pagetypes). */
    const LESSON_PAGE_TYPES = array(1 => 'shortanswer', 2 => 'truefalse', 3 => 'multichoice', 5 => 'matching',
        8 => 'numerical', 10 => 'essay', 20 => 'content', 21 => 'endofbranch', 30 => 'cluster', 31 => 'endofcluster');

    /** Sauts de leçon (mod/lesson/locallib.php). */
    const LESSON_JUMPS = array(0 => 'cette page', -1 => 'page suivante', -9 => 'fin de la leçon',
        -40 => 'page précédente', -50 => 'page non vue d\'un embranchement', -60 => 'page au hasard',
        -70 => 'embranchement au hasard', -80 => 'question non vue d\'une grappe', 1 => 'page non vue',
        2 => 'question non répondue');

    /** Champs de relecture du test et moments (mod_quiz\question\display_options). */
    const QUIZ_REVIEW_FIELDS = array('reviewattempt', 'reviewcorrectness', 'reviewmaxmarks', 'reviewmarks',
        'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer', 'reviewoverallfeedback');
    const QUIZ_REVIEW_TIMES = array('during' => 0x10000, 'immediately' => 0x01000, 'laterwhileopen' => 0x00100,
        'afterclose' => 0x00010);

    /** Types de question IA et leur table d'options. */
    const AI_QTYPES = array('aiessay' => 'qtype_aiessay_options', 'aishortanswer' => 'qtype_aishortanswer_options');

    public static function read(int $cmid): array {
        global $DB;
        list($course) = get_course_and_cm_from_cmid($cmid);
        $cmrecord = $DB->get_record('course_modules', array('id' => $cmid), '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($cmid);
        $context = \context_module::instance($cmid);
        $instance = $DB->get_record($cm->modname, array('id' => $cm->instance), '*', MUST_EXIST);

        $instancedata = (array)$instance;
        $dates = array();
        foreach (self::DATE_FIELDS as $field) {
            if (array_key_exists($field, $instancedata)) {
                $dates[$field] = util::date($instancedata[$field]);
            }
        }
        $instancedata['_dates'] = $dates;

        $section = $modinfo->get_section_info($cm->sectionnum);
        return array(
            'cmid'         => (int)$cm->id,
            'courseid'     => (int)$course->id,
            'modname'      => $cm->modname,
            'name'         => $cm->name,
            'url'          => $cm->url ? $cm->url->out(false) : null,
            'section'      => array('number' => (int)$cm->sectionnum, 'name' => util::plain(get_section_name($course, $section))),
            'cm'           => array(
                'idnumber'            => $cm->idnumber,
                'visible'             => (int)$cm->visible,
                'visibleoncoursepage' => (int)$cm->visibleoncoursepage,
                'showdescription'     => (int)$cm->showdescription,
                'groupmode'           => (int)$cm->groupmode,
                'groupingid'          => (int)$cm->groupingid,
                'indent'              => (int)$cm->indent,
                'downloadcontent'     => (int)($cmrecord->downloadcontent ?? 0),
                'lang'                => $cmrecord->lang ?? null,
            ),
            'completion'   => array(
                'completion'                => (int)$cm->completion,
                'completionview'            => (int)$cm->completionview,
                'completionexpected'        => util::date($cm->completionexpected),
                'completionpassgrade'       => (int)$cm->completionpassgrade,
                'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            ),
            'availability' => util::availability($cm->availability, $modinfo),
            'tags'         => \core_tag_tag::get_item_tags_array('core', 'course_modules', $cm->id),
            'instance'     => $instancedata,
            'grade'        => self::grade_items($course->id, $cm),
            'content'      => self::content($cm, $instance, $context),
            'extensions'   => registry::read_all($cm),
        );
    }

    /** Éléments de note de l'activité (note maximale, note pour passer, catégorie). */
    private static function grade_items(int $courseid, \cm_info $cm): array {
        global $DB;
        $out = array();
        $items = $DB->get_records('grade_items', array('courseid' => $courseid, 'itemtype' => 'mod',
            'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance), 'itemnumber');
        foreach ($items as $item) {
            // Catégorie racine du carnet : son nom technique est « ? ».
            $category = $DB->get_record('grade_categories', array('id' => $item->categoryid), 'fullname, parent');
            $category = !$category ? false : (empty($category->parent) ? '(racine du cours)' : $category->fullname);
            $out[] = array(
                'itemnumber'   => (int)$item->itemnumber,
                'itemname'     => $item->itemname,
                'idnumber'     => $item->idnumber,
                'gradetype'    => (int)$item->gradetype,
                'grademax'     => $item->grademax,
                'grademin'     => $item->grademin,
                'gradepass'    => $item->gradepass,
                'scale'        => $item->scaleid ? $DB->get_field('scale', 'name', array('id' => $item->scaleid)) : null,
                'category'     => $category === false ? null : $category,
                'hidden'       => (int)$item->hidden,
                'locked'       => (int)$item->locked,
                'outcomeid'    => $item->outcomeid ? (int)$item->outcomeid : null,
            );
        }
        return $out;
    }

    /** Contenu propre au type d'activité. */
    private static function content(\cm_info $cm, \stdClass $instance, \context_module $context): array {
        switch ($cm->modname) {
            case 'page':
                return array(
                    'displayoptions' => unserialize_array((string)$instance->displayoptions) ?: array(),
                    'files'          => util::files($context->id, 'mod_page', 'content', 0),
                );
            case 'lesson':
                return self::lesson($instance, $context);
            case 'quiz':
                return self::quiz($cm, $instance, $context);
            case 'assign':
                return self::assign($instance, $context);
            case 'url':
                return array(
                    'displayoptions' => unserialize_array((string)$instance->displayoptions) ?: array(),
                    'parameters'     => unserialize_array((string)$instance->parameters) ?: array(),
                );
            case 'resource':
                return array(
                    'displayoptions' => unserialize_array((string)$instance->displayoptions) ?: array(),
                    'files'          => util::files($context->id, 'mod_resource', 'content', 0),
                );
            case 'folder':
                return array('files' => util::files($context->id, 'mod_folder', 'content', 0));
            case 'book':
                return self::book($instance, $context);
            case 'qbank':
                return array('categories' => question_reader::categories_in_contexts(array($context->id)));
            default:
                return array();
        }
    }

    // -------------------------------------------------------------------------
    //  Leçon
    // -------------------------------------------------------------------------

    /** Pages de la leçon dans l'ordre de lecture, avec réponses et sauts. */
    private static function lesson(\stdClass $lesson, \context_module $context): array {
        global $DB;
        $pages = $DB->get_records('lesson_pages', array('lessonid' => $lesson->id));
        $answers = $DB->get_records('lesson_answers', array('lessonid' => $lesson->id), 'pageid, id');

        $answersbypage = array();
        foreach ($answers as $answer) {
            // Une page de la leçon d'abord : les codes 1 et 2 peuvent aussi être des identifiants de page.
            $jumpto = (int)$answer->jumpto;
            $jumplabel = ($jumpto > 0 && isset($pages[$jumpto]))
                ? 'page « ' . $pages[$jumpto]->title . ' »'
                : (self::LESSON_JUMPS[$jumpto] ?? null);
            $answersbypage[$answer->pageid][] = array(
                'jumpto'         => $jumpto,
                'jumplabel'      => $jumplabel,
                'grade'          => (int)$answer->grade,
                'score'          => (int)$answer->score,
                'answer'         => $answer->answer,
                'answerformat'   => (int)$answer->answerformat,
                'response'       => $answer->response,
                'responseformat' => (int)$answer->responseformat,
            );
        }

        // Ordre de lecture : chaînage prevpageid / nextpageid depuis la première page.
        $ordered = array();
        $first = null;
        foreach ($pages as $page) {
            if ((int)$page->prevpageid === 0) {
                $first = $page;
                break;
            }
        }
        $seen = array();
        for ($page = $first; $page && !isset($seen[$page->id]); $page = $pages[$page->nextpageid] ?? null) {
            $seen[$page->id] = true;
            $ordered[] = $page;
        }
        // Pages hors chaîne (base incohérente) : à la fin, pour ne rien perdre.
        foreach ($pages as $page) {
            if (!isset($seen[$page->id])) {
                $ordered[] = $page;
            }
        }

        $out = array();
        foreach ($ordered as $index => $page) {
            $out[] = array(
                'id'             => (int)$page->id,
                'position'       => $index + 1,
                'title'          => $page->title,
                'qtype'          => (int)$page->qtype,
                'type'           => self::LESSON_PAGE_TYPES[(int)$page->qtype] ?? 'unknown',
                'qoption'        => (int)$page->qoption,
                'layout'         => (int)$page->layout,
                'display'        => (int)$page->display,
                'contents'       => $page->contents,
                'contentsformat' => (int)$page->contentsformat,
                'answers'        => $answersbypage[$page->id] ?? array(),
                'files'          => util::files($context->id, 'mod_lesson', 'page_contents', (int)$page->id),
            );
        }
        return array(
            'pagecount' => count($out),
            'pages'     => $out,
            'mediafiles' => util::files($context->id, 'mod_lesson', 'mediafile', 0),
        );
    }

    // -------------------------------------------------------------------------
    //  Test
    // -------------------------------------------------------------------------

    /** Relecture décodée, sections, emplacements et questions, bandes de feedback, dérogations. */
    private static function quiz(\cm_info $cm, \stdClass $quiz, \context_module $context): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $review = array();
        foreach (self::QUIZ_REVIEW_FIELDS as $field) {
            $value = (int)($quiz->$field ?? 0);
            foreach (self::QUIZ_REVIEW_TIMES as $time => $bit) {
                $review[substr($field, 6)][$time] = ($value & $bit) ? 1 : 0;
            }
        }

        $sections = array();
        foreach ($DB->get_records('quiz_sections', array('quizid' => $quiz->id), 'firstslot') as $section) {
            $sections[] = array(
                'firstslot'        => (int)$section->firstslot,
                'heading'          => $section->heading,
                'shufflequestions' => (int)$section->shufflequestions,
            );
        }

        $slots = array();
        $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure($quiz->id, $context);
        $entryids = array();
        foreach ($structure as $slot) {
            if (empty($slot->random) && !empty($slot->questionbankentryid)) {
                $entryids[] = (int)$slot->questionbankentryid;
            }
        }
        $idnumbers = array();
        if ($entryids) {
            list($insql, $params) = $DB->get_in_or_equal($entryids);
            $idnumbers = $DB->get_records_select_menu('question_bank_entries', "id $insql", $params, '', 'id, idnumber');
        }
        $questionids = array();
        foreach ($structure as $slot) {
            if (!empty($slot->random)) {
                $categoryid = (int)$slot->category;
                $slots[] = array(
                    'slot'      => (int)$slot->slot,
                    'page'      => (int)$slot->page,
                    'maxmark'   => $slot->maxmark,
                    'random'    => true,
                    'filtercondition' => $slot->filtercondition,
                    'category'  => $categoryid ? $DB->get_field('question_categories', 'name', array('id' => $categoryid)) : null,
                    'categoryid' => $categoryid,
                );
                continue;
            }
            $questionid = is_numeric($slot->questionid) ? (int)$slot->questionid : 0;
            if ($questionid) {
                $questionids[] = $questionid;
            }
            $slots[] = array(
                'slot'            => (int)$slot->slot,
                'page'            => (int)$slot->page,
                'displaynumber'   => $slot->displaynumber,
                'requireprevious' => (int)$slot->requireprevious,
                'maxmark'         => $slot->maxmark,
                'random'          => false,
                'questionid'      => $questionid,
                'name'            => $slot->name,
                'qtype'           => $slot->qtype,
                'idnumber'        => $idnumbers[$slot->questionbankentryid] ?? null,
                'defaultmark'     => $slot->defaultmark ?? null,
                'version'         => isset($slot->version) ? (int)$slot->version : null,
                'requestedversion' => $slot->requestedversion ?? null,
                'categoryid'      => (int)$slot->category,
                'questiontext'    => $slot->questiontext ?? null,
            );
        }
        $tags = question_reader::tags($questionids);
        $aioptions = self::ai_options($questionids);
        foreach ($slots as $i => $slot) {
            if (!empty($slot['questionid'])) {
                $slots[$i]['tags'] = $tags[$slot['questionid']] ?? array();
                if (isset($aioptions[$slot['questionid']])) {
                    $slots[$i]['aioptions'] = $aioptions[$slot['questionid']];
                }
            }
        }

        $feedback = array();
        foreach ($DB->get_records('quiz_feedback', array('quizid' => $quiz->id), 'mingrade DESC') as $band) {
            $feedback[] = array('mingrade' => $band->mingrade, 'maxgrade' => $band->maxgrade,
                'feedbacktext' => $band->feedbacktext);
        }

        // Dérogations : celles des groupes sont des réglages, celles des
        // élèves (rattrapages) ne sont que comptées.
        $groupoverrides = array();
        foreach ($DB->get_records_select('quiz_overrides', 'quiz = ? AND groupid IS NOT NULL', array($quiz->id)) as $override) {
            $row = util::row($override, array('id', 'quiz', 'userid'));
            $row['groupname'] = groups_get_group_name($override->groupid);
            $groupoverrides[] = $row;
        }

        return array(
            'review'         => $review,
            'sections'       => $sections,
            'slots'          => $slots,
            'slotcount'      => count($slots),
            'feedback'       => $feedback,
            'groupoverrides' => $groupoverrides,
            'useroverridecount' => $DB->count_records_select('quiz_overrides', 'quiz = ? AND userid IS NOT NULL',
                array($quiz->id)),
            'accessrules'    => self::quiz_access_rules($quiz),
        );
    }

    /** Règles d'accès actives : celles du cœur se lisent dans l'instance ; les plugins sont listés. */
    private static function quiz_access_rules(\stdClass $quiz): array {
        $rules = array();
        foreach (\core_component::get_plugin_list('quizaccess') as $name => $dir) {
            $rules[] = $name;
        }
        return array('installed' => $rules);
    }

    /**
     * Options des questions IA (prompt, corrigé, compétences). La clé d'API
     * est masquée par le pont.
     *
     * @param int[] $questionids
     * @return array questionid => options
     */
    public static function ai_options(array $questionids): array {
        global $DB;
        $out = array();
        if (!$questionids) {
            return $out;
        }
        foreach (self::AI_QTYPES as $qtype => $table) {
            if (!util::table_exists($table)) {
                continue;
            }
            list($insql, $params) = $DB->get_in_or_equal($questionids);
            foreach ($DB->get_records_select($table, "questionid $insql", $params) as $row) {
                $out[(int)$row->questionid] = util::row($row, array('id', 'questionid'));
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    //  Devoir
    // -------------------------------------------------------------------------

    /** Réglages des plugins de remise et de feedback, pièces jointes, grille d'évaluation. */
    private static function assign(\stdClass $assign, \context_module $context): array {
        global $DB;
        $plugins = array();
        foreach ($DB->get_records('assign_plugin_config', array('assignment' => $assign->id), 'subtype, plugin, name') as $row) {
            $plugins[$row->subtype][$row->plugin][$row->name] = $row->value;
        }
        return array(
            'plugins'         => $plugins,
            'introattachments' => util::files($context->id, 'mod_assign', 'introattachment', 0),
            'grading'         => self::advanced_grading($context, 'mod_assign'),
            'useroverridecount' => $DB->count_records_select('assign_overrides', 'assignid = ? AND userid IS NOT NULL',
                array($assign->id)),
        );
    }

    /** Méthode d'évaluation avancée (grille critériée, guide) et sa définition. */
    private static function advanced_grading(\context_module $context, string $component): array {
        global $DB;
        $out = array();
        foreach ($DB->get_records('grading_areas', array('contextid' => $context->id, 'component' => $component)) as $area) {
            $entry = array('area' => $area->areaname, 'method' => $area->activemethod, 'definition' => null);
            if (!empty($area->activemethod)) {
                $definition = $DB->get_record('grading_definitions',
                    array('areaid' => $area->id, 'method' => $area->activemethod),
                    'id, name, description, status, options');
                if ($definition) {
                    $entry['definition'] = array(
                        'name'        => $definition->name,
                        'description' => $definition->description,
                        'status'      => (int)$definition->status,
                        'criteria'    => self::grading_criteria($area->activemethod, (int)$definition->id),
                    );
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    private static function grading_criteria(string $method, int $definitionid): array {
        global $DB;
        $out = array();
        if ($method === 'rubric' && util::table_exists('gradingform_rubric_criteria')) {
            foreach ($DB->get_records('gradingform_rubric_criteria', array('definitionid' => $definitionid), 'sortorder') as $criterion) {
                $levels = array();
                foreach ($DB->get_records('gradingform_rubric_levels', array('criterionid' => $criterion->id), 'score') as $level) {
                    $levels[] = array('score' => $level->score, 'definition' => $level->definition);
                }
                $out[] = array('description' => $criterion->description, 'levels' => $levels);
            }
        } else if ($method === 'guide' && util::table_exists('gradingform_guide_criteria')) {
            foreach ($DB->get_records('gradingform_guide_criteria', array('definitionid' => $definitionid), 'sortorder') as $criterion) {
                $out[] = array(
                    'shortname'   => $criterion->shortname,
                    'description' => $criterion->description,
                    'descriptionmarkers' => $criterion->descriptionmarkers,
                    'maxscore'    => $criterion->maxscore,
                );
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    //  Livre
    // -------------------------------------------------------------------------

    private static function book(\stdClass $book, \context_module $context): array {
        global $DB;
        $chapters = array();
        foreach ($DB->get_records('book_chapters', array('bookid' => $book->id), 'pagenum') as $chapter) {
            $chapters[] = array(
                'id'         => (int)$chapter->id,
                'pagenum'    => (int)$chapter->pagenum,
                'subchapter' => (int)$chapter->subchapter,
                'title'      => $chapter->title,
                'content'    => $chapter->content,
                'hidden'     => (int)$chapter->hidden,
                'files'      => util::files($context->id, 'mod_book', 'chapter', (int)$chapter->id),
            );
        }
        return array('chapters' => $chapters);
    }
}
