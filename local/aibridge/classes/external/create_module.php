<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aibridge\audit;
use local_aibridge\util;
use local_aibridge\writer\guard;
use local_aibridge\writer\module_writer;

/**
 * Crée une activité masquée dans un cours bac à sable. Les réglages sont les
 * champs du formulaire de l'activité, en JSON.
 */
class create_module extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'Cours (bac à sable)'),
            'section'  => new external_value(PARAM_INT, 'Numéro de la section'),
            'modname'  => new external_value(PARAM_PLUGIN, 'Type : page, label, url, lesson, quiz, assign'),
            'name'     => new external_value(PARAM_TEXT, 'Nom affiché'),
            'intro'    => new external_value(PARAM_RAW, 'Description (HTML)', VALUE_DEFAULT, ''),
            'settings' => new external_value(PARAM_RAW, 'Champs du formulaire de l\'activité (objet JSON)',
                VALUE_DEFAULT, '{}'),
            'dryrun'   => new external_value(PARAM_BOOL, 'Vérifier sans créer', VALUE_DEFAULT, false),
            'beforecmid' => new external_value(PARAM_INT, 'Placer avant cette activité (même section) ; 0 : à la fin',
                VALUE_DEFAULT, 0),
        ));
    }

    public static function execute(int $courseid, int $section, string $modname, string $name, string $intro = '',
            string $settings = '{}', bool $dryrun = false, int $beforecmid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'courseid' => $courseid, 'section' => $section, 'modname' => $modname, 'name' => $name,
            'intro' => $intro, 'settings' => $settings, 'dryrun' => $dryrun, 'beforecmid' => $beforecmid,
        ));
        $courseid = (int)$params['courseid'];
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        $course = guard::require_course($courseid);
        require_capability('moodle/course:manageactivities', $context);

        // Un objet JSON (liste de champs nommés), pas une liste.
        $decoded = json_decode(trim($params['settings']) === '' ? '{}' : $params['settings'], true);
        if (!is_array($decoded) || ($decoded && array_keys($decoded) === range(0, count($decoded) - 1))) {
            throw new \moodle_exception('error_settings', 'local_aibridge');
        }

        // Au journal, les réglages décodés : le masquage des secrets porte sur les noms de champs.
        $logparams = $params;
        $logparams['settings'] = $decoded;
        return audit::run('create_module', $logparams, $courseid, 0, function() use ($course, $params, $decoded) {
            return array('json' => util::encode(module_writer::create($course, (int)$params['section'],
                $params['modname'], $params['name'], $params['intro'], $decoded, (bool)$params['dryrun'],
                (int)$params['beforecmid'])));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
