<?php
namespace local_classhours\task;

defined('MOODLE_INTERNAL') || die();

use local_classhours\gate;
use local_classhours\gate_collector;
use local_classhours\schedule;

/**
 * Activités surveillées ouvertes avec une durée : à l'heure prévue, marque
 * l'ouverture fermée et ramasse le travail en cours.
 *
 * L'activité, elle, est déjà fermée aux élèves à l'heure prévue (voir gate) :
 * cette tâche ne fait que le ramassage.
 */
class close_supervised extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_close_supervised', 'local_classhours');
    }

    public function execute() {
        $closed = gate::close_expired(schedule::now());
        foreach ($closed as $cmid => $closedat) {
            try {
                $done = gate_collector::collect((int)$cmid, (int)$closedat);
                mtrace('local_classhours : activité ' . $cmid . ' fermée — ' . $done['quiz'] . ' tentative(s) envoyée(s), '
                    . $done['assign'] . ' brouillon(s) remis.');
            } catch (\Throwable $e) {
                mtrace('local_classhours : ramassage de l\'activité ' . $cmid . ' — ' . $e->getMessage());
            }
        }
    }
}
