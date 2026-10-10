<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\lesson_writer;

/**
 * Remplace les pages de contenu d'une leçon créée par le pont (encore masquée).
 */
class set_lesson_pages extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'cmid'   => new external_value(PARAM_INT, 'Leçon (brouillon du pont)'),
            'pages'  => new external_value(PARAM_RAW, 'Pages en JSON : [{"title": "...", "contents": "<h1>...</h1>"}]'),
            'dryrun' => new external_value(PARAM_BOOL, 'Vérifier sans modifier', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $cmid, string $pages, bool $dryrun = false): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            array('cmid' => $cmid, 'pages' => $pages, 'dryrun' => $dryrun));
        $cmid = (int)$params['cmid'];
        $courseid = (int)$DB->get_field('course_modules', 'course', array('id' => $cmid), MUST_EXIST);
        self::validate_context(\context_course::instance($courseid));
        require_capability('moodle/course:manageactivities', \context_module::instance($cmid));

        $decoded = json_decode($params['pages'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('error_lessonpages', 'local_aibridge');
        }
        $logparams = array('cmid' => $cmid, 'pages' => count($decoded), 'dryrun' => $params['dryrun']);
        return audit::run('set_lesson_pages', $logparams, $courseid, $cmid, function() use ($cmid, $decoded, $params) {
            return array('json' => util::encode(lesson_writer::set_pages($cmid, $decoded, (bool)$params['dryrun'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
