<?php
namespace assignfeedback_ai\task;

defined('MOODLE_INTERNAL') || die();

class generate_feedback_task extends \core\task\adhoc_task {

    /** Nombre max de tentatives avant de basculer une ligne en status=failed. */
    const MAX_ATTEMPTS = 3;

    /** Nombre max de feedbacks traités dans un seul tick (sécurité contre le timeout cron). */
    const BATCH_LIMIT = 20;

    /** Wall time max pour un tick de drainage en secondes. */
    const BATCH_DEADLINE_SEC = 540;

    /** Délai de re-tentative quand le lock global est déjà pris (en secondes). */
    const REQUEUE_LOCK_BUSY = 30;

    public function get_name() {
        return get_string('taskname', 'assignfeedback_ai');
    }

    /**
     * Enqueue une nouvelle tâche pour traiter la ligne $rowid de assignfeedback_ai_grade.
     */
    public static function queue($rowid) {
        $task = new self();
        $task->set_custom_data((object)array('rowid' => (int)$rowid));
        \core\task\manager::queue_adhoc_task($task);
    }

    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        // On essaie d'acquérir le lock global qui sérialise les appels LLM.
        $factory = \core\lock\lock_config::get_lock_factory('assignfeedback_ai');
        $lock    = $factory->get_lock('llm_call', 5);

        if (!$lock) {
            // Un autre tick draine déjà la queue. Plutôt que de subir le backoff
            // exponentiel de Moodle (qui laisserait le LLM inactif longtemps après
            // que le drain ait fini), on se re-planifie dans REQUEUE_LOCK_BUSY s.
            // Comme ça, dès que le drain finit, le prochain tick le récupère vite.
            $next = new self();
            $next->set_custom_data($this->get_custom_data());
            $next->set_next_run_time(time() + self::REQUEUE_LOCK_BUSY);
            \core\task\manager::queue_adhoc_task($next);
            mtrace("assignfeedback_ai: lock occupé, re-planification dans "
                . self::REQUEUE_LOCK_BUSY . "s");
            return; // Pas d'exception → pas de faildelay imposé par Moodle.
        }

        try {
            // 1) On traite la ligne explicitement demandée par cette tâche.
            $data    = $this->get_custom_data();
            $myrowid = isset($data->rowid) ? (int)$data->rowid : 0;
            $ok      = $this->try_process($myrowid);

            if (!$ok) {
                // Échec API : on s'arrête là pour laisser le mécanisme de retry
                // (avec ses MAX_ATTEMPTS) faire son travail. Pas de drainage en
                // cas d'erreur, sinon on hammer un LLM qui semble en panne.
                return;
            }

            // 2) Drainage : tant qu'on a le lock LLM, on enchaîne les autres
            //    lignes pending. Ça évite l'inactivité entre tasks et tient compte
            //    aussi des soumissions qui arriveraient PENDANT qu'on draine.
            $deadline  = time() + self::BATCH_DEADLINE_SEC;
            $processed = 1; // myrowid déjà traité

            while ($processed < self::BATCH_LIMIT && time() < $deadline) {
                $rows = $DB->get_records_sql(
                    'SELECT id FROM {' . \assign_feedback_ai::TABLE_GRADE . '}
                     WHERE status = ?
                     ORDER BY timecreated ASC',
                    array(\assign_feedback_ai::STATUS_PENDING),
                    0, 1
                );
                $next = $rows ? reset($rows) : null;
                if (!$next) {
                    break; // Queue vide.
                }
                if ((int)$next->id === $myrowid) {
                    break; // Sécurité : ne devrait pas arriver, mais évite la boucle.
                }
                if (!$this->try_process((int)$next->id)) {
                    break; // Échec : on relâche, le retry classique reprendra.
                }
                $processed++;
            }

            mtrace("assignfeedback_ai: tick terminé, $processed feedback(s) générés");
        } finally {
            $lock->release();
        }
    }

    /**
     * Traite une ligne assignfeedback_ai_grade.
     * Retourne true si succès OU si la ligne n'a rien à faire (déjà traitée, supprimée…).
     * Retourne false sur échec d'appel API (incrémente attempts et requeue si possible).
     */
    private function try_process($rowid) {
        global $DB;

        if ($rowid <= 0) {
            return true;
        }

        $fb = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
        if (!$fb) {
            return true; // Ligne supprimée entre-temps.
        }
        if ($fb->status !== \assign_feedback_ai::STATUS_PENDING) {
            return true; // Déjà generated ou failed.
        }

        try {
            list($course, $cm) = get_course_and_cm_from_instance($fb->assignment, 'assign');
            $context = \context_module::instance($cm->id);
            $assign  = new \assign($context, $cm, $course);
            $plugin  = new \assign_feedback_ai($assign, 'ai');
            $plugin->process_feedback_row($rowid);
            return true;
        } catch (\Throwable $e) {
            $this->record_failure($rowid, $e->getMessage());
            $current = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
            if ($current && (int)$current->attempts < self::MAX_ATTEMPTS) {
                self::queue($rowid);
            }
            mtrace("assignfeedback_ai: échec sur la ligne $rowid : " . $e->getMessage());
            return false;
        }
    }

    private function record_failure($rowid, $errmsg) {
        global $DB;

        $fb = $DB->get_record(\assign_feedback_ai::TABLE_GRADE, array('id' => $rowid));
        if (!$fb) {
            return;
        }

        $fb->attempts      = (int)$fb->attempts + 1;
        $fb->error_message = (string)$errmsg;
        $fb->timemodified  = time();

        if ($fb->attempts >= self::MAX_ATTEMPTS) {
            $fb->status = \assign_feedback_ai::STATUS_FAILED;
        } else {
            $fb->status = \assign_feedback_ai::STATUS_PENDING;
        }

        $DB->update_record(\assign_feedback_ai::TABLE_GRADE, $fb);
    }
}
