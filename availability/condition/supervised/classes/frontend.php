<?php
namespace availability_supervised;

defined('MOODLE_INTERNAL') || die();

/**
 * Formulaire de la condition dans la restriction d'accès : pas de réglage,
 * seulement le lien vers la page Activités surveillées, où l'enseignant
 * ouvre et ferme l'activité. Un verrou (activité liée, fermée pendant une
 * activité surveillée) s'affiche en lecture seule : il se gère depuis la page
 * Activités surveillées.
 */
class frontend extends \core_availability\frontend {

    protected function get_javascript_strings() {
        return array('form_label', 'form_help', 'manage_link', 'form_lock', 'desc_lock_missing');
    }

    /**
     * @return array [URL de la page Activités surveillées, noms des activités du cours (cmid => nom)]
     */
    protected function get_javascript_init_params($course, ?\cm_info $cm = null,
            ?\section_info $section = null) {
        $url = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $course->id));
        $names = array();
        foreach (get_fast_modinfo($course)->get_cms() as $other) {
            if (\local_classhours\gate::is_supervised_json($other->availability)) {
                $names[(int)$other->id] = format_string($other->name, true,
                    array('context' => \context_module::instance($other->id)));
            }
        }
        return array($url->out(false), (object)$names);
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
