<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\created;
use local_aibridge\writer\draft_writer;
use local_aibridge\writer\question_writer;

/**
 * Supprime un brouillon du pont : un objet qu'il a créé et qui est encore
 * masqué, ou une catégorie de questions qu'il a créée et dont les questions
 * ne servent plus.
 */
class delete_draft extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'objecttype' => new external_value(PARAM_ALPHA, 'cm, section, course ou questioncategory'),
            'objectid'   => new external_value(PARAM_INT,
                'Identifiant (course_modules.id, course_sections.id, course.id, question_categories.id)'),
            'dryrun'     => new external_value(PARAM_BOOL, 'Vérifier sans supprimer', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(string $objecttype, int $objectid, bool $dryrun = false): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), array(
            'objecttype' => $objecttype, 'objectid' => $objectid, 'dryrun' => $dryrun,
        ));
        $type = $params['objecttype'];
        $id = (int)$params['objectid'];

        // Contexte de connexion : le cours de l'objet (draft_writer vérifie le reste).
        $courseid = 0;
        if ($type === created::CM) {
            $courseid = (int)$DB->get_field('course_modules', 'course', array('id' => $id));
        } else if ($type === created::SECTION) {
            $courseid = (int)$DB->get_field('course_sections', 'course', array('id' => $id));
        } else if ($type === created::COURSE) {
            $courseid = $id;
        } else if ($type === question_writer::CATEGORY) {
            $contextid = (int)$DB->get_field('question_categories', 'contextid', array('id' => $id));
            $context = $contextid ? \context::instance_by_id($contextid, IGNORE_MISSING) : false;
            $coursecontext = $context ? $context->get_course_context(false) : false;
            $courseid = $coursecontext ? (int)$coursecontext->instanceid : 0;
        }
        if ($courseid > 0 && $DB->record_exists('course', array('id' => $courseid))) {
            self::validate_context(\context_course::instance($courseid));
        } else {
            self::validate_context(\context_system::instance());
        }

        return audit::run('delete_draft', $params, $courseid, $type === created::CM ? $id : 0,
            function() use ($type, $id, $params) {
                return array('json' => util::encode(draft_writer::delete($type, $id, (bool)$params['dryrun'])));
            });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
