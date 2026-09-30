<?php
namespace local_classhours\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_classhours\supervised_view;

/**
 * Rafraîchissement des activités surveillées, sans recharger la page :
 *   - view = block : le contenu du bloc block_supervised (tableau de bord,
 *     page du cours), pour tout utilisateur connecté ;
 *   - view = page : l'état et les compteurs de chaque activité surveillée
 *     d'un cours, pour la page de pilotage (local/classhours:supervise).
 */
class supervised_refresh extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'Cours (0 : tableau de bord)', VALUE_DEFAULT, 0),
            'view'     => new external_value(PARAM_ALPHA, 'block ou page', VALUE_DEFAULT, 'block'),
        ));
    }

    public static function execute(int $courseid = 0, string $view = 'block'): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(),
            array('courseid' => $courseid, 'view' => $view));
        $courseid = (int)$params['courseid'];

        if ($courseid > SITEID) {
            $context = \context_course::instance($courseid);
        } else {
            $context = \context_user::instance((int)$USER->id);
        }
        self::validate_context($context);

        if ($params['view'] === 'page') {
            require_capability('local/classhours:supervise', $context);
            $returnurl = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
            $items = array();
            foreach (supervised_view::course_cms($courseid) as $cmid => $cm) {
                $state = supervised_view::state_html($courseid, $cmid, $returnurl, true);
                $items[] = array(
                    'cmid'         => $cmid,
                    'statehtml'    => $state,
                    // Sans le sesskey des formulaires, identique d'un appel à l'autre.
                    'signature'    => md5(preg_replace('/name="sesskey" value="[^"]*"/', '', $state)),
                    'countershtml' => supervised_view::counters_html($cm, $courseid),
                );
            }
            return array('html' => '', 'signature' => '', 'items' => $items);
        }

        $html = supervised_view::block_html($courseid, (int)$USER->id);
        return array(
            'html'      => $html,
            'signature' => md5(preg_replace('/name="sesskey" value="[^"]*"/', '', $html)),
            'items'     => array(),
        );
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array(
            'html'      => new external_value(PARAM_RAW, 'Contenu du bloc'),
            'signature' => new external_value(PARAM_RAW, 'Empreinte du contenu du bloc'),
            'items'     => new external_multiple_structure(new external_single_structure(array(
                'cmid'         => new external_value(PARAM_INT, 'Activité'),
                'statehtml'    => new external_value(PARAM_RAW, 'État (ouverte, fermée, boutons)'),
                'signature'    => new external_value(PARAM_RAW, 'Empreinte de l\'état'),
                'countershtml' => new external_value(PARAM_RAW, 'Compteurs'),
            ))),
        ));
    }
}
