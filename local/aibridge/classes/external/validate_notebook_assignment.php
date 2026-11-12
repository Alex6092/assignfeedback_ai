<?php
namespace local_aibridge\external;
defined('MOODLE_INTERNAL') || die();
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class validate_notebook_assignment extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['versionid' => new external_value(PARAM_INT, 'Version notebook')]);
    }
    public static function execute(int $versionid): array {
        global $DB;
        $p = self::validate_parameters(self::execute_parameters(), compact('versionid'));
        $v = $DB->get_record('local_notebook_version', ['id' => $p['versionid']], '*', MUST_EXIST);
        [$course, $cm] = \local_aibridge\writer\guard::require_draft_cm($v->cmid, 'assign');
        self::validate_context(\context_module::instance($cm->id));
        return \local_aibridge\audit::run('validate_notebook_assignment', ['versionid' => $v->id],
            $course->id, $cm->id, function() use ($v) {
                return ['json' => \local_aibridge\util::encode(\local_notebook\manager::validate($v->id))];
            });
    }
    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
