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
 * Remplace les pages de contenu d'une leçon créée par le pont (encore masquée),
 * avec les images qu'elles citent en relatif.
 */
class set_lesson_pages extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'cmid'   => new external_value(PARAM_INT, 'Leçon (brouillon du pont)'),
            'pages'  => new external_value(PARAM_RAW, 'Pages en JSON : [{"title": "...", "contents": "<h1>...</h1>"}]'),
            'dryrun' => new external_value(PARAM_BOOL, 'Vérifier sans modifier', VALUE_DEFAULT, false),
            'files'  => new external_value(PARAM_RAW, 'Images citées en relatif par les pages, en JSON : '
                . '[{"filename": "schema.png", "content": "<base64>"}] (png, jpg, gif, svg)', VALUE_DEFAULT, ''),
        ));
    }

    public static function execute(int $cmid, string $pages, bool $dryrun = false, string $files = ''): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            array('cmid' => $cmid, 'pages' => $pages, 'dryrun' => $dryrun, 'files' => $files));
        $cmid = (int)$params['cmid'];
        $courseid = (int)$DB->get_field('course_modules', 'course', array('id' => $cmid), MUST_EXIST);
        self::validate_context(\context_course::instance($courseid));
        require_capability('moodle/course:manageactivities', \context_module::instance($cmid));

        $decoded = json_decode($params['pages'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('error_lessonpages', 'local_aibridge');
        }
        $images = trim($params['files']) === '' ? array() : json_decode($params['files'], true);
        if (!is_array($images)) {
            throw new \moodle_exception('error_lessonimages', 'local_aibridge');
        }
        // Au journal : le nombre d'images, jamais leur contenu.
        $logparams = array('cmid' => $cmid, 'pages' => count($decoded), 'images' => count($images),
            'dryrun' => $params['dryrun']);
        return audit::run('set_lesson_pages', $logparams, $courseid, $cmid, function() use ($cmid, $decoded, $images, $params) {
            return array('json' => util::encode(lesson_writer::set_pages($cmid, $decoded, (bool)$params['dryrun'], $images)));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
