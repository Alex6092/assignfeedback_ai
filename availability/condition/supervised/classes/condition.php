<?php
namespace availability_supervised;

defined('MOODLE_INTERNAL') || die();

use local_classhours\access;
use local_classhours\gate;

/**
 * Condition « Activité surveillée » : l'activité n'est ouverte que lorsque
 * l'enseignant l'ouvre en classe (pour tout le cours, un groupe ou un élève),
 * depuis la page Activités surveillées ou le bloc.
 *
 * JSON : {"type":"supervised"}, sans paramètre : l'état est celui des
 * ouvertures (local_classhours\gate).
 *
 * Elle dépend du moment : on ne réimplémente pas is_applied_to_user_lists(),
 * pour que les listes d'évaluation (élèves à noter) n'en dépendent pas.
 */
class condition extends \core_availability\condition {

    /**
     * @param \stdClass $structure JSON décodé
     */
    public function __construct($structure) {
    }

    public function save() {
        return (object)array('type' => 'supervised');
    }

    /**
     * JSON d'une condition, pour les tests et le code qui pose la condition.
     *
     * @return \stdClass
     */
    public static function get_json() {
        return (object)array('type' => 'supervised');
    }

    /**
     * Ouverte quand l'enseignant l'a ouverte pour l'élève. Fermée, un devoir
     * ou un test où l'élève a déjà une note, un feedback ou une tentative
     * terminée reste lisible (le verrou de remise et la règle d'accès des
     * tests empêchent tout nouveau travail). Cette lecture ne joue pas sur une
     * condition inversée.
     *
     * N'utilise que les champs de base du module (id, modname, instance) : la
     * condition est évaluée pendant la construction des données dynamiques du
     * module, où les accesseurs magiques sont interdits.
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        $courseid = (int)$info->get_course()->id;
        $allow = false;
        if ($info instanceof \core_availability\info_module) {
            $cm = $info->get_course_module();
            $allow = gate::is_open_for($courseid, (int)$cm->id, (int)$userid);
            if (!$allow && !$not) {
                $allow = access::has_released_work($courseid, $cm, (int)$userid);
            }
        }
        return $not ? !$allow : $allow;
    }

    /**
     * Élève : fermée (« seulement quand l'enseignant la lance en classe ») ou
     * ouverte, avec l'heure de fermeture prévue.
     * Enseignant (vue complète) : l'état courant et le lien vers la page de
     * pilotage.
     */
    public function get_description($full, $not, \core_availability\info $info) {
        global $USER;
        if ($not) {
            return get_string('desc_not', 'availability_supervised');
        }
        $courseid = (int)$info->get_course()->id;
        $cmid = ($info instanceof \core_availability\info_module) ? (int)$info->get_course_module()->id : 0;

        if ($full) {
            $text = get_string('desc_full', 'availability_supervised');
            $openings = $cmid ? gate::active_for_cm($courseid, $cmid) : array();
            if ($openings) {
                $ends = array_map(function($o) {
                    return (int)$o->closeat;
                }, $openings);
                $text .= ' — ' . (in_array(0, $ends, true)
                    ? get_string('state_open', 'availability_supervised')
                    : get_string('state_open_until', 'availability_supervised', gate::format_end(max($ends))));
            } else {
                $text .= ' — ' . get_string('state_closed', 'availability_supervised');
            }
            if (has_capability('local/classhours:supervise', \context_course::instance($courseid))) {
                $url = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
                $text .= ' ' . \html_writer::link($url, get_string('manage_link', 'availability_supervised'));
            }
            return $text;
        }

        $end = $cmid ? gate::end_for($courseid, $cmid, (int)$USER->id) : null;
        if ($end === null) {
            return get_string('desc_closed', 'availability_supervised');
        }
        return $end === 0
            ? get_string('desc_open', 'availability_supervised')
            : get_string('desc_open_until', 'availability_supervised', gate::format_end($end));
    }

    protected function get_debug_string() {
        return '';
    }
}
