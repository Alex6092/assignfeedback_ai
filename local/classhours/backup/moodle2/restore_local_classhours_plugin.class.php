<?php
defined('MOODLE_INTERNAL') || die();

use local_classhours\efe_sync;
use local_classhours\store;

/**
 * Restauration des heures de cours (voir backup_local_classhours_plugin).
 *
 * Les groupes sont restaurés avant le cours : un créneau propre à un groupe
 * est rattaché au groupe restauré. S'il n'a pas de correspondant (groupes non
 * inclus, importation), le créneau est IGNORÉ : mieux vaut fermer pour ce
 * groupe que tout ouvrir à tout le cours.
 */
class restore_local_classhours_plugin extends restore_local_plugin {

    /**
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return array(
            new restore_path_element($this->get_namefor('course'), $this->get_pathfor('/classhours_course')),
            new restore_path_element($this->get_namefor('slot'), $this->get_pathfor('/classhours_slots/classhours_slot')),
            new restore_path_element($this->get_namefor('period'),
                $this->get_pathfor('/classhours_periods/classhours_period')),
        );
    }

    /**
     * Option EFE : reprise seulement si le cours cible ne l'a pas déjà réglée.
     *
     * @param array|\stdClass $data
     */
    public function process_local_classhours_course($data) {
        global $DB;
        $data = (object)$data;
        $courseid = (int)$this->task->get_courseid();
        if (!$DB->record_exists('local_classhours_course', array('courseid' => $courseid))) {
            store::set_efeauto($courseid, !empty($data->efeauto));
        }
    }

    /**
     * @param array|\stdClass $data
     */
    public function process_local_classhours_slot($data) {
        global $DB;
        $data = (object)$data;
        $courseid = (int)$this->task->get_courseid();
        $groupid = $this->map_group((int)$data->groupid);
        if ($groupid === null) {
            return;
        }
        $record = array('courseid' => $courseid, 'groupid' => $groupid, 'weekday' => (int)$data->weekday,
            'starttime' => (int)$data->starttime, 'endtime' => (int)$data->endtime);
        if ($DB->record_exists('local_classhours_slot', $record)) {
            return; // restauration dans un cours qui a déjà ce créneau
        }
        store::add_slot($courseid, $groupid, (int)$data->weekday, (int)$data->starttime, (int)$data->endtime);
    }

    /**
     * Les dates suivent le décalage de la restauration (nouvelle année).
     *
     * @param array|\stdClass $data
     */
    public function process_local_classhours_period($data) {
        global $DB;
        $data = (object)$data;
        if ($data->type !== store::OPEN && $data->type !== store::CLOSED) {
            return;
        }
        $courseid = (int)$this->task->get_courseid();
        $groupid = $this->map_group((int)$data->groupid);
        if ($groupid === null) {
            return;
        }
        $timestart = (int)$this->apply_date_offset((int)$data->timestart);
        $timeend = (int)$this->apply_date_offset((int)$data->timeend);
        if ($DB->record_exists('local_classhours_period', array('courseid' => $courseid, 'groupid' => $groupid,
                'type' => $data->type, 'timestart' => $timestart, 'timeend' => $timeend))) {
            return;
        }
        store::add_period($courseid, $groupid, $data->type, $timestart, $timeend, $data->name ?? null);
    }

    /**
     * Resynchronise l'option EFE une fois toutes les activités (et leur
     * configuration EFE) restaurées. Ne doit jamais faire échouer la
     * restauration : la tâche planifiée rattrapera.
     */
    public function after_restore_course() {
        try {
            efe_sync::sync_course((int)$this->task->get_courseid());
        } catch (\Throwable $e) {
            debugging('local_classhours restore: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return array(
            new restore_path_element($this->get_namefor('exclude'), $this->get_pathfor('/classhours_exclude')),
        );
    }

    /**
     * Exclusion de l'option EFE, rattachée au nouveau module.
     *
     * @param array|\stdClass $data
     */
    public function process_local_classhours_exclude($data) {
        $cmid = (int)$this->task->get_moduleid();
        if ($cmid > 0) {
            store::set_excluded($cmid, (int)$this->task->get_courseid(), true);
        }
    }

    /**
     * Groupe restauré correspondant.
     *
     * @return int|null 0 pour « tout le cours », null si le groupe est perdu
     */
    private function map_group(int $oldgroupid): ?int {
        if ($oldgroupid <= 0) {
            return 0;
        }
        $newid = $this->get_mappingid('group', $oldgroupid);
        return $newid ? (int)$newid : null;
    }
}
