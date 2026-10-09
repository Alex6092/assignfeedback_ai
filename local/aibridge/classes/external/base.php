<?php
namespace local_aibridge\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Socle des fonctions du pont : contrôle d'accès commun et format de
 * réponse (un champ « json »).
 */
abstract class base extends external_api {

    /**
     * Valide le contexte du cours et la capacité de lecture du pont.
     */
    protected static function require_course_read(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/aibridge:read', $context);
        return $context;
    }

    /**
     * Le compte doit pouvoir lire au moins un cours par le pont : c'est ce qui
     * distingue le compte de service d'un utilisateur quelconque du service.
     */
    protected static function require_bridge_user(): void {
        self::validate_context(\context_system::instance());
        if (!get_user_capability_course('local/aibridge:read', null, true, '', '', 1)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/aibridge:read',
                'nopermissions', '');
        }
    }

    /** Réponse commune : la donnée en JSON. */
    protected static function json_returns(): external_single_structure {
        return new external_single_structure(array(
            'json' => new external_value(PARAM_RAW, 'Réponse en JSON (secrets masqués)'),
        ));
    }
}
