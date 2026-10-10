<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\quiz_writer;

/**
 * Remplit un test vide créé par le pont : questions fixes et tirages aléatoires.
 */
class set_quiz_layout extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'cmid'   => new external_value(PARAM_INT, 'Test (brouillon du pont, sans question)'),
            'layout' => new external_value(PARAM_RAW, 'Structure en JSON : {"slots": [{"idnumber": "IA-T5-Q1", '
                . '"maxmark": 2}, {"random": {"categoryid": 12}, "count": 9}], "shufflequestions": true}'),
            'dryrun' => new external_value(PARAM_BOOL, 'Vérifier sans modifier', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $cmid, string $layout, bool $dryrun = false): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            array('cmid' => $cmid, 'layout' => $layout, 'dryrun' => $dryrun));
        $cmid = (int)$params['cmid'];
        $courseid = (int)$DB->get_field('course_modules', 'course', array('id' => $cmid), MUST_EXIST);
        self::validate_context(\context_course::instance($courseid));
        require_capability('mod/quiz:manage', \context_module::instance($cmid));

        $decoded = json_decode($params['layout'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('error_quizlayout', 'local_aibridge');
        }
        $logparams = array('cmid' => $cmid, 'layout' => $decoded, 'dryrun' => $params['dryrun']);
        return audit::run('set_quiz_layout', $logparams, $courseid, $cmid, function() use ($cmid, $decoded, $params) {
            return array('json' => util::encode(quiz_writer::set_layout($cmid, $decoded, (bool)$params['dryrun'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
