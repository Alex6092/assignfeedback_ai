<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use local_aibridge\audit;
use local_aibridge\reader\site_reader;
use local_aibridge\util;

/**
 * Version de Moodle, plugins du projet, réglages utiles, thème et classes
 * CSS des parcours, état du pont.
 */
class get_site_info extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array());
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), array());
        self::require_bridge_user();
        return audit::run('get_site_info', array(), 0, 0, function() {
            return array('json' => util::encode(site_reader::read()));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
