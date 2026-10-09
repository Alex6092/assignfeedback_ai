<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\reader\module_reader;
use local_aibridge\util;

/**
 * Tout le détail d'une activité : réglages, contenu, note, extensions.
 */
class get_module extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'cmid' => new external_value(PARAM_INT, 'Activité (course_modules.id)'),
        ));
    }

    public static function execute(int $cmid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), array('cmid' => $cmid));
        $cmid = (int)$params['cmid'];
        $courseid = (int)$DB->get_field('course_modules', 'course', array('id' => $cmid), MUST_EXIST);
        self::require_course_read($courseid);
        return audit::run('get_module', $params, $courseid, $cmid, function() use ($cmid) {
            return array('json' => util::encode(module_reader::read($cmid)));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
