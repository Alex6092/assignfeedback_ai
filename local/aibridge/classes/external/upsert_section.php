<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\course_writer;
use local_aibridge\writer\guard;

/**
 * Crée une section si elle manque (masquée), puis règle son nom et son résumé.
 */
class upsert_section extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'Cours (bac à sable)'),
            'section'  => new external_value(PARAM_INT, 'Numéro de la section'),
            'name'     => new external_value(PARAM_TEXT, 'Nom (absent : inchangé)', VALUE_DEFAULT, null, NULL_ALLOWED),
            'summary'  => new external_value(PARAM_RAW, 'Résumé HTML (absent : inchangé)', VALUE_DEFAULT, null, NULL_ALLOWED),
            'dryrun'   => new external_value(PARAM_BOOL, 'Vérifier sans modifier', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $courseid, int $section, ?string $name = null, ?string $summary = null,
            bool $dryrun = false): array {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'courseid' => $courseid, 'section' => $section, 'name' => $name, 'summary' => $summary, 'dryrun' => $dryrun,
        ));
        $courseid = (int)$params['courseid'];
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        $course = guard::require_course($courseid);
        require_capability('moodle/course:update', $context);

        return audit::run('upsert_section', $params, $courseid, 0, function() use ($course, $params) {
            return array('json' => util::encode(course_writer::upsert_section($course, (int)$params['section'],
                $params['name'], $params['summary'], (bool)$params['dryrun'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
