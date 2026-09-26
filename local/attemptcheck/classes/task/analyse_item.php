<?php
namespace local_attemptcheck\task;

defined('MOODLE_INTERNAL') || die();

use local_attemptcheck\notifier;

/**
 * Analyse d'une tentative juste remise, hors de la requête de l'élève (le
 * journal de la remise est alors écrit), et notification des enseignants.
 *
 * Données : type (quiz|assign), id.
 */
class analyse_item extends \core\task\adhoc_task {

    public function get_name() {
        return get_string('task_analyse', 'local_attemptcheck');
    }

    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data->type) || empty($data->id) || !in_array($data->type, array('quiz', 'assign'), true)) {
            return;
        }
        notifier::check((string)$data->type, (int)$data->id);
    }

    /** Met l'analyse d'une tentative en file. */
    public static function queue(string $type, int $id): void {
        $task = new self();
        $task->set_component('local_attemptcheck');
        $task->set_custom_data((object)array('type' => $type, 'id' => $id));
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
