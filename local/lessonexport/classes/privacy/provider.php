<?php
namespace local_lessonexport\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Le plugin ne stocke qu'un réglage par cours, sans aucune donnée personnelle.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
