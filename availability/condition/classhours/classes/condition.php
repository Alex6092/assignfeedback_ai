<?php
namespace availability_classhours;

defined('MOODLE_INTERNAL') || die();

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

    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        $allow = schedule::for_course((int)$info->get_course()->id)->is_open_for_user((int)$userid);
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
            }
        }
        return $text;
    }

    protected function get_debug_string() {
        return $this->efe ? 'efe' : '';
    }
}
