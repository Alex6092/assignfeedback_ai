<?php
namespace local_classhours\task;

defined('MOODLE_INTERNAL') || die();

use local_classhours\gate;
use local_classhours\gate_collector;
use local_classhours\schedule;

/**
 * Activités surveillées dont la fin est passée : ramasse le travail en cours
 * à la fin de base, puis, une fois le dernier tiers-temps écoulé, ferme
 * l'ouverture définitivement et ramasse le reste.
 *
 * L'activité, elle, est déjà fermée à chaque élève à son heure (voir gate) :
 * cette tâche ne fait que le ramassage.
 */
class close_supervised extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_close_supervised', 'local_classhours');
    }

    public function execute() {
        // Fin de base : ramassage des élèves sans tiers-temps ; fin du dernier
        // tiers-temps : fermeture définitive et dernier ramassage.
        foreach (gate::process_expired(schedule::now()) as list($cmid, $closedat)) {
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
