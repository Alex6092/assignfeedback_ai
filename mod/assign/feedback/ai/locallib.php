<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Classe principale du plugin assignfeedback_ai.
 *
 * REGLE MOODLE : le nom DOIT etre exactement assign_feedback_{pluginname}
 *                et DOIT etendre assign_plugin
 *
 * Compatibilite PHP : 7.2+ (pas d'arrow functions, pas de type hints scalaires)
 *
 * @package assignfeedback_ai
 */
class assign_feedback_ai extends assign_feedback_plugin {
    //assign_plugin {

    // Noms des tables DB (cohérents avec install.xml)
    const TABLE_CONFIG = 'assignfeedback_ai';
    const TABLE_GRADE  = 'assignfeedback_ai_grade';

    // Statuts possibles d'un feedback
    const STATUS_PENDING   = 'pending';   // En attente / en cours de génération
    const STATUS_GENERATED = 'generated'; // Généré avec succès, visible par l'étudiant
    const STATUS_FAILED    = 'failed';    // Échec après MAX_ATTEMPTS tentatives

    // =========================================================
    //  METHODES OBLIGATOIRES (interface assign_plugin)
    // =========================================================

    public function get_name() {
        return get_string('pluginname', 'assignfeedback_ai');
    }

    /**
     * Requis par assign_plugin depuis Moodle 4.2.
     * Doit retourner 'feedback' pour un plugin de type assignfeedback_*.
     *
     * @return string
     */
    public function get_subtype() {
        return 'assignfeedback';
    }

    public function can_upgrade($type, $version) {
        return false;
    }

    /**
     * Ajoute les champs dans le formulaire d'édition du devoir.
     * Appelé automatiquement par mod_assign quand le plugin est activé.
     */
    public function get_settings(MoodleQuickForm $mform) {
        
        $assignid = 0;
        
        if($this->assignment !== null) {
            try {
                // get_instance() retourne null lors de la CREATION d'un nouveau devoir
                // (le devoir n'existe pas encore en base). On doit protéger cet appel.
                $instance = $this->assignment->get_instance();
                if ($instance !== null && !empty($instance->id)) {
                    $assignid = (int)$instance->id;
                }
            }
            catch (Throwable $e) {
                $assignid = 0;
            }
        }

        $cfg = $this->load_config($assignid);

        // Valeurs par défaut globales — désormais dans local_aifeedback.
        $def_url    = (string)get_config('local_aifeedback', 'apiurl');
        $def_model  = (string)get_config('local_aifeedback', 'model');
        $def_prompt = (string)get_config('local_aifeedback', 'defaultsystemprompt');

        if ($def_url   === '') { $def_url   = 'http://localhost:1234/v1/chat/completions'; }
        if ($def_model === '') { $def_model = 'qwen3.5-9b-instruct'; }
        if ($def_prompt === '') { $def_prompt = self::default_system_prompt(); }

        // --- Prompt système ---
        $mform->addElement('textarea', 'assignfeedback_ai_systemprompt',
            get_string('systemprompt', 'assignfeedback_ai'),
            array('rows' => 8, 'cols' => 60));
        $mform->setType('assignfeedback_ai_systemprompt', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_ai_systemprompt', 'systemprompt', 'assignfeedback_ai');
        $mform->setDefault('assignfeedback_ai_systemprompt',
            ($cfg && $cfg->systemprompt !== null) ? $cfg->systemprompt : $def_prompt);

        // --- Énoncé de l'exercice ---
        $mform->addElement('textarea', 'assignfeedback_ai_exercise',
            get_string('exercise', 'assignfeedback_ai'),
            array('rows' => 5, 'cols' => 60));
        $mform->setType('assignfeedback_ai_exercise', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_ai_exercise', 'exercise', 'assignfeedback_ai');
        $mform->setDefault('assignfeedback_ai_exercise',
            ($cfg && $cfg->exercise !== null) ? $cfg->exercise : '');

        // --- Corrigé de référence ---
        $mform->addElement('textarea', 'assignfeedback_ai_expectedanswer',
            get_string('expectedanswer', 'assignfeedback_ai'),
            array('rows' => 6, 'cols' => 60));
        $mform->setType('assignfeedback_ai_expectedanswer', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_ai_expectedanswer', 'expectedanswer', 'assignfeedback_ai');
        $mform->setDefault('assignfeedback_ai_expectedanswer',
            ($cfg && $cfg->expectedanswer !== null) ? $cfg->expectedanswer : '');

        // --- Compétences évaluées ---
        $mform->addElement('textarea', 'assignfeedback_ai_competencies',
            get_string('competencies', 'assignfeedback_ai'),
            array('rows' => 3, 'cols' => 60));
        $mform->setType('assignfeedback_ai_competencies', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_ai_competencies', 'competencies', 'assignfeedback_ai');
        $mform->setDefault('assignfeedback_ai_competencies',
            ($cfg && $cfg->competencies !== null) ? $cfg->competencies : '');

        // --- URL API (override optionnel de la config globale) ---
        $mform->addElement('advcheckbox', 'assignfeedback_ai_apiurl_override',
            get_string('apiurl_override', 'assignfeedback_ai'));
        $mform->setDefault('assignfeedback_ai_apiurl_override',
            ($cfg && !empty($cfg->apiurl_override)) ? 1 : 0);

        $mform->addElement('text', 'assignfeedback_ai_apiurl',
            get_string('apiurl', 'assignfeedback_ai'), array('size' => 60));
        $mform->setType('assignfeedback_ai_apiurl', PARAM_URL);
        $mform->setDefault('assignfeedback_ai_apiurl',
            ($cfg && !empty($cfg->apiurl)) ? $cfg->apiurl : $def_url);
        $mform->disabledIf('assignfeedback_ai_apiurl',
            'assignfeedback_ai_apiurl_override', 'notchecked');

        // --- Modèle IA (override optionnel) ---
        $mform->addElement('advcheckbox', 'assignfeedback_ai_model_override',
            get_string('model_override', 'assignfeedback_ai'));
        $mform->setDefault('assignfeedback_ai_model_override',
            ($cfg && !empty($cfg->model_override)) ? 1 : 0);

        $mform->addElement('text', 'assignfeedback_ai_model',
            get_string('model', 'assignfeedback_ai'), array('size' => 40));
        $mform->setType('assignfeedback_ai_model', PARAM_TEXT);
        $mform->setDefault('assignfeedback_ai_model',
            ($cfg && !empty($cfg->model)) ? $cfg->model : $def_model);
        $mform->disabledIf('assignfeedback_ai_model',
            'assignfeedback_ai_model_override', 'notchecked');

        // --- Clé API (override optionnel) ---
        $mform->addElement('advcheckbox', 'assignfeedback_ai_apikey_override',
            get_string('apikey_override', 'assignfeedback_ai'));
        $mform->setDefault('assignfeedback_ai_apikey_override',
            ($cfg && !empty($cfg->apikey_override)) ? 1 : 0);

        $mform->addElement('passwordunmask', 'assignfeedback_ai_apikey',
            get_string('apikey', 'assignfeedback_ai'), array('size' => 60));
        $mform->setType('assignfeedback_ai_apikey', PARAM_RAW);
        $mform->setDefault('assignfeedback_ai_apikey',
            ($cfg && !empty($cfg->apikey)) ? self::decrypt_secret($cfg->apikey) : '');
        $mform->disabledIf('assignfeedback_ai_apikey',
            'assignfeedback_ai_apikey_override', 'notchecked');

        // --- Vision (override optionnel) ---
        $def_vision = (int)get_config('local_aifeedback', 'vision_enabled');

        $mform->addElement('advcheckbox', 'assignfeedback_ai_vision_enabled_override',
            get_string('vision_enabled_override', 'assignfeedback_ai'));
        $mform->setDefault('assignfeedback_ai_vision_enabled_override',
            ($cfg && !empty($cfg->vision_enabled_override)) ? 1 : 0);

        $mform->addElement('advcheckbox', 'assignfeedback_ai_vision_enabled',
            get_string('vision_enabled', 'assignfeedback_ai'));
        $mform->setDefault('assignfeedback_ai_vision_enabled',
            ($cfg && !empty($cfg->vision_enabled_override))
                ? (int)$cfg->vision_enabled
                : $def_vision);
        $mform->disabledIf('assignfeedback_ai_vision_enabled',
            'assignfeedback_ai_vision_enabled_override', 'notchecked');

        // Cache tous les champs si le plugin IA n'est pas activé pour ce devoir.
        $hideifoff = array(
            'assignfeedback_ai_systemprompt',
            'assignfeedback_ai_exercise',
            'assignfeedback_ai_expectedanswer',
            'assignfeedback_ai_competencies',
            'assignfeedback_ai_apiurl_override',
            'assignfeedback_ai_apiurl',
            'assignfeedback_ai_model_override',
            'assignfeedback_ai_model',
            'assignfeedback_ai_apikey_override',
            'assignfeedback_ai_apikey',
            'assignfeedback_ai_vision_enabled_override',
            'assignfeedback_ai_vision_enabled',
        );
        foreach ($hideifoff as $field) {
            $mform->hideIf($field, 'assignfeedback_ai_enabled', 'notchecked');
        }
    }

    /**
     * Persiste la configuration du plugin pour ce devoir.
     */
    public function save_settings(stdClass $formdata) {
        global $DB;

        $assignid = !empty($formdata->instance) ? (int)$formdata->instance : 0;
        if ($assignid === 0) {
            return true;
        }

        $row = new stdClass();
        $row->assignment      = $assignid;
        $row->systemprompt    = isset($formdata->assignfeedback_ai_systemprompt)
                                ? $formdata->assignfeedback_ai_systemprompt : '';
        $row->exercise        = isset($formdata->assignfeedback_ai_exercise)
                                ? $formdata->assignfeedback_ai_exercise : '';
        $row->expectedanswer  = isset($formdata->assignfeedback_ai_expectedanswer)
                                ? $formdata->assignfeedback_ai_expectedanswer : '';
        $row->competencies    = isset($formdata->assignfeedback_ai_competencies)
                                ? $formdata->assignfeedback_ai_competencies : '';

        $row->apiurl          = isset($formdata->assignfeedback_ai_apiurl)
                                ? $formdata->assignfeedback_ai_apiurl : '';
        $row->apiurl_override = !empty($formdata->assignfeedback_ai_apiurl_override) ? 1 : 0;

        $row->model           = isset($formdata->assignfeedback_ai_model)
                                ? $formdata->assignfeedback_ai_model : '';
        $row->model_override  = !empty($formdata->assignfeedback_ai_model_override) ? 1 : 0;

        $plainkey             = isset($formdata->assignfeedback_ai_apikey)
                                ? (string)$formdata->assignfeedback_ai_apikey : '';
        $row->apikey          = ($plainkey === '') ? '' : self::encrypt_secret($plainkey);
        $row->apikey_override = !empty($formdata->assignfeedback_ai_apikey_override) ? 1 : 0;

        $row->vision_enabled          = !empty($formdata->assignfeedback_ai_vision_enabled) ? 1 : 0;
        $row->vision_enabled_override = !empty($formdata->assignfeedback_ai_vision_enabled_override) ? 1 : 0;

        $row->timemodified    = time();

        $existing = $DB->get_record(self::TABLE_CONFIG, array('assignment' => $assignid));
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record(self::TABLE_CONFIG, $row);
        } else {
            $row->timecreated = time();
            $DB->insert_record(self::TABLE_CONFIG, $row);
        }

        return true;
    }

    /**
     * Affiche le résumé dans la colonne feedback de la grille de notation.
     * Vue contextuelle : enseignant voit l'état réel (pending/failed/generated),
     * étudiant ne voit que "en cours" tant que le feedback n'est pas généré.
     */
    public function view_summary(stdClass $grade, &$showviewlink) {
        global $DB;

        $showviewlink = false;
        $instance     = $this->assignment->get_instance();
        $assignid     = ($instance !== null) ? (int)$instance->id : 0;
        $context      = $this->assignment->get_context();
        $isteacher    = has_capability('mod/assign:grade', $context);
        $cmid         = (int)$this->assignment->get_course_module()->id;

        $fb = ($assignid > 0) ? $DB->get_record(self::TABLE_GRADE, array(
            'assignment' => $assignid,
            'grade'      => (int)$grade->id,
        )) : false;

        // Lien "Gérer" toujours visible côté enseignant.
        $managelink = '';
        if ($isteacher) {
            $manageurl  = new moodle_url('/mod/assign/feedback/ai/manage.php', array('id' => $cmid));
            $managelink = ' ' . html_writer::link($manageurl,
                '[' . get_string('manage', 'assignfeedback_ai') . ']',
                array('class' => 'small text-muted'));
        }

        // Pas encore de ligne ou en attente.
        if (!$fb || $fb->status === self::STATUS_PENDING) {
            return html_writer::div(
                html_writer::span(get_string('generationpending', 'assignfeedback_ai'), 'text-muted')
                . $managelink,
                'assignfeedback-ai-summary'
            );
        }

        // Échec : l'étudiant voit "en cours", l'enseignant voit l'erreur.
        if ($fb->status === self::STATUS_FAILED) {
            if (!$isteacher) {
                return html_writer::span(
                    get_string('generationpending', 'assignfeedback_ai'),
                    'text-muted'
                );
            }
            return html_writer::div(
                html_writer::span(get_string('generationfailed', 'assignfeedback_ai'),
                    'badge badge-danger mr-1') . $managelink,
                'assignfeedback-ai-summary'
            );
        }

        // status == generated
        $result = json_decode($fb->aifeedback, true);
        if (!is_array($result)) {
            return html_writer::span(
                get_string('feedbackerror', 'assignfeedback_ai'),
                'text-danger'
            );
        }

        // Côté étudiant : on retourne la carte complète directement en ligne.
        if (!$isteacher) {
            return $this->render_card($result, $fb->status);
        }

        // Côté enseignant : version compacte pour la grille de notation,
        // avec un lien "voir" qui appellera view() pour le détail.
        $showviewlink = true;

        $score  = isset($result['score'])  ? (int)$result['score'] . '/100' : '—';
        $niveau = isset($result['niveau']) ? $result['niveau'] : '—';
        $badge  = $this->niveau_to_badge($niveau);

        return html_writer::div(
            html_writer::span($score, 'font-weight-bold mr-1') .
            html_writer::span(s($niveau), 'badge badge-' . $badge) .
            $managelink,
            'assignfeedback-ai-summary'
        );
    }

    /**
     * Affiche la vue complète du feedback.
     */
    public function view(stdClass $grade) {
        global $DB;

        $instance  = $this->assignment->get_instance();
        $assignid  = ($instance !== null) ? (int)$instance->id : 0;
        $context   = $this->assignment->get_context();
        $isteacher = has_capability('mod/assign:grade', $context);

        $fb = ($assignid > 0) ? $DB->get_record(self::TABLE_GRADE, array(
            'assignment' => $assignid,
            'grade'      => (int)$grade->id,
        )) : false;

        if (!$fb || $fb->status === self::STATUS_PENDING) {
            return html_writer::div(
                get_string('generationpending', 'assignfeedback_ai'),
                'alert alert-info'
            );
        }

        if ($fb->status === self::STATUS_FAILED) {
            if (!$isteacher) {
                return html_writer::div(
                    get_string('generationpending', 'assignfeedback_ai'),
                    'alert alert-info'
                );
            }
            $msg = get_string('generationfailed', 'assignfeedback_ai');
            if (!empty($fb->error_message)) {
                $msg .= ' — ' . s($fb->error_message);
            }
            return html_writer::div($msg, 'alert alert-danger');
        }

        $result = json_decode($fb->aifeedback, true);
        if (!is_array($result)) {
            return html_writer::div(
                get_string('feedbackerror', 'assignfeedback_ai'), 'alert alert-danger'
            );
        }

        return $this->render_card($result, $fb->status);
    }

    public function is_feedback_modified(stdClass $grade, stdClass $submissionorgrade) {
        return false;
    }

    public function has_feedback(stdClass $grade) {
        global $DB;
        return $DB->record_exists(self::TABLE_GRADE, array(
            'assignment' => (int)$this->assignment->get_instance()->id,
            'grade'      => (int)$grade->id,
        ));
    }

    /**
     * Indique à mod_assign si le plugin a quelque chose à montrer pour cette note.
     * Conditionne l'affichage dans la section Feedback côté étudiant et la grille enseignant.
     */
    public function is_empty(stdClass $grade) {
        global $DB;
        $assignid = (int)$this->assignment->get_instance()->id;
        $row = $DB->get_record(self::TABLE_GRADE, array(
            'assignment' => $assignid,
            'grade'      => (int)$grade->id,
        ));
        if (!$row) {
            return true;
        }
        // On considère "non vide" dès qu'on a un statut, même pending/failed,
        // pour pouvoir afficher "génération en cours" côté étudiant.
        return false;
    }

    /**
     * Injecte le rendu du feedback IA dans le formulaire de notation enseignant.
     * Appelée par mod_assign quand l'enseignant ouvre la grille de notation d'un étudiant.
     */
    public function get_form_elements_for_user($grade, MoodleQuickForm $mform, stdClass $data, $userid) {
        global $DB;

        if (!$grade) {
            return false;
        }
        $assignid = (int)$this->assignment->get_instance()->id;
        $fb = $DB->get_record(self::TABLE_GRADE, array(
            'assignment' => $assignid,
            'grade'      => (int)$grade->id,
        ));
        if (!$fb) {
            $mform->addElement('static', 'assignfeedback_ai_view', '',
                html_writer::div(get_string('nofeedback', 'assignfeedback_ai'), 'text-muted'));
            return true;
        }

        if ($fb->status === self::STATUS_PENDING) {
            $mform->addElement('static', 'assignfeedback_ai_view', '',
                html_writer::div(get_string('generationpending', 'assignfeedback_ai'), 'alert alert-info'));
            return true;
        }

        if ($fb->status === self::STATUS_FAILED) {
            $msg = get_string('generationfailed', 'assignfeedback_ai');
            if (!empty($fb->error_message)) {
                $msg .= ' — ' . s($fb->error_message);
            }
            $mform->addElement('static', 'assignfeedback_ai_view', '',
                html_writer::div($msg, 'alert alert-danger'));
            return true;
        }

        $result = json_decode($fb->aifeedback, true);
        if (!is_array($result)) {
            $mform->addElement('static', 'assignfeedback_ai_view', '',
                html_writer::div(get_string('feedbackerror', 'assignfeedback_ai'), 'alert alert-danger'));
            return true;
        }

        $mform->addElement('static', 'assignfeedback_ai_view', '',
            $this->render_card($result, $fb->status));
        return true;
    }

    // =========================================================
    //  API PUBLIQUE
    // =========================================================

    /**
     * Traite une ligne assignfeedback_ai_grade : appelle le LLM et persiste le résultat.
     * Lève une exception en cas d'échec (la tâche ad-hoc l'attrapera et incrémentera attempts).
     *
     * @param int $rowid id de la ligne dans TABLE_GRADE
     * @return array Le feedback normalisé
     * @throws moodle_exception
     */
    public function process_feedback_row($rowid) {
        global $DB;

        $fb = $DB->get_record(self::TABLE_GRADE, array('id' => $rowid), '*', MUST_EXIST);

        $assignid = (int)$this->assignment->get_instance()->id;
        $cfg      = $this->load_config($assignid);
        if (!$cfg) {
            throw new moodle_exception('noconfiguration', 'assignfeedback_ai');
        }

        $extracted = $this->extract_submission((int)$fb->userid, $cfg);
        $messages  = $this->build_messages($extracted, $cfg);
        $result    = $this->call_api($messages, $cfg);

        if ($result === false) {
            throw new moodle_exception('generationerror', 'assignfeedback_ai');
        }

        $result = $this->normalize($result);

        $fb->aifeedback    = json_encode($result, JSON_UNESCAPED_UNICODE);
        $fb->status        = self::STATUS_GENERATED;
        $fb->error_message = null;
        $fb->timemodified  = time();
        $DB->update_record(self::TABLE_GRADE, $fb);

        // Pose automatiquement la note sur assign_grades + propage au gradebook.
        $this->apply_grade_from_result($fb, $result);

        return $result;
    }

    /**
     * Pose la note sur assign_grades en fonction du résultat IA :
     *  - barème (echelle, instance->grade < 0) : matche le niveau au libellé du barème
     *  - points  (instance->grade > 0)         : mappe score 0-100 → 0-maxgrade
     * Puis pousse au gradebook via assign_update_grades().
     */
    private function apply_grade_from_result($fb, $result) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $instance = $this->assignment->get_instance();
        $maxgrade = (int)$instance->grade;
        $gradeval = null;

        if ($maxgrade > 0) {
            $score = isset($result['score']) ? (float)$result['score'] : 0.0;
            $gradeval = round(($score / 100.0) * (float)$maxgrade, 2);
        } else if ($maxgrade < 0) {
            $scaleid = -1 * $maxgrade;
            $scale   = $DB->get_record('scale', array('id' => $scaleid));
            if (!$scale) {
                debugging('assignfeedback_ai: scale ' . $scaleid . ' introuvable', DEBUG_DEVELOPER);
                return;
            }
            $items  = explode(',', $scale->scale);
            $niveau = isset($result['niveau']) ? trim((string)$result['niveau']) : '';
            $key    = strtolower($this->strip_accents($niveau));
            foreach ($items as $i => $item) {
                $candidate = strtolower($this->strip_accents(trim($item)));
                if ($candidate === $key) {
                    $gradeval = $i + 1; // barèmes Moodle : 1-indexé
                    break;
                }
            }
            if ($gradeval === null) {
                debugging('assignfeedback_ai: niveau "' . $niveau
                    . '" non trouvé dans le barème (id=' . $scaleid . ')', DEBUG_DEVELOPER);
                return;
            }
        } else {
            return; // Devoir non noté.
        }

        $assigngrade = $DB->get_record('assign_grades', array('id' => (int)$fb->grade));
        if (!$assigngrade) {
            return;
        }

        $assigngrade->grade        = $gradeval;
        $assigngrade->timemodified = time();
        $DB->update_record('assign_grades', $assigngrade);

        // Pousse la note vers le gradebook.
        assign_update_grades($instance, (int)$assigngrade->userid);
    }

    /**
     * Crée (ou réinitialise) la ligne assignfeedback_ai_grade et enqueue une tâche
     * de génération. Utilisé par l'observer (soumission étudiante) ET par les
     * actions de relance manuelle dans manage.php.
     *
     * @param int $assignid     id du devoir (assign.id)
     * @param int $userid       id de l'étudiant
     * @param int $assigngradeid id du assign_grades (à créer en amont si besoin)
     * @return int              l'id de la ligne TABLE_GRADE
     */
    public static function enqueue_for_grade($assignid, $userid, $assigngradeid) {
        global $DB;

        $now      = time();
        $existing = $DB->get_record(self::TABLE_GRADE, array(
            'assignment' => $assignid,
            'grade'      => $assigngradeid,
        ));

        if ($existing) {
            $existing->status        = self::STATUS_PENDING;
            $existing->attempts      = 0;
            $existing->error_message = null;
            $existing->aifeedback    = null;
            $existing->timemodified  = $now;
            $DB->update_record(self::TABLE_GRADE, $existing);
            $rowid = (int)$existing->id;
        } else {
            $row                = new stdClass();
            $row->assignment    = $assignid;
            $row->grade         = $assigngradeid;
            $row->userid        = $userid;
            $row->aifeedback    = null;
            $row->status        = self::STATUS_PENDING;
            $row->attempts      = 0;
            $row->error_message = null;
            $row->timecreated   = $now;
            $row->timemodified  = $now;
            $rowid              = (int)$DB->insert_record(self::TABLE_GRADE, $row);
        }

        \assignfeedback_ai\job_handler::enqueue($rowid);

        return $rowid;
    }

    // =========================================================
    //  METHODES PRIVEES — CONFIGURATION
    // =========================================================

    private function load_config($assignid) {
        global $DB;
        if ($assignid <= 0) {
            return false;
        }
        return $DB->get_record(self::TABLE_CONFIG, array('assignment' => $assignid));
    }

    // =========================================================
    //  METHODES PRIVEES — EXTRACTION DE TEXTE
    // =========================================================

    /**
     * Extrait le contenu d'une soumission étudiante.
     *
     * @param int      $userid
     * @param stdClass $cfg config du plugin pour ce devoir (pour vision_enabled).
     * @return array {text: string, images: array<{source,data_url}>}
     */
    private function extract_submission($userid, $cfg = null) {
        global $DB;

        // Calcule le budget d'images sur toute la soumission.
        $maximages = $this->vision_image_budget($cfg);
        $images    = array();
        $parts     = array();

        $submission = $this->assignment->get_user_submission($userid, false);
        if (!$submission) {
            return array(
                'text'   => get_string('nosubmissiontext', 'assignfeedback_ai'),
                'images' => array(),
            );
        }

        // Texte en ligne (requête directe car get_onlinetext_submission() est private en 4.x).
        $online_plugin = $this->assignment->get_submission_plugin_by_type('onlinetext');
        if ($online_plugin && $online_plugin->is_enabled()) {
            $online = $DB->get_record('assignsubmission_onlinetext',
                array('submission' => (int)$submission->id));
            if ($online && !empty($online->onlinetext)) {
                $parts[] = strip_tags($online->onlinetext);
            }
            // Images embarquées dans l'éditeur HTML.
            if ($maximages > 0 && count($images) < $maximages) {
                $this->collect_onlinetext_images((int)$submission->id, $images, $maximages);
            }
        }

        // Fichiers joints.
        $fs      = get_file_storage();
        $context = $this->assignment->get_context();
        $files   = $fs->get_area_files(
            $context->id, 'assignsubmission_file', 'submission_files',
            $submission->id, 'filename', false
        );

        foreach ($files as $file) {
            $txt = \local_aifeedback\content_extractor::read_file($file, $images, $maximages);
            if ($txt !== '') {
                $parts[] = '=== ' . $file->get_filename() . " ===\n" . $txt;
            }
        }

        if (empty($parts) && empty($images)) {
            return array(
                'text'   => get_string('nosubmissiontext', 'assignfeedback_ai'),
                'images' => array(),
            );
        }

        return array(
            'text'   => empty($parts) ? get_string('nosubmissiontext', 'assignfeedback_ai')
                                       : implode("\n\n", $parts),
            'images' => $images,
        );
    }

    /**
     * Détermine le nombre max d'images à envoyer pour la soumission.
     * Retourne 0 si vision désactivée (= jamais d'images).
     */
    private function vision_image_budget($cfg) {
        $enabled = (!empty($cfg) && !empty($cfg->vision_enabled_override))
            ? (int)$cfg->vision_enabled
            : (int)get_config('local_aifeedback', 'vision_enabled');

        if (!$enabled) {
            return 0;
        }
        $max = (int)get_config('local_aifeedback', 'maximagespersubmission');
        return ($max > 0) ? $max : 5;
    }

    /**
     * Récupère les images embarquées dans l'éditeur HTML d'une soumission online text
     * et les pousse dans $images (data URL).
     */
    private function collect_onlinetext_images($submissionid, array &$images, $maximages) {
        $fs       = get_file_storage();
        $context  = $this->assignment->get_context();
        $editorfiles = $fs->get_area_files(
            $context->id, 'assignsubmission_onlinetext', 'submissions_onlinetext',
            $submissionid, 'filename', false
        );
        foreach ($editorfiles as $file) {
            if (count($images) >= $maximages) {
                break;
            }
            $url = \local_aifeedback\content_extractor::file_to_data_url($file);
            if ($url !== null) {
                $images[] = array(
                    'source'   => 'Image en ligne : ' . $file->get_filename(),
                    'data_url' => $url,
                );
            }
        }
    }

    // =========================================================
    //  METHODES PRIVEES — PROMPT
    // =========================================================

    /**
     * Construit les messages envoyés à l'API. Accepte soit une chaîne (legacy),
     * soit le tableau {text, images} retourné par extract_submission().
     */
    private function build_messages($submission, $cfg) {
        $system = (!empty($cfg->systemprompt))
            ? $cfg->systemprompt
            : self::default_system_prompt();
        // Consigne d'accessibilité (tolérance orthographique) selon le réglage global.
        $system .= \local_aifeedback\prompt::accessibility_suffix();

        // Normalise l'entrée.
        if (is_array($submission)) {
            $text   = isset($submission['text'])   ? (string)$submission['text']   : '';
            $images = isset($submission['images']) ? (array)$submission['images'] : array();
        } else {
            $text   = (string)$submission;
            $images = array();
        }

        $parts = array();
        if (!empty($cfg->exercise)) {
            $parts[] = "EXERCICE :\n" . $cfg->exercise;
        }
        if (!empty($cfg->competencies)) {
            $parts[] = "COMPETENCES EVALUEES :\n" . $cfg->competencies;
        }
        if (!empty($cfg->expectedanswer)) {
            $parts[] = "ATTENDUS / CORRIGE :\n" . $cfg->expectedanswer;
        }
        $parts[] = "REPONSE ETUDIANT :\n" . $text;
        if (!empty($images)) {
            $parts[] = "Les images jointes ci-dessous font partie de la réponse étudiant. "
                    . "Tu dois les analyser pour évaluer.";
        }
        $textcontent = implode("\n\n", $parts);

        // Mode texte simple — content reste une string pour rester rétrocompatible.
        if (empty($images)) {
            return array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user',   'content' => $textcontent),
            );
        }

        // Mode multimodal — content devient un tableau de blocs (format OpenAI).
        $usercontent = array(
            array('type' => 'text', 'text' => $textcontent),
        );
        foreach ($images as $img) {
            $src = isset($img['source'])   ? (string)$img['source']   : '';
            $url = isset($img['data_url']) ? (string)$img['data_url'] : '';
            if ($url === '') {
                continue;
            }
            if ($src !== '') {
                $usercontent[] = array('type' => 'text', 'text' => 'Image : ' . $src);
            }
            $usercontent[] = array(
                'type'      => 'image_url',
                'image_url' => array('url' => $url),
            );
        }

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user',   'content' => $usercontent),
        );
    }

    /**
     * Prompt système par défaut — spécialisé BTS.
     * Méthode statique publique pour pouvoir être appelée depuis settings.php.
     */
    public static function default_system_prompt() {
        $p  = "Tu es un correcteur pédagogique spécialisé dans l'enseignement supérieur technologique français";
        $p .= " (BTS Informatique / BTS CIEL).\n";
        $p .= "Ton rôle est d'évaluer objectivement les réponses d'étudiants à partir :\n";
        $p .= "- d'un exercice,\n- des compétences visées,\n- des attentes pédagogiques,\n";
        $p .= "- d'un corrigé ou d'éléments de référence.\n\n";
        $p .= "Tu dois :\n";
        $p .= "1. analyser la réponse de l'étudiant,\n";
        $p .= "2. identifier les éléments corrects,\n";
        $p .= "3. identifier les erreurs, oublis ou imprécisions,\n";
        $p .= "4. produire un retour pédagogique constructif,\n";
        $p .= "5. déterminer un niveau de maîtrise.\n\n";
        $p .= "Les niveaux possibles sont STRICTEMENT :\n";
        $p .= "- \"Maîtrise insuffisante\"\n- \"Maîtrise fragile\"\n";
        $p .= "- \"Maîtrise satisfaisante\"\n- \"Très bonne maîtrise\"\n\n";
        $p .= "Règles importantes :\n";
        $p .= "- Rester factuel et pédagogique.\n";
        $p .= "- Ne jamais humilier l'étudiant.\n";
        $p .= "- Expliquer précisément ce qui est correct et incorrect.\n";
        $p .= "- Valoriser les éléments réussis même si la réponse est incomplète.\n";
        $p .= "- Ne jamais inventer des connaissances absentes du corrigé ou du sujet.\n";
        $p .= "- Privilégier la cohérence pédagogique.\n";
        $p .= "- Tenir compte du niveau attendu en BTS.\n";
        $p .= "- Une réponse partiellement correcte n'est pas totalement fausse.\n";
        $p .= "- Les fautes mineures de français ne pénalisent pas si les concepts techniques sont corrects.\n";
        $p .= "- Distinguer : erreur de compréhension, oubli, imprécision, confusion technique.\n\n";
        $p .= "Critères :\n";
        $p .= "- Très bonne maîtrise (80-100) : réponse complète, concepts corrects, vocabulaire maîtrisé.\n";
        $p .= "- Maîtrise satisfaisante (50-79) : notions principales comprises, quelques imprécisions.\n";
        $p .= "- Maîtrise fragile (25-49) : compréhension partielle, plusieurs oublis, erreurs techniques.\n";
        $p .= "- Maîtrise insuffisante (0-24) : hors sujet, erreurs majeures, concepts non compris.\n\n";
        $p .= "La structure de ta réponse JSON est imposée par le schéma fourni dans la requête.";
        return $p;
    }

    // =========================================================
    //  HELPERS — CHIFFREMENT DES SECRETS (API keys)
    // =========================================================
    // Depuis Phase 3.A, les vraies implémentations vivent dans
    // \local_aifeedback\secret (bibliothèque partagée). On garde ici des
    // wrappers pour le code interne déjà écrit qui les appelle.

    public static function encrypt_secret($plain) {
        return \local_aifeedback\secret::encrypt($plain);
    }

    public static function decrypt_secret($value) {
        return \local_aifeedback\secret::decrypt($value);
    }

    /**
     * Schéma JSON imposé au LLM via response_format.json_schema (mode strict).
     * Force la structure et les libellés exacts attendus en sortie.
     */
    public static function response_schema() {
        $levels = array(
            'Maîtrise insuffisante',
            'Maîtrise fragile',
            'Maîtrise satisfaisante',
            'Très bonne maîtrise',
        );

        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'niveau' => array(
                    'type' => 'string',
                    'enum' => $levels,
                ),
                'score' => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                ),
                'points_forts' => array(
                    'type'  => 'array',
                    'items' => array('type' => 'string'),
                ),
                'points_a_ameliorer' => array(
                    'type'  => 'array',
                    'items' => array('type' => 'string'),
                ),
                'feedback' => array(
                    'type' => 'string',
                ),
                'competences_evaluees' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => array(
                            'competence'  => array('type' => 'string'),
                            'niveau'      => array(
                                'type' => 'string',
                                'enum' => $levels,
                            ),
                            'commentaire' => array('type' => 'string'),
                        ),
                        'required' => array('competence', 'niveau', 'commentaire'),
                    ),
                ),
            ),
            'required' => array(
                'niveau', 'score', 'points_forts',
                'points_a_ameliorer', 'feedback', 'competences_evaluees',
            ),
        );
    }

    // =========================================================
    //  METHODES PRIVEES — APPEL API
    // =========================================================

    private function call_api($messages, $cfg) {
        // Construction des overrides pour la lib partagée.
        $options = array(
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array(
                    'name'   => 'assignfeedback_ai_response',
                    'strict' => true,
                    'schema' => self::response_schema(),
                ),
            ),
            'extra_body' => array('enable_thinking' => false),
            'temperature' => 0.2,
            'max_tokens'  => 2048,
        );
        if (!empty($cfg->apiurl_override) && !empty($cfg->apiurl)) {
            $options['apiurl'] = $cfg->apiurl;
        }
        if (!empty($cfg->model_override) && !empty($cfg->model)) {
            $options['model'] = $cfg->model;
        }
        if (!empty($cfg->apikey_override) && !empty($cfg->apikey)) {
            $options['apikey'] = self::decrypt_secret($cfg->apikey);
        }

        try {
            return \local_aifeedback\api::call($messages, $options);
        } catch (\Throwable $e) {
            debugging('assignfeedback_ai: API call failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    // =========================================================
    //  METHODES PRIVEES — NORMALISATION DU JSON
    // =========================================================

    private function normalize($result) {
        if (!isset($result['score'])) {
            $result['score'] = 0;
        }
        $result['score'] = max(0, min(100, (int)$result['score']));

        if (empty($result['niveau'])) {
            $result['niveau'] = $this->score_to_niveau($result['score']);
        }

        if (!isset($result['points_forts']) || !is_array($result['points_forts'])) {
            $result['points_forts'] = array();
        }
        if (!isset($result['points_a_ameliorer']) || !is_array($result['points_a_ameliorer'])) {
            $result['points_a_ameliorer'] = array();
        }
        if (!isset($result['feedback'])) {
            $result['feedback'] = '';
        }
        if (!isset($result['competences_evaluees']) || !is_array($result['competences_evaluees'])) {
            $result['competences_evaluees'] = array();
        }

        return $result;
    }

    private function score_to_niveau($score) {
        if ($score >= 80) { return 'Tres bonne maitrise'; }
        if ($score >= 50) { return 'Maitrise satisfaisante'; }
        if ($score >= 25) { return 'Maitrise fragile'; }
        return 'Maitrise insuffisante';
    }

    // =========================================================
    //  METHODES PRIVEES — RENDU HTML
    // =========================================================

    private function niveau_to_badge($niveau) {
        $map = array(
            'tres bonne maitrise'    => 'success',
            'maitrise satisfaisante' => 'info',
            'maitrise fragile'       => 'warning',
            'maitrise insuffisante'  => 'danger',
        );
        $key = strtolower($this->strip_accents($niveau));
        return isset($map[$key]) ? $map[$key] : 'secondary';
    }

    private function strip_accents($str) {
        $from = array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç',
                      'É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç');
        $to   = array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c',
                      'E','E','E','E','A','A','A','I','I','O','O','U','U','U','C');
        return str_replace($from, $to, $str);
    }

    private function render_card($result, $status) {
        $niveau      = isset($result['niveau'])               ? (string)$result['niveau']               : '—';
        $score       = isset($result['score'])                ? (int)$result['score']                    : 0;
        $forts       = isset($result['points_forts'])         ? $result['points_forts']                  : array();
        $ameliorer   = isset($result['points_a_ameliorer'])   ? $result['points_a_ameliorer']            : array();
        $feedback    = isset($result['feedback'])             ? (string)$result['feedback']              : '';
        $competences = isset($result['competences_evaluees']) ? $result['competences_evaluees']          : array();

        $badge = $this->niveau_to_badge($niveau);

        $bar_colors = array(
            'success'   => '#28a745',
            'info'      => '#17a2b8',
            'warning'   => '#ffc107',
            'danger'    => '#dc3545',
            'secondary' => '#6c757d',
        );
        $bar_color = isset($bar_colors[$badge]) ? $bar_colors[$badge] : '#6c757d';

        $progressbar = html_writer::div(
            html_writer::div('', 'progress-bar', array(
                'role'          => 'progressbar',
                'style'         => 'width:' . $score . '%;background-color:' . $bar_color,
                'aria-valuenow' => $score,
                'aria-valuemin' => '0',
                'aria-valuemax' => '100',
            )),
            'progress', array('style' => 'height:8px;margin:0;border-radius:0')
        );

        $html  = html_writer::start_div('card mb-3');

        // En-tête
        $html .= html_writer::start_div('card-header d-flex justify-content-between align-items-center py-2');
        $html .= html_writer::tag('strong',
            get_string('pluginname', 'assignfeedback_ai'));
        $html .= html_writer::div(
            html_writer::tag('span', $score . '/100', array('class' => 'h5 mb-0 mr-2')) .
            html_writer::tag('span', s($niveau), array('class' => 'badge badge-' . $badge)),
            'd-flex align-items-center'
        );
        $html .= html_writer::end_div();

        $html .= $progressbar;

        $html .= html_writer::start_div('card-body');

        // Points forts
        if (!empty($forts)) {
            $html .= html_writer::tag('h6', get_string('strengths', 'assignfeedback_ai'));
            $li = '';
            foreach ($forts as $item) {
                $li .= html_writer::tag('li', s((string)$item));
            }
            $html .= html_writer::tag('ul', $li, array('class' => 'mb-3'));
        }

        // Points à améliorer
        if (!empty($ameliorer)) {
            $html .= html_writer::tag('h6', get_string('improvements', 'assignfeedback_ai'));
            $li = '';
            foreach ($ameliorer as $item) {
                $li .= html_writer::tag('li', s((string)$item));
            }
            $html .= html_writer::tag('ul', $li, array('class' => 'mb-3'));
        }

        // Feedback détaillé
        if ($feedback !== '') {
            $html .= html_writer::tag('h6', get_string('detailedfeedback', 'assignfeedback_ai'));
            $html .= html_writer::div(nl2br(s($feedback)), 'alert alert-light mb-3');
        }

        // Tableau des compétences
        if (!empty($competences)) {
            $html .= html_writer::tag('h6', get_string('competency_scores', 'assignfeedback_ai'));

            $rows = '';
            foreach ($competences as $comp) {
                if (!is_array($comp)) {
                    continue;
                }
                $c_nom     = isset($comp['competence'])  ? s((string)$comp['competence'])  : '—';
                $c_niveau  = isset($comp['niveau'])      ? s((string)$comp['niveau'])      : '—';
                $c_comment = isset($comp['commentaire']) ? s((string)$comp['commentaire']) : '';
                $c_badge   = isset($comp['niveau'])
                             ? $this->niveau_to_badge((string)$comp['niveau'])
                             : 'secondary';

                $rows .= html_writer::tag('tr',
                    html_writer::tag('td', html_writer::tag('strong', $c_nom)) .
                    html_writer::tag('td',
                        html_writer::tag('span', $c_niveau, array('class' => 'badge badge-' . $c_badge))
                    ) .
                    html_writer::tag('td', $c_comment, array('class' => 'text-muted small'))
                );
            }

            if ($rows !== '') {
                $head = html_writer::tag('thead',
                    html_writer::tag('tr',
                        html_writer::tag('th', get_string('competency', 'assignfeedback_ai')) .
                        html_writer::tag('th', get_string('mastery_level', 'assignfeedback_ai')) .
                        html_writer::tag('th', get_string('commentary', 'assignfeedback_ai'))
                    )
                );
                $html .= html_writer::tag('table',
                    $head . html_writer::tag('tbody', $rows),
                    array('class' => 'table table-sm table-bordered')
                );
            }
        }

        $html .= html_writer::end_div(); // card-body
        $html .= html_writer::end_div(); // card

        return $html;
    }
}
