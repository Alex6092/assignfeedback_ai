<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Création du devoir d'une mission : génération, copie d'un sprint et
 * adaptation par l'IA passent toutes par ici.
 *
 * INSERT direct (comme local_aiquizgen pour les quiz) : on ne passe pas par
 * assign_add_instance(), qui attend une structure de formulaire complète.
 * Conséquence : AUCUN événement ni rappel de plugin (course_module_created,
 * coursemodule_edit_post_actions). La Correction IA, EFE et le Tuteur IA sont
 * donc configurés ici, explicitement, dans cet ordre : le brief du tuteur
 * dérive du corrigé de la correction IA.
 *
 * Réglages du devoir (ceux du lycée) :
 *   - la demande client est dans « Instructions de l'activité » (activity), la
 *     description restant réservée au bloc de compétence d'EFE, affiché sur la
 *     page de cours ;
 *   - barème « Barème officiel » (réglage scalename), sinon note sur 100 ;
 *   - bouton « Envoyer » obligatoire, 2 tentatives accordées automatiquement ;
 *   - achèvement : remettre un travail (si l'achèvement est activé dans le cours).
 */
class assign_factory {

    /** Niveaux renvoyés par la Correction IA : un barème doit porter ces libellés. */
    const LEVELS = array('Maîtrise insuffisante', 'Maîtrise fragile',
        'Maîtrise satisfaisante', 'Très bonne maîtrise');

    /** Barème par défaut, si le réglage n'a jamais été enregistré. */
    const DEFAULT_SCALE = 'Barème officiel';

    /** Tentatives permises. */
    const MAX_ATTEMPTS = 2;

    /**
     * « Accorder des tentatives : automatiquement » — ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC
     * de mod/assign/locallib.php (la valeur 'none' n'existe plus en Moodle 5.2).
     */
    const REOPEN_METHOD = 'automatic';

    /**
     * Crée le devoir caché d'une mission, puis configure la Correction IA, EFE et
     * le Tuteur IA.
     *
     * @param int           $courseid
     * @param \stdClass     $project projet du groupe (groupid, companyname, personaprofile)
     * @param array         $spec    title, clientrequest (HTML), rubric, competencies[]
     * @param array         $options efe_codes (string[]), profid (int), aichat (bool),
     *                               aichatsearch (int 0|1|2)
     * @param callable|null $log     function(string $line) : journal du job
     * @return \stdClass course module (->id, ->instance = assign.id)
     */
    public static function create(int $courseid, \stdClass $project, array $spec,
            array $options = array(), ?callable $log = null): \stdClass {
        $log = $log ?: function($line) {
        };

        $cm = self::create_module($courseid, $project, $spec, $log);

        self::prefill_feedback_config((int)$cm->instance, $project, $spec);
        $log('Correction IA pré-configurée sur le devoir.');

        // Après la création du devoir, plus rien ne doit faire échouer le job :
        // un nouvel essai créerait un second devoir.
        $codes = self::clean_codes($options['efe_codes'] ?? array());
        if (!empty($codes)) {
            try {
                $mode = efe_bridge::attach_competencies((int)$cm->id, $courseid, $codes,
                    (int)($options['profid'] ?? 0));
                if ($mode === 'multi') {
                    $log('Compétence(s) EFE rattachée(s) : ' . implode(', ', $codes)
                        . ' (report automatique à la correction).');
                } else if ($mode === 'single') {
                    $log('EFE ne gère pas plusieurs compétences (version ancienne) : seule '
                        . $codes[0] . ' est rattachée.');
                } else {
                    $log('local_efenotes absent : report de compétence ignoré.');
                }
            } catch (\Throwable $e) {
                $log('Compétences EFE non rattachées : ' . $e->getMessage());
            }
        }

        if (!empty($options['aichat'])) {
            try {
                if (aichat_bridge::configure((int)$cm->id, $courseid, (int)($options['aichatsearch'] ?? 0))) {
                    $log('Tuteur IA activé sur le devoir (brief pédagogique mis en file).');
                } else {
                    $log('Tuteur IA non installé : option ignorée.');
                }
            } catch (\Throwable $e) {
                $log('Tuteur IA non activé : ' . $e->getMessage());
            }
        }

        return $cm;
    }

    /**
     * Crée le module « assign » caché, restreint au groupe du projet, avec
     * soumission texte + fichier et la correction IA activée.
     */
    private static function create_module(int $courseid, \stdClass $project, array $spec,
            callable $log): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $course   = get_course($courseid);
        $moduleid = (int)$DB->get_field('modules', 'id', array('name' => 'assign'));
        $now      = time();
        $groupid  = (int)$project->groupid;

        // Restriction d'accès au groupe (caché aux autres) si un groupe est défini.
        $availability = null;
        if ($groupid > 0) {
            $availability = json_encode(array(
                'op'    => '&',
                'c'     => array(array('type' => 'group', 'id' => $groupid)),
                'showc' => array(false), // masqué aux non-membres
            ));
        }

        // Achèvement « Remettre un travail » : Moodle l'ignore si l'achèvement
        // n'est pas activé dans le cours (et sur le site).
        $completionon = (bool)(new \completion_info($course))->is_enabled();
        if (!$completionon) {
            $log('Achèvement désactivé dans le cours : condition « Remettre un travail » non posée.');
        }

        $warning = null;
        $scaleid = self::resolve_scale($courseid, $warning);
        if ($warning !== null) {
            $log($warning);
        }

        // --- 1. {course_modules} (sans instance encore) -----------------
        $newcm = new \stdClass();
        $newcm->course                    = $courseid;
        $newcm->module                    = $moduleid;
        $newcm->instance                  = 0;
        $newcm->visible                   = 0; // caché : l'enseignant relit avant
        $newcm->visibleoncoursepage       = 1;
        $newcm->groupmode                 = 0;
        $newcm->groupingid                = 0;
        $newcm->completion                = $completionon ? COMPLETION_TRACKING_AUTOMATIC : COMPLETION_TRACKING_NONE;
        $newcm->completiongradeitemnumber = null;
        $newcm->completionpassgrade       = 0;
        $newcm->completionview            = 0;
        $newcm->completionexpected        = 0;
        $newcm->availability              = $availability;
        $newcm->showdescription           = 0; // EFE l'active s'il pose son bloc
        $cmid = (int)add_course_module($newcm);
        if (!$cmid) {
            throw new \moodle_exception('error_assign_creation', 'local_aimissions');
        }
        // Force la création du contexte module (sinon « Invalid context id »).
        \context_module::instance($cmid);

        // --- 2. INSERT direct dans {assign} -----------------------------
        $assign = new \stdClass();
        $assign->course                      = $courseid;
        $assign->name                        = self::truncate((string)$spec['title'], 250);
        // Description vide : EFE y dépose son bloc « Compétence évaluée », affiché
        // sur la page de cours. La demande client va dans les instructions.
        $assign->intro                       = '';
        $assign->introformat                 = FORMAT_HTML;
        $assign->activity                    = self::ensure_html((string)$spec['clientrequest']);
        $assign->activityformat              = FORMAT_HTML;
        $assign->alwaysshowdescription       = 1;
        $assign->nosubmissions               = 0;
        $assign->submissiondrafts            = 1; // « Exiger que les étudiants cliquent sur Envoyer »
        $assign->sendnotifications           = 0;
        $assign->sendlatenotifications       = 0;
        $assign->duedate                     = 0;
        $assign->allowsubmissionsfromdate    = 0;
        // Barème : la correction IA fait correspondre le niveau au libellé de
        // l'échelle ; sur 100 : elle convertit le score.
        $assign->grade                       = ($scaleid > 0) ? -$scaleid : 100;
        $assign->timemodified                = $now;
        $assign->requiresubmissionstatement  = 0;
        $assign->completionsubmit            = 1;
        $assign->cutoffdate                  = 0;
        $assign->gradingduedate              = 0;
        $assign->teamsubmission              = 0; // soumission individuelle (restreinte au groupe)
        $assign->requireallteammemberssubmit = 0;
        $assign->teamsubmissiongroupingid    = 0;
        $assign->blindmarking                = 0;
        $assign->hidegrader                  = 0;
        $assign->revealidentities            = 0;
        $assign->attemptreopenmethod         = self::REOPEN_METHOD;
        $assign->maxattempts                 = self::MAX_ATTEMPTS;
        $assign->markingworkflow             = 0;
        $assign->markingallocation           = 0;
        $assign->markercount                 = 1;
        $assign->markinganonymous            = 0;
        $assign->sendstudentnotifications    = 1;
        $assign->preventsubmissionnotingroup = 0;
        $assign->timelimit                   = 0;
        $assign->submissionattachments       = 0;
        $assign->gradepenalty                = 0;

        try {
            $assignid = (int)$DB->insert_record('assign', $assign);
        } catch (\Throwable $e) {
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
            $DB->delete_records('course_modules', array('id' => $cmid));
            throw $e;
        }
        $assign->id = $assignid;

        // --- 3. cm.instance ← assign.id ---------------------------------
        $DB->set_field('course_modules', 'instance', $assignid, array('id' => $cmid));

        // --- 4. Section générale ----------------------------------------
        course_add_cm_to_section($course, $cmid, 0);

        // --- 5. Activation des plugins (soumission + correction IA) -----
        // is_enabled() lit {assign_plugin_config}.name='enabled' ; absent = off.
        self::enable_plugin($assignid, 'assignsubmission', 'onlinetext', array('enabled' => '1'));
        self::enable_plugin($assignid, 'assignsubmission', 'file', array(
            'enabled'                => '1',
            'maxfilesubmissions'     => '5',
            'maxsubmissionsizebytes' => '0', // 0 = limite du cours
            'filetypeslist'          => '',
        ));
        self::enable_plugin($assignid, 'assignfeedback', 'ai', array('enabled' => '1'));

        // --- 6. Carnet de notes (élément de type barème si grade < 0) ---
        $assign->cmidnumber   = '';
        $assign->cmid         = $cmid;
        $assign->coursemodule = $cmid;
        try {
            assign_grade_item_update($assign);
        } catch (\Throwable $e) {
            $DB->delete_records('assign_plugin_config', array('assignment' => $assignid));
            $DB->delete_records('assign', array('id' => $assignid));
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
            $DB->delete_records('course_modules', array('id' => $cmid));
            throw $e;
        }

        // --- 7. Reconstruit le cache du cours ---------------------------
        rebuild_course_cache($courseid, true);

        $cm = get_coursemodule_from_instance('assign', $assignid);
        if (!$cm) {
            throw new \moodle_exception('error_assign_creation', 'local_aimissions');
        }
        return $cm;
    }

    /**
     * Barème des devoirs créés : celui du cours d'abord, puis celui du site.
     *
     * @param int         $courseid
     * @param string|null $warning reçoit un message pour le journal, le cas échéant
     * @return int id du barème, 0 = note sur 100
     */
    public static function resolve_scale(int $courseid, ?string &$warning = null): int {
        global $DB;

        $name = get_config('local_aimissions', 'scalename');
        if ($name === false) {
            $name = self::DEFAULT_SCALE; // réglage jamais enregistré
        }
        $name = trim((string)$name);
        if ($name === '') {
            return 0; // réglage vidé : note sur 100, choix explicite
        }

        $rows = $DB->get_records_select('scale', 'name = ? AND (courseid = 0 OR courseid = ?)',
            array($name, $courseid), 'courseid DESC', 'id, courseid, scale');
        if (empty($rows)) {
            $warning = 'Barème « ' . $name . ' » introuvable : devoir noté sur 100.';
            return 0;
        }
        $scale = reset($rows);
        if (!self::scale_matches_levels((string)$scale->scale)) {
            $warning = 'Le barème « ' . $name . ' » ne reprend pas les 4 niveaux de la Correction IA ('
                . implode(', ', self::LEVELS) . ') : la note ne sera pas posée automatiquement.';
        }
        return (int)$scale->id;
    }

    /**
     * Le barème porte-t-il les 4 niveaux de la Correction IA ? Comparaison sans
     * accents ni casse, comme assign_feedback_ai::apply_grade_from_result().
     */
    public static function scale_matches_levels(string $scale): bool {
        $items = array();
        foreach (explode(',', $scale) as $item) {
            $items[self::normalise($item)] = true;
        }
        foreach (self::LEVELS as $level) {
            if (!isset($items[self::normalise($level)])) {
                return false;
            }
        }
        return true;
    }

    private static function normalise(string $label): string {
        return \core_text::strtolower(\core_text::specialtoascii(trim($label)));
    }

    /**
     * Pose les lignes {assign_plugin_config} d'un plugin (active + réglages).
     */
    private static function enable_plugin(int $assignid, string $subtype, string $plugin, array $settings): void {
        global $DB;
        foreach ($settings as $name => $value) {
            $rec = new \stdClass();
            $rec->assignment = $assignid;
            $rec->subtype    = $subtype;
            $rec->plugin     = $plugin;
            $rec->name       = $name;
            $rec->value      = (string)$value;
            $DB->insert_record('assign_plugin_config', $rec);
        }
    }

    /**
     * Pré-remplit la ligne {assignfeedback_ai} : la correction IA tourne à la
     * remise sans aucune saisie de l'enseignant.
     */
    public static function prefill_feedback_config(int $assignid, \stdClass $project, array $spec): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('assignfeedback_ai')) {
            return; // plugin de correction absent : devoir créé quand même
        }

        $now = time();
        $cfg = new \stdClass();
        $cfg->assignment              = $assignid;
        $cfg->systemprompt            = self::correction_system_prompt($project);
        $cfg->exercise                = self::ensure_html((string)$spec['clientrequest']);
        $cfg->expectedanswer          = (string)$spec['rubric'];
        $cfg->competencies            = self::competencies_text($spec['competencies'] ?? array());
        $cfg->apiurl                  = '';
        $cfg->apiurl_override         = 0;
        $cfg->model                   = '';
        $cfg->model_override          = 0;
        $cfg->apikey                  = '';
        $cfg->apikey_override         = 0;
        $cfg->vision_enabled          = 0;
        $cfg->vision_enabled_override = 0;
        $cfg->timecreated             = $now;
        $cfg->timemodified            = $now;

        // La clé unique est sur 'assignment' : insert simple (devoir neuf).
        if (!$DB->record_exists('assignfeedback_ai', array('assignment' => $assignid))) {
            $DB->insert_record('assignfeedback_ai', $cfg);
        }
    }

    /**
     * Système de correction : l'IA évalue en se mettant à la place du client,
     * sur plusieurs dimensions.
     */
    public static function correction_system_prompt(\stdClass $project): string {
        $p  = "Tu es un correcteur pédagogique spécialisé dans l'enseignement supérieur technologique français";
        $p .= " (BTS Informatique / BTS CIEL).\n";
        $p .= "Ton rôle est d'évaluer objectivement les réponses d'étudiants à partir :\n";
        $p .= "- d'un exercice,\n- des compétences visées,\n- des attentes pédagogiques.\n";

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

        $p .= "Tu corriges le livrable d'une équipe d'étudiants BTS CIEL répondant à une demande ";
        $p .= "client. Tu joues le rôle du client « " . $project->companyname . " » : "
            . personas::instruction((string)$project->personaprofile) . "\n\n";
        $p .= "Évalue le livrable sur : (1) la RÉPONSE AU BESOIN exprimé par le client, ";
        $p .= "(2) la QUALITÉ TECHNIQUE, (3) la DOCUMENTATION/clarté du rapport, ";
        $p .= "(4) la COMMUNICATION (le livrable est-il présenté comme à un client ?). ";
        $p .= "Sois bienveillant mais exigeant, et justifie tes points d'amélioration en te ";
        $p .= "référant à la demande initiale. Le barème suit le schéma JSON imposé.";
        return $p;
    }

    /**
     * Liste de compétences (feedback IA) → texte, une par ligne.
     *
     * @param array|string $competencies
     */
    public static function competencies_text($competencies): string {
        if (is_array($competencies)) {
            return implode("\n", array_map('strval', $competencies));
        }
        return (string)$competencies;
    }

    /**
     * Codes EFE nettoyés : sans vide ni doublon, ordre conservé.
     *
     * @param mixed $codes
     * @return string[]
     */
    public static function clean_codes($codes): array {
        $out = array();
        foreach ((array)$codes as $code) {
            $code = trim((string)$code);
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Garantit du HTML (enveloppe le texte brut dans des <p>).
     */
    public static function ensure_html(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (strip_tags($text) === $text) {
            // Texte brut : on transforme les sauts de ligne en paragraphes.
            $parts = preg_split('/\n{2,}/', $text);
            return implode('', array_map(function($pp) {
                return '<p>' . nl2br(s(trim($pp))) . '</p>';
            }, $parts));
        }
        return $text;
    }

    /**
     * Tronque proprement une chaîne.
     */
    public static function truncate(string $s, int $max): string {
        $s = trim($s);
        if (\core_text::strlen($s) <= $max) {
            return $s;
        }
        return \core_text::substr($s, 0, $max - 1) . '…';
    }
}
