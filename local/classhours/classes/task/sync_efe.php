<?php
namespace local_classhours\task;

defined('MOODLE_INTERNAL') || die();

use local_classhours\efe_sync;
use local_classhours\schedule;

/**
 * Filet de sécurité de l'option EFE : resynchronise les cours concernés.
 *
 * Rattrape ce que l'enregistrement d'un formulaire ne voit pas : activités
 * configurées pour EFE par programme (sprints de local_aimissions),
 * restaurations et duplications, changement du défaut du site.
 *
 * Cours concernés : ceux qui ont un emploi du temps, et ceux dont une activité
 * porte déjà la condition (pour la retirer si l'option a été coupée).
 */
class sync_efe extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_sync_efe', 'local_classhours');
    }

    public function execute() {
        $total = 0;
        foreach (self::courseids() as $courseid) {
            try {
                schedule::reset_cache($courseid);
                $total += efe_sync::sync_course($courseid);
            } catch (\Throwable $e) {
                mtrace('local_classhours : cours ' . $courseid . ' — ' . $e->getMessage());
            }
        }
        mtrace('local_classhours : ' . $total . ' activité(s) mise(s) à jour.');
    }

    /**
     * @return int[] cours à synchroniser
     */
    public static function courseids(): array {
        global $DB;
        $ids = array_merge(
            $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_classhours_slot}'),
            $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_classhours_period}'),
            $DB->get_fieldset_sql('SELECT DISTINCT course FROM {course_modules} WHERE '
                . $DB->sql_like('availability', ':pattern'), array('pattern' => '%"classhours"%'))
        );
        $ids = array_unique(array_map('intval', $ids));
        sort($ids);
        return $ids;
    }
}
