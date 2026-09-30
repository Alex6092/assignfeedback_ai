<?php
namespace availability_supervised\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Vie privée : la condition ne stocke aucune donnée personnelle (les
 * ouvertures sont dans local_classhours).
 */
class provider implements \core_privacy\local\metadata\null_provider {

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
