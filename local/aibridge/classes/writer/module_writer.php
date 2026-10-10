<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Création d'activités par le pont, par create_module() du cœur : le même
 * chemin que le formulaire d'édition (modedit.php), avec les rappels
 * coursemodule_edit_post_actions des plugins (Tuteur IA, EFE, heures de
 * cours). Les réglages reçus sont ceux du formulaire de l'activité ; ils sont
 * fusionnés avec des valeurs techniques par défaut, qui rendent la création
 * possible sans dire quoi que ce soit de pédagogique.
 *
 * Toute activité est créée MASQUÉE : visible = 0 est imposé, quoi que dise
 * l'appelant.
 */
class module_writer {

    /** Types d'activité que le pont sait créer. */
    const MODULES = array('page', 'label', 'url', 'lesson', 'quiz', 'assign');

    /** Champs que l'appelant ne peut pas fixer : le pont les calcule ou les impose. */
    const PROTECTED_FIELDS = array('id', 'course', 'section', 'module', 'modulename', 'coursemodule', 'instance',
        'visible', 'visibleold', 'visibleoncoursepage', 'add', 'update', 'return', 'sr', 'introeditor',
        'availability', 'availabilityconditionsjson', 'beforemod', 'lang', 'mediafile');

    /** Champs de date acceptés sous la forme « AAAA-MM-JJTHH:MM » (heure du serveur). */
    const DATE_FIELDS = array('duedate', 'cutoffdate', 'allowsubmissionsfromdate', 'gradingduedate', 'timeopen',
        'timeclose', 'available', 'deadline', 'completionexpected');

    /**
     * Crée une activité masquée.
     *
     * @param \stdClass $course   cours (écriture déjà autorisée par guard)
     * @param int       $section  numéro de section
     * @param string    $modname  page, label, url, lesson, quiz, assign
     * @param string    $name     nom affiché
     * @param string    $intro    description (HTML)
     * @param array     $settings champs du formulaire de l'activité
     * @param bool      $dryrun   ne rien créer : renvoyer les champs qui seraient transmis
     * @return array
     */
    public static function create(\stdClass $course, int $section, string $modname, string $name, string $intro,
            array $settings, bool $dryrun): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        if (!in_array($modname, self::MODULES, true)) {
            throw new \moodle_exception('error_modname', 'local_aibridge', '', $modname);
        }
        if (!$DB->record_exists('modules', array('name' => $modname, 'visible' => 1))) {
            throw new \moodle_exception('error_modname', 'local_aibridge', '', $modname);
        }
        $warnings = array();
        $settings = self::clean_settings($settings, $warnings);
        self::check_efe($settings, $warnings);

        $info = (object)array_merge(self::defaults($modname), self::convert($modname, $course, $settings));
        $info->modulename = $modname;
        $info->course = (int)$course->id;
        $info->section = $section;
        $info->name = trim($name);
        $info->visible = 0;
        $info->visibleoncoursepage = 1;
        if (plugin_supports('mod', $modname, FEATURE_MOD_INTRO, true)) {
            $info->introeditor = array('text' => $intro, 'format' => FORMAT_HTML,
                'itemid' => $dryrun ? 0 : file_get_unused_draft_itemid());
        }
        if ($modname === 'lesson') {
            // Zone de brouillon vide pour le fichier multimédia de la leçon.
            $info->mediafile = $dryrun ? 0 : file_get_unused_draft_itemid();
        }
        if (empty($course->enablecompletion) && !empty($info->completion)) {
            $warnings[] = 'Achèvement désactivé dans le cours : les conditions d\'achèvement sont ignorées.';
        }

        if ($dryrun) {
            $exists = (bool)get_fast_modinfo($course)->get_section_info($section);
            return array('dryrun' => true, 'sectionexists' => $exists, 'moduleinfo' => (array)$info,
                'warnings' => $warnings);
        }

        $result = create_module($info);
        $cmid = (int)$result->coursemodule;
        created::add(created::CM, $cmid, (int)$course->id);

        // Configuration écrite après coup, faute de rappel utilisable à la création.
        if ($modname === 'assign' && !empty($settings['assignfeedback_ai_enabled'])) {
            self::save_ai_feedback($cmid, $settings, $warnings);
        }
        self::schedule_tutor_brief($cmid, $warnings);

        // Le masquage est la règle du pont : on le vérifie, on ne le suppose pas.
        $visible = (int)$DB->get_field('course_modules', 'visible', array('id' => $cmid));
        if ($visible !== 0) {
            \core_courseformat\formatactions::cm($course->id)->set_visibility($cmid, 0);
            $warnings[] = 'Activité remasquée après création.';
        }

