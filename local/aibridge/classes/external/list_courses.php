<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;

/**
 * Cours lisibles par le pont (capacité local/aibridge:read), avec le chemin
 * de leur catégorie. Filtre texte facultatif sur le nom complet, le nom
 * abrégé et le numéro d'identification.
 */
class list_courses extends base {

    /** Nombre maximal de cours renvoyés. */
    const LIMIT = 500;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'search' => new external_value(PARAM_TEXT, 'Filtre texte (vide : tous)', VALUE_DEFAULT, ''),
        ));
    }

    public static function execute(string $search = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), array('search' => $search));
        self::require_bridge_user();
        return audit::run('list_courses', $params, 0, 0, function() use ($params) {
            $needle = \core_text::strtolower(trim($params['search']));
            $courses = get_user_capability_course('local/aibridge:read', null, true,
                'fullname, shortname, idnumber, category, visible, startdate', 'sortorder') ?: array();
            $categories = \core_course_category::make_categories_list('', 0, ' / ');
            $out = array();
            foreach ($courses as $course) {
                if ((int)$course->id === SITEID) {
                    continue;
                }
                $haystack = \core_text::strtolower($course->fullname . ' ' . $course->shortname . ' ' . $course->idnumber);
                if ($needle !== '' && strpos($haystack, $needle) === false) {
                    continue;
                }
                $out[] = array(
                    'id'        => (int)$course->id,
                    'fullname'  => $course->fullname,
                    'shortname' => $course->shortname,
                    'idnumber'  => $course->idnumber,
                    'visible'   => (int)$course->visible,
                    'startdate' => util::date($course->startdate),
                    'category'  => $categories[$course->category] ?? null,
                    'categoryid' => (int)$course->category,
                );
                if (count($out) >= self::LIMIT) {
                    break;
                }
            }
            return array('json' => util::encode(array('courses' => $out, 'truncated' => count($out) >= self::LIMIT)));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
