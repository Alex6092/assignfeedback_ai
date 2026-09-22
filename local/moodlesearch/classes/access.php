<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Qui peut chercher avec MoodleSearch.
 *
 * Règle des cohortes : MoodleSearch est ouvert par défaut (réglage
 * defaultaccess) ; une cohorte peut être activée ou désactivée, et une cohorte
 * désactivée l'emporte toujours (une classe coupée pendant une évaluation reste
 * coupée, même si l'élève est aussi dans une cohorte activée). Un mode examen
 * OPNsense actif dans la classe ferme aussi MoodleSearch.
 */
class access {

    /** Motifs de refus. */
    const DISABLED   = 'disabled';
    const CAPABILITY = 'capability';
    const COHORT     = 'cohort';
    const EXAM       = 'exam';
    const NOKEY      = 'nokey';

    const TABLE = 'local_moodlesearch_cohort';

    /** MoodleSearch est-il activé sur le site ? */
    public static function enabled(): bool {
        return !empty(get_config('local_moodlesearch', 'enabled'));
    }

    /**
     * Motif pour lequel l'utilisateur ne peut pas chercher, ou '' s'il peut.
     *
     * @param int  $userid
     * @param bool $checkkey vérifier aussi qu'il a une clé Tavily
     * @return string
     */
    public static function reason(int $userid, bool $checkkey = true): string {
        if (!self::enabled()) {
            return self::DISABLED;
        }
        if (!has_capability('local/moodlesearch:use', \context_system::instance(), $userid)) {
            return self::CAPABILITY;
        }
        if (!self::cohort_allowed($userid)) {
            return self::COHORT;
        }
        if (opnsense_bridge::exam_active($userid)) {
            return self::EXAM;
        }
        if ($checkkey && \local_aichat\websearch\userkeys::get($userid, 'tavily') === '') {
            return self::NOKEY;
        }
        return '';
    }

    /**
     * Les cohortes de l'utilisateur l'autorisent-elles ?
     * Désactivée l'emporte ; sinon activée ; sinon le réglage par défaut.
     */
    public static function cohort_allowed(int $userid): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $cohortids = array_keys(cohort_get_user_cohorts($userid));
        if (!empty($cohortids)) {
            list($insql, $params) = $DB->get_in_or_equal($cohortids);
            $states = array_map('intval', $DB->get_fieldset_select(self::TABLE, 'enabled',
                "cohortid $insql", $params));
            if (in_array(0, $states, true)) {
                return false;
            }
            if (in_array(1, $states, true)) {
                return true;
            }
        }
        return self::default_allowed();
    }

    /** Accès des personnes qu'aucune cohorte réglée ne concerne. */
    public static function default_allowed(): bool {
        $value = get_config('local_moodlesearch', 'defaultaccess');
        return $value === false || !empty($value); // autorisé tant que jamais enregistré
    }

    /**
     * Règle une cohorte.
     *
     * @param int      $cohortid
     * @param int|null $enabled 1 activée, 0 désactivée, null = réglage par défaut
     * @param int      $userid  auteur du changement
     */
    public static function set_cohort_state(int $cohortid, ?int $enabled, int $userid): void {
        global $DB;
        if ($enabled === null) {
            $DB->delete_records(self::TABLE, array('cohortid' => $cohortid));
            return;
        }
        $row = $DB->get_record(self::TABLE, array('cohortid' => $cohortid));
        $data = (object)array('cohortid' => $cohortid, 'enabled' => $enabled ? 1 : 0,
            'modifiedby' => $userid, 'timemodified' => time());
        if ($row) {
            $data->id = $row->id;
            $DB->update_record(self::TABLE, $data);
        } else {
            $DB->insert_record(self::TABLE, $data);
        }
    }

    /**
     * États réglés des cohortes.
     *
     * @return array cohortid => \stdClass {enabled, modifiedby, timemodified}
     */
    public static function cohort_states(): array {
        global $DB;
        $out = array();
        foreach ($DB->get_records(self::TABLE) as $row) {
            $out[(int)$row->cohortid] = $row;
        }
        return $out;
    }
}
