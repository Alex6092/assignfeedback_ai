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
 * Crée un cours masqué dans une catégorie bac à sable.
 */
class create_course extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'categoryid'  => new external_value(PARAM_INT, 'Catégorie bac à sable'),
            'fullname'    => new external_value(PARAM_TEXT, 'Nom complet'),
            'shortname'   => new external_value(PARAM_TEXT, 'Nom abrégé (unique)'),
            'idnumber'    => new external_value(PARAM_RAW, 'Numéro d\'identification', VALUE_DEFAULT, ''),
            'summary'     => new external_value(PARAM_RAW, 'Résumé (HTML)', VALUE_DEFAULT, ''),
            'numsections' => new external_value(PARAM_INT, 'Nombre de sections en plus de la section 0', VALUE_DEFAULT, 0),
            'startdate'   => new external_value(PARAM_INT, 'Date de début (horodatage, 0 : aujourd\'hui)', VALUE_DEFAULT, 0),
            'dryrun'      => new external_value(PARAM_BOOL, 'Vérifier sans créer', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $categoryid, string $fullname, string $shortname, string $idnumber = '',
            string $summary = '', int $numsections = 0, int $startdate = 0, bool $dryrun = false): array {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'categoryid' => $categoryid, 'fullname' => $fullname, 'shortname' => $shortname, 'idnumber' => $idnumber,
            'summary' => $summary, 'numsections' => $numsections, 'startdate' => $startdate, 'dryrun' => $dryrun,
        ));
        $context = \context_coursecat::instance((int)$params['categoryid']);
        self::validate_context($context);
        guard::require_category((int)$params['categoryid']);
        require_capability('moodle/course:create', $context);

        return audit::run('create_course', $params, 0, 0, function() use ($params) {
            return array('json' => util::encode(course_writer::create_course((int)$params['categoryid'],
                $params['fullname'], $params['shortname'], array(
                    'idnumber'    => $params['idnumber'],
                    'summary'     => $params['summary'],
                    'numsections' => (int)$params['numsections'],
                    'startdate'   => (int)$params['startdate'],
                ), (bool)$params['dryrun'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
