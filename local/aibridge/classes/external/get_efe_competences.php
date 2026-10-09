<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use local_aibridge\audit;
use local_aibridge\util;

/**
 * Référentiel EFE (N1, N2, N3) tel que local_efenotes le propose dans le
 * formulaire d'activité : même source, même cache.
 */
class get_efe_competences extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array());
    }

    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), array());
        self::require_bridge_user();
        return audit::run('get_efe_competences', array(), 0, 0, function() {
            $out = array('installed' => false, 'configured' => false, 'error' => null,
                'n1' => array(), 'n2' => array(), 'n3' => array());
            if (class_exists('\local_efenotes\referentiel') && class_exists('\local_efenotes\api_client')) {
                $out['installed'] = true;
                $client = new \local_efenotes\api_client();
                if ($client->is_configured()) {
                    $out['configured'] = true;
                    $error = null;
                    $body = \local_efenotes\referentiel::get($error);
                    $out['error'] = $error;
                    foreach (array('n1', 'n2', 'n3') as $level) {
                        $out[$level] = array_values($body[$level] ?? array());
                    }
                }
            }
            return array('json' => util::encode($out));
        });
    }

    public static function execute_returns(): external_single_structure {
        return self::json_returns();
    }
}
