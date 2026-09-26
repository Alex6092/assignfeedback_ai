<?php
namespace availability_classhours;

defined('MOODLE_INTERNAL') || die();

use local_classhours\schedule;

/**
 * Formulaire de la condition dans la restriction d'accès : pas de réglage,
 * seulement le résumé des créneaux du cours et le lien vers leur page.
 */
class frontend extends \core_availability\frontend {

    protected function get_javascript_strings() {
        return array('form_label', 'configure', 'noslots_form', 'efe_form');
    }

    /**
     * @return array [résumé des créneaux (HTML sûr), URL de la page Heures de cours, a des créneaux]
     */
    protected function get_javascript_init_params($course, ?\cm_info $cm = null,
            ?\section_info $section = null) {
        $sched = schedule::for_course((int)$course->id);
        $url = new \moodle_url('/local/classhours/manage.php', array('courseid' => $course->id));
        return array($sched->describe_weekly(null, true), $url->out(false), $sched->has_slots());
    }
}
