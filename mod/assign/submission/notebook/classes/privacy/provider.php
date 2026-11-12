<?php
namespace assignsubmission_notebook\privacy;
defined('MOODLE_INTERNAL') || die();
class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        return $collection->add_subsystem_link('local_notebook', [], 'privacy:metadata');
    }
}
