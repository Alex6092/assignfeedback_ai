<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\reader\course_reader;
use local_aibridge\util;

/**
 * Réglages d'un cours et son plan (sections, activités, résumé des
 * extensions de chaque activité).
 */
class get_course extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'Cours'),
        ));
    }

    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), array('courseid' => $courseid));
        $courseid = (int)$params['courseid'];
        self::require_course_read($courseid);
        return audit::run('get_course', $params, $courseid, 0, function() use ($courseid) {
            return array('json' => util::encode(course_reader::read($courseid)));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
