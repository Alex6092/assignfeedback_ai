<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\reader\question_reader;
use local_aibridge\util;

/**
 * Banques de questions d'un cours, questions (dernière version) et, sur
 * demande, leur export Moodle XML.
 */
class get_questions extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'courseid'     => new external_value(PARAM_INT, 'Cours'),
            'categoryids'  => new external_multiple_structure(
                new external_value(PARAM_INT, 'Catégorie de questions'), 'Catégories (vide : toutes)', VALUE_DEFAULT, array()),
            'questionids'  => new external_multiple_structure(
                new external_value(PARAM_INT, 'Question'), 'Questions (vide : toutes celles des catégories)',
                VALUE_DEFAULT, array()),
            'includebanks' => new external_value(PARAM_BOOL, 'Renvoyer les banques et catégories', VALUE_DEFAULT, true),
            'includexml'   => new external_value(PARAM_BOOL, 'Renvoyer l\'export Moodle XML des questions', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $courseid, array $categoryids = array(), array $questionids = array(),
            bool $includebanks = true, bool $includexml = false): array {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'courseid' => $courseid, 'categoryids' => $categoryids, 'questionids' => $questionids,
            'includebanks' => $includebanks, 'includexml' => $includexml));
        $courseid = (int)$params['courseid'];
        self::require_course_read($courseid);
        return audit::run('get_questions', $params, $courseid, 0, function() use ($params, $courseid) {
            $questions = question_reader::questions($courseid, $params['categoryids'], $params['questionids']);
            $out = array('questions' => $questions);
            if ($params['includebanks']) {
                $out['banks'] = question_reader::banks($courseid);
            }
            if ($params['includexml']) {
                $ids = array_map(function($question) {
                    return $question['id'];
                }, $questions);
                // Ordre demandé conservé quand des questions sont nommées.
                if ($params['questionids']) {
                    $ids = array_values(array_intersect($params['questionids'], $ids));
                }
                $out['xml'] = question_reader::export_xml($courseid, $ids);
            }
            return array('json' => util::encode($out));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