        return array(
            'cmid'     => $cmid,
            'instance' => (int)$result->instance,
            'modname'  => $modname,
            'name'     => $info->name,
            'section'  => $section,
            'visible'  => 0,
            'url'      => (new \moodle_url('/mod/' . $modname . '/view.php', array('id' => $cmid)))->out(false),
            'warnings' => $warnings,
        );
    }

    /**
     * Valeurs techniques par défaut, pour que la création aboutisse. Les
     * réglages pédagogiques (tentatives, relecture, remise...) viennent de
     * l'appelant ; ces défauts sont prudents (rien n'est montré avant la
     * fermeture d'un test, aucun plugin de remise n'est activé).
     */
    private static function defaults(string $modname): array {
        $common = array('completion' => 0, 'completionview' => 0, 'completionexpected' => 0, 'cmidnumber' => '',
            'groupmode' => 0, 'groupingid' => 0, 'showdescription' => 0);
        switch ($modname) {
            case 'page':
                return $common + array('content' => '', 'contentformat' => FORMAT_HTML,
                    'display' => RESOURCELIB_DISPLAY_AUTO, 'printintro' => 0, 'printlastmodified' => 0);
            case 'label':
                return $common;
            case 'url':
                return $common + array('externalurl' => '', 'display' => RESOURCELIB_DISPLAY_AUTO, 'printintro' => 0);
            case 'lesson':
                $config = get_config('mod_lesson');
                return $common + array(
                    'practice' => 0, 'modattempts' => 0, 'usepassword' => 0, 'password' => '', 'dependency' => 0,
                    'timespent' => 0, 'completed' => 0, 'gradebetterthan' => 0, 'custom' => 1, 'ongoing' => 0,
                    'usemaxgrade' => 0, 'maxanswers' => (int)($config->maxanswers ?? 5), 'maxattempts' => 1,
                    'review' => 0, 'nextpagedefault' => 0, 'feedback' => 0, 'minquestions' => 0, 'maxpages' => 0,
                    'timelimit' => 0, 'retake' => 0, 'activitylink' => 0, 'mediaheight' => 480,
                    'mediawidth' => 640, 'mediaclose' => 0, 'slideshow' => 0, 'width' => 640, 'height' => 480,
                    'bgcolor' => '#FFFFFF', 'displayleft' => 0, 'displayleftif' => 0, 'progressbar' => 0,
                    'available' => 0, 'deadline' => 0, 'allowofflineattempts' => 0, 'grade' => 0,
                );
            case 'quiz':
                $review = array();
                foreach (array('attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
                        'rightanswer', 'overallfeedback') as $field) {
                    $review[$field . 'during'] = 0;
                    $review[$field . 'immediately'] = 0;
                    $review[$field . 'open'] = 0;
                    $review[$field . 'closed'] = 1;
                }
                return $common + $review + array(
                    'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'overduehandling' => 'autosubmit',
                    'graceperiod' => 0, 'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0,
                    'attempts' => 1, 'attemptonlast' => 0, 'grademethod' => 1, 'decimalpoints' => 2,
                    'questiondecimalpoints' => -1, 'questionsperpage' => 1, 'navmethod' => 'free',
                    'shuffleanswers' => 1, 'sumgrades' => 0, 'grade' => 10, 'quizpassword' => '', 'subnet' => '',
                    'browsersecurity' => '-', 'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0,
                    'showblocks' => 0, 'completionattemptsexhausted' => 0, 'completionminattempts' => 0,
                    'allowofflineattempts' => 0,
                );
            case 'assign':
                return $common + array(
                    'alwaysshowdescription' => 0, 'submissiondrafts' => 1, 'requiresubmissionstatement' => 0,
                    'sendnotifications' => 0, 'sendlatenotifications' => 0, 'sendstudentnotifications' => 1,
                    'duedate' => 0, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0, 'gradingduedate' => 0,
                    'grade' => 100, 'teamsubmission' => 0, 'requireallteammemberssubmit' => 0,
                    'teamsubmissiongroupingid' => 0, 'blindmarking' => 0, 'hidegrader' => 0,
                    'attemptreopenmethod' => 'untilpass', 'maxattempts' => 1, 'markingworkflow' => 0,
                    'markingallocation' => 0, 'markinganonymous' => 0, 'preventsubmissionnotingroup' => 0,
                    'timelimit' => 0, 'submissionattachments' => 0, 'completionsubmit' => 0,
                );
        }
        return $common;
    }

    /**
     * Raccourcis acceptés dans les réglages, convertis au format du formulaire :
     *   - dates « AAAA-MM-JJTHH:MM » (heure du serveur) → horodatage ;
     *   - « activity » (devoir) → éditeur des instructions ;
     *   - « grade » : {"scale": "Nom du barème"} → barème du site ou du cours.
     */
    private static function convert(string $modname, \stdClass $course, array $settings): array {
        foreach (self::DATE_FIELDS as $field) {
            if (isset($settings[$field]) && is_string($settings[$field]) && !is_numeric($settings[$field])) {
                $settings[$field] = self::timestamp($settings[$field], $field);
            }
        }
        if ($modname === 'assign' && isset($settings['activity']) && is_string($settings['activity'])) {
            $settings['activityeditor'] = array('text' => $settings['activity'], 'format' => FORMAT_HTML);
            unset($settings['activity']);
        }
        if (isset($settings['grade']) && is_array($settings['grade'])) {
            $settings['grade'] = -self::scale_id((int)$course->id, (string)($settings['grade']['scale'] ?? ''));
        }
        return $settings;
    }

    /** Date « AAAA-MM-JJTHH:MM » dans le fuseau du serveur → horodatage. */
    private static function timestamp(string $value, string $field): int {
        $date = \DateTime::createFromFormat('Y-m-d\TH:i', trim($value), \core_date::get_server_timezone_object());
        if ($date === false) {
            throw new \moodle_exception('error_date', 'local_aibridge', '', $field . ' = ' . $value);
        }
        return $date->getTimestamp();
    }

    /** Barème du cours ou du site, par son nom. */
    private static function scale_id(int $courseid, string $name): int {
        global $DB;
        $scales = $DB->get_records_select('scale', '(courseid = 0 OR courseid = :courseid) AND name = :name',
            array('courseid' => $courseid, 'name' => trim($name)), 'courseid DESC', 'id', 0, 1);
        if (!$scales) {
            throw new \moodle_exception('error_scale', 'local_aibridge', '', $name);
        }
        return (int)reset($scales)->id;
    }

    /**
     * Retire les champs réservés et les valeurs qui ne sont pas des réglages
     * (objets imbriqués au-delà des tableaux du formulaire).
     *
     * @param array    $settings
     * @param string[] $warnings
     */
    private static function clean_settings(array $settings, array &$warnings): array {
        foreach (self::PROTECTED_FIELDS as $field) {
            if (array_key_exists($field, $settings)) {
                unset($settings[$field]);
                $warnings[] = 'Champ « ' . $field . ' » ignoré : il est fixé par le pont.';
            }
        }
        // Aucun secret ne passe par le pont (clé d'API, mot de passe de test...) : l'enseignant
        // les règle lui-même dans Moodle.
        foreach (array_keys($settings) as $field) {
            if (preg_match('/apikey|password|secret|token/i', (string)$field) && !preg_match('/_override$/', (string)$field)) {
                unset($settings[$field]);
                $warnings[] = 'Champ « ' . $field . ' » ignoré : les secrets se règlent dans Moodle, pas par le pont.';
            }
        }
        return $settings;
    }

    /**
     * Le formulaire d'EFE refuse un report sans compétence ; créée par
     * programme, l'activité n'y passe pas : le pont le vérifie lui-même.
     * Les codes N1 seuls (« C04 ») sont refusés : N2 au minimum.
     *
     * @param array    $settings
     * @param string[] $warnings
     */
    private static function check_efe(array $settings, array &$warnings): void {
        if (empty($settings['efenotes_enabled'])) {
            return;
        }
        $codes = array_merge((array)($settings['efenotes_competence'] ?? array()),
            !empty($settings['efenotes_punct_enabled']) ? (array)($settings['efenotes_punct_competence'] ?? array()) : array());
        if (!array_filter((array)($settings['efenotes_competence'] ?? array()), 'strlen')) {
            throw new \moodle_exception('error_efenocompetence', 'local_aibridge');
        }
        foreach ($codes as $code) {
            if (!preg_match('/^C\d{2}\.\d+(\.[A-Z0-9.]+)?$/', trim((string)$code))) {
                throw new \moodle_exception('error_efecode', 'local_aibridge', '', $code);
            }
        }
        if (empty($settings['efenotes_prof_moodleid'])) {
            $warnings[] = 'EFE sans enseignant (efenotes_prof_moodleid) : les reports partiront sans enseignant désigné.';
        }
    }

    /**
     * Correction IA : à la création, mod_assign appelle le plugin avant que le
     * devoir ait un identifiant, et l'observateur du plugin ne lit que le
     * formulaire web. La configuration est donc écrite ici, par le plugin
     * lui-même.
     *
     * @param int      $cmid
     * @param array    $settings champs assignfeedback_ai_*
     * @param string[] $warnings
     */
    private static function save_ai_feedback(int $cmid, array $settings, array &$warnings): void {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/mod/assign/feedback/ai/locallib.php')) {
            $warnings[] = 'Correction IA demandée, mais assignfeedback_ai n\'est pas installé.';
            return;
        }
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
        $assign = new \assign(\context_module::instance($cmid), $cm, $course);
        $plugin = $assign->get_feedback_plugin_by_type('ai');
        if (!$plugin) {
            $warnings[] = 'Correction IA introuvable pour ce devoir.';
            return;
        }
        if (!$plugin->is_enabled()) {
            $plugin->enable();
        }
        $formdata = (object)$settings;
        $formdata->instance = (int)$cm->instance;
        $plugin->save_settings($formdata);
    }

    /**
     * Brief du Tuteur IA : l'observateur du tuteur passe avant que la
     * Correction IA soit configurée ; on relance la mise en file une fois tout
     * enregistré (sans effet si le tuteur n'est pas activé).
     *
     * @param int      $cmid
     * @param string[] $warnings
     */
    private static function schedule_tutor_brief(int $cmid, array &$warnings): void {
        if (!class_exists('\local_aichat\brief')) {
            return;
        }
        try {
            \local_aichat\brief::schedule_if_stale($cmid);
        } catch (\Throwable $e) {
            $warnings[] = 'Brief du Tuteur IA non mis en file : ' . $e->getMessage();
        }
    }
}
