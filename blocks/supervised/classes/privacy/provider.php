<?php
namespace block_supervised\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Vie privée : le bloc n'affiche que des données de local_classhours et n'en
 * stocke aucune.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
