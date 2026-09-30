<?php
namespace availability_supervised;

defined('MOODLE_INTERNAL') || die();

/**
 * Formulaire de la condition dans la restriction d'accès : pas de réglage,
 * seulement le lien vers la page Activités surveillées, où l'enseignant
 * ouvre et ferme l'activité.
 */
class frontend extends \core_availability\frontend {

    protected function get_javascript_strings() {
        return array('form_label', 'form_help', 'manage_link');
    }

    /**
     * @return array [URL de la page Activités surveillées]
     */
    protected function get_javascript_init_params($course, ?\cm_info $cm = null,
            ?\section_info $section = null) {
        $url = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $course->id));
        return array($url->out(false));
    }

    /**
     * Une section ne peut pas être « ouverte » par la page de pilotage, qui
     * travaille activité par activité : la condition n'est proposée que sur
     * une activité.
     */
    protected function allow_add($course, ?\cm_info $cm = null, ?\section_info $section = null) {
        return $section === null;
    }
}
