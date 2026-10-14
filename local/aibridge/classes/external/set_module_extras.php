<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\extras_writer;

/**
 * Réglages hors formulaire d'une activité créée par le pont : activité
 * surveillée, activités à achever d'abord, durée minimale, Avancement.
 */
class set_module_extras extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'cmid'   => new external_value(PARAM_INT, 'Activité (brouillon du pont)'),
            'extras' => new external_value(PARAM_RAW, 'JSON : {"supervised": {"planned": "AAAA-MM-JJTHH:MM", '
                . '"sessioncode": true, "exammode": true}, "requires": [cmid, …], "minduration": 300, '
                . '"avancement": {"duedate": "AAAA-MM-JJTHH:MM"}}'),
            'dryrun' => new external_value(PARAM_BOOL, 'Vérifier sans modifier', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $cmid, string $extras, bool $dryrun = false): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            array('cmid' => $cmid, 'extras' => $extras, 'dryrun' => $dryrun));
        $cmid = (int)$params['cmid'];
        $courseid = (int)$DB->get_field('course_modules', 'course', array('id' => $cmid), MUST_EXIST);
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        $decoded = json_decode($params['extras'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('error_extras', 'local_aibridge');
        }
        $logparams = array('cmid' => $cmid, 'extras' => $decoded, 'dryrun' => $params['dryrun']);
        return audit::run('set_module_extras', $logparams, $courseid, $cmid, function() use ($cmid, $decoded, $params) {
            return array('json' => util::encode(extras_writer::apply($cmid, $decoded, (bool)$params['dryrun'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
