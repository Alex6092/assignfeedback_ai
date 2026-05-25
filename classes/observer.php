<?php
namespace assignfeedback_ai;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Déclenché quand un étudiant valide sa soumission ("Soumettre").
     * Crée une ligne pending dans assignfeedback_ai_grade et enqueue une tâche ad-hoc
     * qui appellera l'API LLM en différé (sérialisée par lock global).
     *
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        $cmid = (int)$event->contextinstanceid;
        try {
            list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
        } catch (\Throwable $e) {
            return;
        }

        $context = \context_module::instance($cm->id);
        $assign  = new \assign($context, $cm, $course);

        // Le plugin IA est-il activé pour ce devoir ?
        $plugin = $assign->get_feedback_plugin_by_type('ai');
        if (!$plugin || !$plugin->is_enabled()) {
            return;
        }

        $userid   = (int)$event->userid;
        $assignid = (int)$assign->get_instance()->id;

        if ($userid <= 0 || $assignid <= 0) {
            return;
        }

        // Crée la ligne assign_grades "fantôme" si elle n'existe pas encore.
        $grade = $assign->get_user_grade($userid, true);
        if (!$grade) {
            return;
        }

        \assign_feedback_ai::enqueue_for_grade($assignid, $userid, (int)$grade->id);
    }

    /**
     * Filet de sécurité pour la création/édition d'un devoir : sauvegarde la config IA
     * directement depuis le POST si les champs du form sont présents, et synchronise
     * le flag enabled dans assign_plugin_config.
     *
     * Nécessaire parce que mod_assign skippe parfois save_settings() lors de la toute
     * première création d'un devoir (is_enabled() retourne false avant que la config
     * du plugin existe en base — chicken & egg).
     *
     * @param \core\event\base $event course_module_created OU course_module_updated
     */
    public static function course_module_changed(\core\event\base $event) {
        global $CFG, $DB;

        $other = (array)$event->other;
        $modulename = isset($other['modulename']) ? $other['modulename'] : null;
        if ($modulename !== 'assign') {
            return;
        }

        // Pas de POST → contexte non interactif (backup restore, WS, CLI…). On ne touche pas.
        if (!isset($_POST['assignfeedback_ai_systemprompt'])
            && !isset($_POST['assignfeedback_ai_enabled'])) {
            return;
        }

        $assignid = isset($other['instanceid']) ? (int)$other['instanceid'] : 0;
        if ($assignid <= 0) {
            $cmid = (int)$event->contextinstanceid;
            if ($cmid > 0) {
                $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
                if ($cm) {
                    $assignid = (int)$cm->instance;
                }
            }
        }
        if ($assignid <= 0) {
            return;
        }

        $isenabled = !empty($_POST['assignfeedback_ai_enabled']);

        // 1) Synchronise le flag enabled dans assign_plugin_config.
        $cfg = $DB->get_record('assign_plugin_config', array(
            'assignment' => $assignid,
            'plugin'     => 'ai',
            'subtype'    => 'assignfeedback',
            'name'       => 'enabled',
        ));
        $value = $isenabled ? '1' : '0';
        if ($cfg) {
            if ((string)$cfg->value !== $value) {
                $DB->set_field('assign_plugin_config', 'value', $value, array('id' => $cfg->id));
            }
        } else {
            $DB->insert_record('assign_plugin_config', (object)array(
                'assignment' => $assignid,
                'plugin'     => 'ai',
                'subtype'    => 'assignfeedback',
                'name'       => 'enabled',
                'value'      => $value,
            ));
        }

        // 2) Sauvegarde des champs IA si le plugin est activé pour ce devoir.
        //    On délègue à save_settings() pour ne pas dupliquer la logique.
        if (!$isenabled) {
            return;
        }

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php');

        try {
            $cmid = (int)$event->contextinstanceid;
            list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
            $context = \context_module::instance($cm->id);
            $assign  = new \assign($context, $cm, $course);
            $plugin  = new \assign_feedback_ai($assign, 'ai');

            $formdata = self::build_formdata_from_post($assignid);
            $plugin->save_settings($formdata);
        } catch (\Throwable $e) {
            debugging('assignfeedback_ai observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Reconstruit un objet $formdata à partir de $_POST, dans le format attendu
     * par assign_feedback_ai::save_settings().
     */
    private static function build_formdata_from_post($assignid) {
        $formdata = new \stdClass();
        $formdata->instance = $assignid;

        $fields = array(
            'systemprompt', 'exercise', 'expectedanswer', 'competencies',
            'apiurl', 'apiurl_override',
            'model',  'model_override',
            'apikey', 'apikey_override',
        );
        foreach ($fields as $f) {
            $key = 'assignfeedback_ai_' . $f;
            $formdata->{$key} = isset($_POST[$key]) ? $_POST[$key] : '';
        }
        return $formdata;
    }
}
