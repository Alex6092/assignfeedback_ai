<?php
namespace local_aibridge\external;
defined('MOODLE_INTERNAL') || die();
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class set_notebook_assignment extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Devoir brouillon du pont'),
            'bundle' => new external_value(PARAM_RAW, 'Dossier notebook JSON'),
            'dryrun' => new external_value(PARAM_BOOL, 'Simulation', VALUE_DEFAULT, false),
        ]);
    }
    public static function execute(int $cmid, string $bundle, bool $dryrun = false): array {
        $p = self::validate_parameters(self::execute_parameters(), compact('cmid', 'bundle', 'dryrun'));
        [$course, $cm] = \local_aibridge\writer\guard::require_draft_cm($p['cmid'], 'assign');
        self::validate_context(\context_module::instance($cm->id));
        require_capability('moodle/course:manageactivities', \context_course::instance($course->id));
        $decoded = json_decode($p['bundle'], true, 512, JSON_THROW_ON_ERROR);
        return \local_aibridge\audit::run('set_notebook_assignment', ['cmid' => $cm->id, 'dryrun' => $p['dryrun']],
            $course->id, $cm->id, function() use ($cm, $decoded, $p) {
                return ['json' => \local_aibridge\util::encode(\local_notebook\manager::put_version($cm->id, $decoded, $p['dryrun']))];
            });
    }
    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
