<?php
namespace local_aibridge\external;
defined('MOODLE_INTERNAL') || die();
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class get_notebook_validation extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['versionid' => new external_value(PARAM_INT, 'Version notebook')]);
    }
    public static function execute(int $versionid): array {
        global $DB;
        $p = self::validate_parameters(self::execute_parameters(), compact('versionid'));
        $v = $DB->get_record('local_notebook_version', ['id' => $p['versionid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('assign', $v->cmid, 0, false, MUST_EXIST);
        self::require_course_read($cm->course);
        require_capability('local/notebook:manage', \context_module::instance($cm->id));
        return ['json' => \local_aibridge\util::encode(['version' => $v->id, 'status' => $v->status,
            'fingerprint' => $v->fingerprint, 'report' => $v->report])];
    }
    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
