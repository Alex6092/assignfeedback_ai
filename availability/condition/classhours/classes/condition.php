<?php
namespace availability_classhours;

defined('MOODLE_INTERNAL') || die();

use local_classhours\access;
use local_classhours\grant;
use local_classhours\schedule;

/**
 * Condition « Pendant les heures de cours ».
 *
 * JSON : {"type":"classhours"}, plus "efe":1 quand elle a été posée par
 * l'option EFE de local_classhours (seules celles-là sont retirées
 * automatiquement). La condition n'a pas d'autre paramètre : les créneaux sont
 * ceux du cours, réglés sur la page Heures de cours.
 *
 * Elle dépend de l'heure : on ne réimplémente pas is_applied_to_user_lists(),
 * pour que les listes d'évaluation (élèves à noter) n'en dépendent pas.
 */
class condition extends \core_availability\condition {

    /** @var bool posée par l'option EFE */
    private $efe;

    /**
     * @param \stdClass $structure JSON décodé
     */
    public function __construct($structure) {
        $this->efe = !empty($structure->efe);
    }

    public function save() {
        $result = (object)array('type' => 'classhours');
        if ($this->efe) {
            $result->efe = 1;
        }
        return $result;
    }

    /**
     * JSON d'une condition, pour les tests et le code qui pose la condition.
     *
     * @param bool $efe
     * @return \stdClass
     */
    public static function get_json(bool $efe = false) {
        $result = (object)array('type' => 'classhours');
        if ($efe) {
            $result->efe = 1;
        }
        return $result;
    }

    /**
     * Ouverte pendant les créneaux de l'élève. Hors créneau, deux exceptions
     * (voir \local_classhours\access) : la lecture d'un devoir ou d'un test où
     * l'élève a déjà une note ou un feedback, et l'accès ponctuel accordé par
     * un enseignant. Elles ne jouent pas sur une condition inversée (« en
     * dehors des heures de cours »), ni sur une section.
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        $courseid = (int)$info->get_course()->id;
        $allow = schedule::for_course($courseid)->is_open_for_user((int)$userid);
        if (!$allow && !$not && $info instanceof \core_availability\info_module) {
            $allow = access::exception_applies($courseid, $info->get_course_module(), (int)$userid);
        }
        return $not ? !$allow : $allow;
    }

    /**
     * Enseignant (vue complète) : tous les créneaux, avec leur groupe.
     * Élève : ses créneaux, et le prochain si l'activité est fermée.
     */
    public function get_description($full, $not, \core_availability\info $info) {
        global $USER;
        $sched = schedule::for_course((int)$info->get_course()->id);
        $groupids = $full ? null : $sched->user_groupids((int)$USER->id);
        $summary = $sched->describe_weekly($groupids, $full);

        $key = $not ? 'desc_not' : 'desc';
        $text = ($summary === '')
            ? get_string($key, 'availability_classhours')
            : get_string($key . '_slots', 'availability_classhours', $summary);

        if (!$sched->has_slots()) {
            return $text . ' ' . get_string('desc_noslots', 'availability_classhours');
        }
        if (!$full && !$not) {
            $now = schedule::now();
            if ($sched->interval_at($groupids, $now, schedule::grace()) === null) {
                $next = $sched->next_opening($groupids, $now);
                if ($next !== null) {
                    $text .= ' ' . get_string('desc_next', 'availability_classhours', $sched->format_time($next));
                }
                $text .= self::request_link($info);
            }
        }
        return $text;
    }

    /**
     * Lien « Demander un accès exceptionnel » (ou « demande en attente ») pour
     * l'élève devant une activité fermée. Moodle affiche ce message sur la page
     * du cours et sur la page « activité restreinte » ; il accepte le HTML, les
     * conditions du cœur y insèrent déjà des liens.
     */
    private static function request_link(\core_availability\info $info): string {
        global $USER;
        if (!($info instanceof \core_availability\info_module) || !isloggedin() || isguestuser()) {
            return '';
        }
        $cm = $info->get_course_module();
        if (!in_array($cm->modname, access::READ_MODS, true)) {
            return '';
        }
        $courseid = (int)$info->get_course()->id;
        if (!has_capability('local/classhours:requestaccess', \context_course::instance($courseid))) {
            return '';
        }
        if (grant::pending_id($courseid, (int)$cm->id, (int)$USER->id) !== null) {
            return ' ' . \html_writer::span(get_string('request_pending', 'availability_classhours'),
                'badge bg-info text-white');
        }
        $url = new \moodle_url('/local/classhours/request.php', array('cmid' => (int)$cm->id));
        return ' ' . \html_writer::link($url, get_string('request_link', 'availability_classhours'),
            array('class' => 'btn btn-sm btn-outline-primary ms-1'));
    }

    protected function get_debug_string() {
        return $this->efe ? 'efe' : '';
    }
}
