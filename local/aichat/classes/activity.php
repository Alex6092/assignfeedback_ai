<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Configuration du tuteur pour une activité, et garde d'accès commune aux
 * points d'entrée (ajax.php, stream.php).
 */
class activity {

    /**
     * Modules sur lesquels le tuteur peut être activé.
     *
     * Les tentatives de test (mod_quiz) sont prévues dans une étape ultérieure :
     * les colonnes quizscope/quiztag existent déjà en base, mais tant que le
     * widget n'est pas injecté sur la page de tentative on n'affiche pas
     * l'option à l'enseignant, pour ne pas promettre ce qui n'existe pas encore.
     */
    const SUPPORTED_MODS = array('assign');

    const TABLE = 'local_aichat_activity';

    /**
     * Configuration du tuteur pour un module de cours, ou null si jamais
     * enregistrée.
     *
     * @param int $cmid
     * @return \stdClass|null
     */
    public static function get($cmid) {
        global $DB;
        $row = $DB->get_record(self::TABLE, array('cmid' => (int)$cmid));
        return $row ? $row : null;
    }

    /** Le tuteur est-il activé sur cette activité ? */
    public static function is_enabled($cmid) {
        $cfg = self::get($cmid);
        return ($cfg !== null && !empty($cfg->enabled));
    }

    /**
     * Enregistre (crée ou met à jour) la configuration d'une activité.
     *
     * @param int   $cmid
     * @param int   $courseid
     * @param array $data champs à écrire
     * @return \stdClass la configuration enregistrée
     */
    public static function save($cmid, $courseid, array $data) {
        global $DB;
        $now = time();
        $row = self::get($cmid);

        if ($row === null) {
            $row = (object)array(
                'cmid'         => (int)$cmid,
                'courseid'     => (int)$courseid,
                'enabled'      => 0,
                'includeintro' => 1,
                'includebrief' => 1,
                'brief'        => null,
                'briefstatus'  => 'none',
                'briefhash'    => '',
                'brieferror'   => null,
                'customprompt' => null,
                'quizscope'    => 'all',
                'quiztag'      => '',
                'timecreated'  => $now,
                'timemodified' => $now,
            );
            foreach ($data as $key => $value) {
                $row->{$key} = $value;
            }
            $row->id = $DB->insert_record(self::TABLE, $row);
            return $row;
        }

        $row->courseid     = (int)$courseid;
        $row->timemodified = $now;
        foreach ($data as $key => $value) {
            $row->{$key} = $value;
        }
        $DB->update_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Garde commune des points d'entrée élève : identifie l'activité, exige la
     * connexion, le sesskey et la capacité, et vérifie que le tuteur y est
     * bien activé.
     *
     * @param int $cmid
     * @return \stdClass {cm, course, context, config}
     * @throws \moodle_exception
     */
    public static function require_access($cmid) {
        $cmid = (int)$cmid;
        list($course, $cm) = get_course_and_cm_from_cmid($cmid);
        require_login($course, false, $cm);
        require_sesskey();

        $context = \context_module::instance($cm->id);
        require_capability('local/aichat:use', $context);

        if (!in_array($cm->modname, self::SUPPORTED_MODS, true)) {
            throw new \moodle_exception('notsupported', 'local_aichat');
        }
        $config = self::get($cmid);
        if ($config === null || empty($config->enabled)) {
            throw new \moodle_exception('tutordisabled', 'local_aichat');
        }

        return (object)array(
            'cm'      => $cm,
            'course'  => $course,
            'context' => $context,
            'config'  => $config,
        );
    }

    /**
     * Enregistrement de l'activité (assign ou quiz) : nom + description.
     *
     * @param \cm_info|\stdClass $cm
     * @return \stdClass|false
     */
    public static function module_record($cm) {
        global $DB;
        if (!in_array($cm->modname, self::SUPPORTED_MODS, true)) {
            return false;
        }
        return $DB->get_record($cm->modname, array('id' => (int)$cm->instance),
            'id, name, intro, introformat');
    }

    /**
     * Configuration de la correction IA du devoir, si le plugin
     * assignfeedback_ai est installé et configuré sur cette activité.
     *
     * Sert UNIQUEMENT à récupérer les compétences (transmises au tuteur) et le
     * corrigé (utilisé hors ligne pour fabriquer le brief). Le corrigé n'est
     * jamais donné au tuteur.
     *
     * @param \cm_info|\stdClass $cm
     * @return \stdClass|null
     */
    public static function feedback_config($cm) {
        global $DB;
        if ($cm->modname !== 'assign') {
            return null;
        }
        if (!$DB->get_manager()->table_exists('assignfeedback_ai')) {
            return null;
        }
        $row = $DB->get_record('assignfeedback_ai', array('assignment' => (int)$cm->instance));
        return $row ? $row : null;
    }

    /**
     * Supprime les conversations et messages d'un module, en conservant la
     * configuration du tuteur (cas d'un effacement de données personnelles :
     * l'activité, elle, continue d'exister).
     */
    public static function delete_conversations_for_cmid($cmid) {
        global $DB;
        $cmid = (int)$cmid;

        $conversationids = $DB->get_fieldset_select('local_aichat_conversation', 'id',
            'cmid = ?', array($cmid));
        if (!empty($conversationids)) {
            foreach (array_chunk($conversationids, 500) as $chunk) {
                list($insql, $params) = $DB->get_in_or_equal($chunk);
                $DB->delete_records_select('local_aichat_message', "conversationid $insql", $params);
            }
        }
        $DB->delete_records('local_aichat_conversation', array('cmid' => $cmid));
    }

    /**
     * Supprime toutes les données liées à un module, configuration comprise
     * (activité supprimée du cours).
     */
    public static function delete_for_cmid($cmid) {
        global $DB;
        self::delete_conversations_for_cmid($cmid);
        $DB->delete_records(self::TABLE, array('cmid' => (int)$cmid));
    }
}
