<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\guard;
use local_aibridge\writer\question_writer;

/**
 * Importe des questions Moodle XML dans une catégorie de la banque du cours.
 */
class import_questions extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'Cours (bac à sable)'),
            'category' => new external_value(PARAM_TEXT, 'Catégorie de destination (créée si besoin, doit être vide)'),
            'parent'   => new external_value(PARAM_TEXT, 'Catégorie parente (créée si besoin) ; vide : sous « top »',
                VALUE_DEFAULT, ''),
            'xml'      => new external_value(PARAM_RAW, 'Questions au format Moodle XML'),
            'dryrun'   => new external_value(PARAM_BOOL, 'Lire le fichier sans rien écrire', VALUE_DEFAULT, false),
        ));
    }

    public static function execute(int $courseid, string $category, string $parent, string $xml,
            bool $dryrun = false): array {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'courseid' => $courseid, 'category' => $category, 'parent' => $parent, 'xml' => $xml, 'dryrun' => $dryrun,
        ));
        $courseid = (int)$params['courseid'];
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        $course = guard::require_course($courseid);
        require_capability('moodle/course:manageactivities', $context);

        // Au journal : pas le XML (corrigés), seulement sa taille.
        $logparams = array('courseid' => $courseid, 'category' => $params['category'], 'parent' => $params['parent'],
            'xmlbytes' => strlen($params['xml']), 'dryrun' => $params['dryrun']);
        return audit::run('import_questions', $logparams, $courseid, 0, function() use ($course, $params) {
            return array('json' => util::encode(question_writer::import($course, $params['category'],
                $params['parent'], $params['xml'], (bool)$params['dryrun'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
