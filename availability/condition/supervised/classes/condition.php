<?php
namespace availability_supervised;

defined('MOODLE_INTERNAL') || die();

use local_classhours\access;
use local_classhours\gate;

/**
 * Condition « Activité surveillée », sous deux formes :
 *
 *   - {"type":"supervised"} : l'activité n'est ouverte que lorsque
 *     l'enseignant l'ouvre en classe (pour tout le cours, un groupe ou un
 *     élève), depuis la page Activités surveillées ou le bloc, et, si
 *     l'activité demande un code de séance, une fois le code saisi ;
 *   - {"type":"supervised","lock":<cmid>} : activité LIÉE, fermée pendant que
 *     l'activité surveillée <cmid> est ouverte pour l'élève (une leçon, un
 *     cours, qu'on ne doit pas consulter pendant l'évaluation).
 *
 * L'état est celui des ouvertures (local_classhours\gate).
 *
 * Elle dépend du moment : on ne réimplémente pas is_applied_to_user_lists(),
 * pour que les listes d'évaluation (élèves à noter) n'en dépendent pas.
 */
class condition extends \core_availability\condition {

    /** @var int activité surveillée pendant laquelle celle-ci est fermée (0 : activité surveillée elle-même) */
    private $lock = 0;

    /**
     * @param \stdClass $structure JSON décodé
     */
    public function __construct($structure) {
        $this->lock = isset($structure->lock) ? (int)$structure->lock : 0;
    }

    public function save() {
        $result = (object)array('type' => 'supervised');
        if ($this->lock) {
            $result->lock = $this->lock;
        }
        return $result;
    }

    /**
     * JSON d'une condition, pour les tests et le code qui pose la condition.
     *
     * @param int $lock activité surveillée (0 : activité surveillée elle-même)
     * @return \stdClass
     */
    public static function get_json(int $lock = 0) {
        $result = (object)array('type' => 'supervised');
        if ($lock) {
            $result->lock = $lock;
        }
        return $result;
    }

    /**
     * Activité surveillée : ouverte quand l'enseignant l'a ouverte pour
     * l'élève (et le code de séance saisi). Fermée, un devoir ou un test où
     * l'élève a déjà une note, un feedback ou une tentative terminée reste
     * lisible (le verrou de remise et la règle d'accès des tests empêchent tout
     * nouveau travail). Cette lecture ne joue pas sur une condition inversée.
     *
     * Activité liée : fermée tant que l'élève est dans une séance de
     * l'activité surveillée.
     *
     * N'utilise que les champs de base du module (id, modname, instance) : la
     * condition est évaluée pendant la construction des données dynamiques du
     * module, où les accesseurs magiques sont interdits.
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        $courseid = (int)$info->get_course()->id;
        if ($this->lock) {
            $allow = $this->lock < 0 || !gate::in_window($courseid, $this->lock, (int)$userid);
            return $not ? !$allow : $allow;
        }
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
     * Élève : fermée (« seulement quand l'enseignant la lance en classe »),
     * code à saisir, ou ouverte avec l'heure de fermeture prévue.
     * Enseignant (vue complète) : l'état courant et le lien vers la page de
     * pilotage. Activité liée : fermée pendant l'activité surveillée.
     */
    public function get_description($full, $not, \core_availability\info $info) {
        global $USER;
        $courseid = (int)$info->get_course()->id;
        if ($this->lock) {
            $cms = $info->get_modinfo()->cms;
            if ($this->lock < 0 || !isset($cms[$this->lock]) || !empty($cms[$this->lock]->deletioninprogress)) {
                return get_string('desc_lock_missing', 'availability_supervised');
            }
            $name = self::description_cm_name($this->lock);
            return get_string($not ? 'desc_lock_not' : 'desc_lock', 'availability_supervised', $name);
        }
        if ($not) {
            return get_string('desc_not', 'availability_supervised');
        }
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

        $userid = (int)$USER->id;
        if ($cmid && gate::needs_code($courseid, $cmid, $userid)) {
            $url = new \moodle_url('/local/classhours/code.php', array('cmid' => $cmid));
            return get_string('desc_code', 'availability_supervised') . ' '
                . \html_writer::link($url, get_string('code_link', 'availability_supervised'),
                    array('class' => 'btn btn-sm btn-primary ms-1'));
        }
        $end = $cmid ? gate::end_for($courseid, $cmid, $userid) : null;
        if ($end === null) {
            return get_string('desc_closed', 'availability_supervised');
        }
        return $end === 0
            ? get_string('desc_open', 'availability_supervised')
            : get_string('desc_open_until', 'availability_supervised', gate::format_end($end));
    }

    /**
     * Restauration : l'activité surveillée d'un verrou change d'identifiant.
     * Même règle que la condition d'achèvement du cœur : activité non
     * restaurée mais présente dans le cours (duplication) gardée, sinon verrou
     * neutralisé (-1 : ne ferme plus rien, et ne devient surtout pas une
     * activité surveillée) avec un avertissement.
     */
    public function update_after_restore($restoreid, $courseid, \base_logger $logger, $name): bool {
        global $DB;
        if ($this->lock <= 0) {
            return false;
        }
        $rec = \restore_dbops::get_backup_ids_record($restoreid, 'course_module', $this->lock);
        if (!$rec || !$rec->newitemid) {
            if ($DB->record_exists('course_modules', array('id' => $this->lock, 'course' => $courseid))) {
                return false;
            }
            $this->lock = -1;
            $logger->process('Restored item (' . $name . ') has a supervised-activity lock on a module that was not '
                . 'restored', \backup::LOG_WARNING);
            return true;
        }
        $this->lock = (int)$rec->newitemid;
        return true;
    }

    public function update_dependency_id($table, $oldid, $newid) {
        if ($this->lock > 0 && $table === 'course_modules' && (int)$this->lock === (int)$oldid) {
            // Activité surveillée supprimée (newid 0) : verrou neutralisé, pas « activité surveillée ».
            $this->lock = (int)$newid ?: -1;
            return true;
        }
        return false;
    }

    protected function get_debug_string() {
        return $this->lock ? 'lock:' . $this->lock : '';
    }
}
