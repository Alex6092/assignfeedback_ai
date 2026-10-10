<?php
namespace local_aibridge\reader;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Banques de questions d'un cours (Moodle 5 : banques en activités mod_qbank
 * et banques propres aux tests), catégories, questions (dernière version),
 * étiquettes, et export Moodle XML.
 *
 * Toutes les requêtes sont bornées au cours : une question d'un autre cours
 * n'est jamais renvoyée.
 */
class question_reader {

    /**
     * Banques et catégories du cours, avec le nombre de questions.
     */
    public static function banks(int $courseid): array {
        global $DB;
        $sql = "SELECT ctx.id AS contextid, cm.id AS cmid, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :level
                 WHERE cm.course = :courseid
                   AND EXISTS (SELECT 1 FROM {question_categories} qc WHERE qc.contextid = ctx.id)";
        $rows = $DB->get_records_sql($sql, array('level' => CONTEXT_MODULE, 'courseid' => $courseid));
        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        $banks = array();
        foreach ($rows as $row) {
            $banks[] = array(
                'cmid'       => (int)$row->cmid,
                'modname'    => $row->modname,
                'name'       => isset($cms[$row->cmid]) ? $cms[$row->cmid]->name : null,
                'contextid'  => (int)$row->contextid,
                'categories' => self::categories_in_contexts(array((int)$row->contextid)),
            );
        }
        return $banks;
    }

    /**
     * Catégories de questions de contextes donnés, avec le nombre de questions.
     *
     * @param int[] $contextids
     */
    public static function categories_in_contexts(array $contextids): array {
        global $DB;
        if (!$contextids) {
            return array();
        }
        list($insql, $params) = $DB->get_in_or_equal($contextids);
        $categories = $DB->get_records_select('question_categories', "contextid $insql", $params,
            'contextid, parent, sortorder, name', 'id, name, parent, contextid, idnumber, info, infoformat, sortorder');
        if (!$categories) {
            return array();
        }
        list($catsql, $catparams) = $DB->get_in_or_equal(array_keys($categories));
        $counts = $DB->get_records_sql_menu(
            "SELECT qbe.questioncategoryid, COUNT(1)
               FROM {question_bank_entries} qbe
              WHERE qbe.questioncategoryid $catsql
           GROUP BY qbe.questioncategoryid", $catparams);
        $out = array();
        foreach ($categories as $category) {
            $out[] = array(
                'id'            => (int)$category->id,
                'name'          => $category->name,
                'parent'        => (int)$category->parent,
                'idnumber'      => $category->idnumber,
                'info'          => $category->info,
                'questioncount' => (int)($counts[$category->id] ?? 0),
            );
        }
        return $out;
    }

    /**
     * Questions du cours (dernière version, sans les sous-questions des
     * questions Cloze), par catégorie ou par identifiant.
     *
     * @param int   $courseid
     * @param int[] $categoryids vide : toutes les catégories du cours
     * @param int[] $questionids vide : toutes les questions des catégories
     * @return array[]
     */
    public static function questions(int $courseid, array $categoryids = array(), array $questionids = array()): array {
        global $DB;
        list($where, $params) = self::course_scope($courseid);
        if ($categoryids) {
            list($catsql, $catparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
            $where .= " AND qbe.questioncategoryid $catsql";
            $params += $catparams;
        }
        if ($questionids) {
            list($qsql, $qparams) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'qid');
            $where .= " AND q.id $qsql";
            $params += $qparams;
        }
        $sql = "SELECT q.id, q.name, q.qtype, q.defaultmark, q.penalty, q.questiontext, q.questiontextformat,
                       qbe.id AS bankentryid, qbe.idnumber, qbe.questioncategoryid AS categoryid,
                       qv.version, qv.status
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  JOIN {context} ctx ON ctx.id = qc.contextid
                 WHERE $where
                   AND q.parent = 0
                   AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                      WHERE v.questionbankentryid = qbe.id)
              ORDER BY qbe.questioncategoryid, q.name";
        $questions = $DB->get_records_sql($sql, $params);
        $tags = self::tags(array_keys($questions));
        $out = array();
        foreach ($questions as $question) {
            $row = (array)$question;
            $row['id'] = (int)$question->id;
            $row['tags'] = $tags[$question->id] ?? array();
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Export Moodle XML de questions du cours, dans l'ordre donné. Les options
     * des questions IA sont exportées sans la clé d'API (export_to_xml du
     * qtype).
     *
     * @param int   $courseid
     * @param int[] $questionids
     */
    public static function export_xml(int $courseid, array $questionids): string {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $questionids = array_values(array_unique(array_map('intval', $questionids)));
        if (!$questionids) {
            return '';
        }
        list($where, $params) = self::course_scope($courseid);
        list($qsql, $qparams) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'qid');
        // Même forme que get_questions_category() (question/editlib.php), utilisé par l'export du cœur.
        $records = $DB->get_records_sql(
            "SELECT q.*, qv.status, qbe.questioncategoryid AS category
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {context} ctx ON ctx.id = qc.contextid
              WHERE $where AND q.id $qsql", $params + $qparams);

        $questions = array();
        foreach ($questionids as $id) {
            if (!isset($records[$id])) {
                continue;
            }
            $question = $records[$id];
            // Questions masquées : ignorées par l'export du cœur, qui échoue s'il ne reste rien.
            if ($question->status === \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN) {
                continue;
            }
            $qtype = \question_bank::get_qtype($question->qtype, false);
            if ($qtype->name() === 'missingtype') {
                continue;
            }
            $question->export_process = true;
            $qtype->get_question_options($question);
            $questions[] = $question;
        }
        if (!$questions) {
            return '';
        }

        $format = new \qformat_xml();
        $format->setQuestions($questions);
        $format->setCattofile(false);
        $format->setContexttofile(false);
        if (!$format->exportpreprocess()) {
            throw new \moodle_exception('error_export', 'local_aibridge');
        }
        // Droits déjà vérifiés par le pont (local/aibridge:read sur le cours).
        return $format->exportprocess(false);
    }

    /**
     * Étiquettes des questions.
     *
     * @param int[] $questionids
     * @return array questionid => string[]
     */
    public static function tags(array $questionids): array {
        $out = array();
        if (!$questionids) {
            return $out;
        }
        foreach (\core_tag_tag::get_items_tags('core_question', 'question', $questionids) as $questionid => $tags) {
            foreach ($tags as $tag) {
                $out[$questionid][] = $tag->rawname;
            }
        }
        return $out;
    }

    /**
     * Restriction SQL aux banques du cours : contextes des activités du cours
     * et contexte du cours lui-même (catégories antérieures à Moodle 5).
     *
     * @return array [sql, params nommés] sur l'alias ctx
     */
    private static function course_scope(int $courseid): array {
        $coursecontext = \context_course::instance($courseid);
        $sql = "(ctx.id = :coursectx
                 OR (ctx.contextlevel = :modlevel
                     AND ctx.instanceid IN (SELECT cmx.id FROM {course_modules} cmx WHERE cmx.course = :courseid)))";
        return array($sql, array('coursectx' => $coursecontext->id, 'modlevel' => CONTEXT_MODULE, 'courseid' => $courseid));
    }
}
