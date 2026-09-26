<?php
namespace local_classhours\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Vie privée : le plugin ne stocke que l'emploi du temps des cours et des
 * réglages d'activités, aucune donnée personnelle.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
