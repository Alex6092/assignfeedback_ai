<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Opérations de cycle de vie sur les missions : suppression propre (devoir +
 * ligne + rembobinage du projet), avec un point d'entrée unique pour éviter
 * les doubles traitements entre l'UI et l'observer course_module_deleted.
 */
class mission_manager {

    /**
     * Supprime une mission « pour de vrai » depuis l'UI :
     *   - si le devoir existe encore → course_delete_module() (le coeur supprime
     *     le module, les notes, les soumissions ; l'observer nettoiera notre
     *     ligne + rembobinera le projet) ;
     *   - sinon (orpheline : devoir déjà supprimé) → on nettoie directement.
     *
     * @return bool true si quelque chose a été supprimé.
     */
    public static function delete_mission_full(int $missionid): bool {
        global $DB, $CFG;

        $mission = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid));
        if (!$mission) {
            return false;
        }

        $cmid = (int)$mission->assigncmid;
        if ($cmid > 0 && $DB->record_exists('course_modules', array('id' => $cmid))) {
            require_once($CFG->dirroot . '/course/lib.php');
            // Déclenche course_module_deleted → observer::course_module_deleted
            // qui supprime la ligne mission et rembobine le projet.
            course_delete_module($cmid);
            // Filet de sécurité si l'observer n'a pas matché (course_delete_module
            // peut être asynchrone selon la config) : on nettoie la ligne restante.
            $still = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid));
            if ($still) {
                self::delete_mission_row($still, true);
            }
            return true;
        }

        // Mission orpheline : pas (ou plus) de devoir.
        self::delete_mission_row($mission, true);
        return true;
    }

    /**
     * Supprime la ligne mission et, si demandé, rembobine le projet (uniquement
     * si la mission supprimée était bien le DERNIER sprint du projet).
     *
     * Appelée par l'observer (réconciliation après suppression du module) et,
     * en filet de sécurité, par delete_mission_full().
     */
    public static function delete_mission_row(\stdClass $mission, bool $rollback): void {
        global $DB;

        if ((int)$mission->assigncmid > 0) {
            efe_bridge::detach((int)$mission->assigncmid);
        }
        $DB->delete_records(job_handler::TABLE_MISSION, array('id' => (int)$mission->id));

        if ($rollback) {
            self::rollback_project((int)$mission->projectid, (int)$mission->sprint);
        }
    }

    // =====================================================================
    //  DUPLICATION D'UN SPRINT VERS D'AUTRES GROUPES
    // =====================================================================

    /**
     * Compétences EFE d'une mission : liste (0.8.0), ou ancien trio N1/N2/N3
     * (la compétence effective d'EFE : N3, sinon N2, sinon N1).
     *
     * @return string[]
     */
    public static function efe_codes(\stdClass $mission): array {
        $codes = json_decode((string)($mission->efe_competences ?? ''), true);
        if (is_array($codes) && !empty($codes)) {
            return assign_factory::clean_codes($codes);
        }
        foreach (array('efe_competence_n3', 'efe_competence_n2', 'efe_competence_n1') as $field) {
            $code = trim((string)($mission->$field ?? ''));
            if ($code !== '') {
                return array($code);
            }
        }
        return array();
    }

    /**
     * Pourquoi la COPIE à l'identique d'un sprint vers ce groupe est impossible.
     *
     * Copie possible vers un groupe « au même point » :
     *   - sans projet : il reprend l'entreprise du groupe source ;
     *   - ou suivant la même entreprise, au sprint précédent.
     *
     * @return string '' si possible, sinon clé de chaîne dup_reason_*
     */
    public static function copy_blocker(\stdClass $mission, \stdClass $sourceproject, int $groupid): string {
        global $DB;
        if ($groupid === (int)$sourceproject->groupid) {
            return 'dup_reason_samegroup';
        }
        $target = $DB->get_record(job_handler::TABLE_PROJECT,
            array('courseid' => (int)$sourceproject->courseid, 'groupid' => $groupid));
        if (!$target) {
            return '';
        }
        if ((string)$target->companyname !== (string)$sourceproject->companyname) {
            return 'dup_reason_othercompany';
        }
        if ((int)$target->currentsprint !== (int)$mission->sprint - 1) {
            return 'dup_reason_othersprint';
        }
        return '';
    }

    /**
     * Copie à l'identique un sprint vers un groupe au même point : même
     * demande, même corrigé, mêmes compétences, même contexte, mêmes réglages
     * du tuteur. Le devoir est créé caché, à relire puis publier.
     *
     * @param int $missionid sprint source
     * @param int $groupid   groupe cible
     * @param int $userid    enseignant (associé aux notes EFE)
     * @return int id de la nouvelle mission
     * @throws \moodle_exception si le groupe n'est pas au même point
     */
    public static function duplicate_copy(int $missionid, int $groupid, int $userid): int {
        global $DB;

        $mission = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid), '*', MUST_EXIST);
        $source  = $DB->get_record(job_handler::TABLE_PROJECT, array('id' => (int)$mission->projectid),
            '*', MUST_EXIST);
        $reason = self::copy_blocker($mission, $source, $groupid);
        if ($reason !== '') {
            throw new \moodle_exception($reason, 'local_aimissions', '', (int)$mission->sprint - 1);
        }
        $sprint = (int)$mission->sprint;

        // Projet cible : créé en reprenant l'entreprise du groupe source, avec
        // son historique jusqu'au sprint précédent.
        $target = $DB->get_record(job_handler::TABLE_PROJECT,
            array('courseid' => (int)$source->courseid, 'groupid' => $groupid));
        if (!$target) {
            $dossier = json_decode((string)$source->dossier, true) ?: array();
            $dossier['history'] = array_slice(array_values((array)($dossier['history'] ?? array())),
                0, $sprint - 1);
            $now = time();
            $target = new \stdClass();
            $target->courseid          = (int)$source->courseid;
            $target->groupid           = $groupid;
            $target->companyname       = (string)$source->companyname;
            $target->sector            = $source->sector;
            $target->persona           = $source->persona;
            $target->personaprofile    = (string)$source->personaprofile;
            $target->competencytargets = $source->competencytargets;
            $target->dossier           = json_encode($dossier, JSON_UNESCAPED_UNICODE);
            $target->currentsprint     = $sprint - 1;
            $target->clientstatus      = 'active';
            $target->clientwarnings    = 0;
            $target->timecreated       = $now;
            $target->timemodified      = $now;
            $target->id = (int)$DB->insert_record(job_handler::TABLE_PROJECT, $target);
        }

        $codes = self::efe_codes($mission);
        $spec  = array(
            'title'         => (string)$mission->title,
            'clientrequest' => (string)$mission->clientrequest,
            'rubric'        => (string)$mission->rubric,
            'competencies'  => (string)$mission->competencies,
        );
        $options = array('efe_codes' => $codes, 'profid' => $userid)
            + aichat_bridge::settings_of((int)$mission->assigncmid);
        $cm = assign_factory::create((int)$source->courseid, $target, $spec, $options);

        $copy = new \stdClass();
        $copy->projectid          = (int)$target->id;
        $copy->sprint             = $sprint;
        $copy->title              = $mission->title;
        $copy->clientrequest      = $mission->clientrequest;
        $copy->rubric             = $mission->rubric;
        $copy->competencies       = $mission->competencies;
        $copy->pedagogicalcontext = $mission->pedagogicalcontext ?? null;
        $copy->efe_competences    = !empty($codes) ? json_encode($codes, JSON_UNESCAPED_UNICODE) : null;
        $copy->efe_competence_n1  = !empty($codes) ? $codes[0] : null;
        $copy->efe_competence_n2  = null;
        $copy->efe_competence_n3  = null;
        $copy->sourcemissionid    = (int)$mission->id;
        $copy->assigncmid         = (int)$cm->id;
        $copy->status             = 'draft';
        $copy->timecreated        = time();
        $copyid = (int)$DB->insert_record(job_handler::TABLE_MISSION, $copy);

        // Historique : le même résumé que le groupe source pour ce sprint.
        $sourcedossier = json_decode((string)$source->dossier, true) ?: array();
        $summary = $sourcedossier['history'][$sprint - 1] ?? (string)$mission->title;
        $target = $DB->get_record(job_handler::TABLE_PROJECT, array('id' => (int)$target->id));
        $dossier = json_decode((string)$target->dossier, true) ?: array();
        $dossier['history'] = array_values((array)($dossier['history'] ?? array()));
        $dossier['history'][] = $summary;
        $update = new \stdClass();
        $update->id            = (int)$target->id;
        $update->currentsprint = $sprint;
        $update->dossier       = json_encode($dossier, JSON_UNESCAPED_UNICODE);
        $update->timemodified  = time();
        $DB->update_record(job_handler::TABLE_PROJECT, $update);

        return $copyid;
    }

    /**
     * Met en file l'ADAPTATION d'un sprint pour un autre groupe : le LLM
     * réécrit la mission pour l'entreprise de ce groupe (créée si besoin).
     * Contexte, compétences et réglages du tuteur sont repris du sprint source ;
     * niveau, complexité et profil client, de sa génération d'origine.
     *
     * @return int id du job
     */
    public static function enqueue_adapt(int $missionid, int $groupid, int $userid): int {
        global $DB;

        $mission = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid), '*', MUST_EXIST);
        $source  = $DB->get_record(job_handler::TABLE_PROJECT, array('id' => (int)$mission->projectid),
            '*', MUST_EXIST);
        $origin = self::source_params($mission);

        $params = array(
            'groupid'            => $groupid,
            'adaptfrom'          => (int)$mission->id,
            'pedagogicalcontext' => (string)($mission->pedagogicalcontext ?? ($origin['pedagogicalcontext'] ?? '')),
            'level'              => (string)($origin['level'] ?? 'BTS CIEL 1ère année'),
            'complexity'         => (string)($origin['complexity'] ?? 'Intermédiaire'),
            'constraints'        => (int)($origin['constraints'] ?? 3),
            'personaprofile'     => (string)$source->personaprofile,
            'competencylabel'    => (string)($origin['competencylabel'] ?? $source->competencytargets),
            'efe_codes'          => self::efe_codes($mission),
        ) + aichat_bridge::settings_of((int)$mission->assigncmid);

        $now = time();
        $job = new \stdClass();
        $job->courseid        = (int)$source->courseid;
        $job->userid          = $userid;
        $job->projectid       = 0;
        $job->kind            = 'mission';
        $job->params          = json_encode($params, JSON_UNESCAPED_UNICODE);
        $job->status          = 'pending';
        $job->log             = '';
        $job->lasterror       = null;
        $job->resultmissionid = 0;
        $job->attempts        = 0;
        $job->notbefore       = 0;
        $job->timecreated     = $now;
        $job->timemodified    = $now;
        $job->id = (int)$DB->insert_record(job_handler::TABLE_JOB, $job);
        job_handler::enqueue($job->id);
        return $job->id;
    }

    /**
     * Paramètres de la génération qui a produit une mission (niveau,
     * complexité…). Une copie n'a pas de génération : on remonte à son origine.
     */
    private static function source_params(\stdClass $mission, int $depth = 0): array {
        global $DB;
        $jobs = $DB->get_records(job_handler::TABLE_JOB, array('resultmissionid' => (int)$mission->id),
            'id DESC', 'id, params', 0, 1);
        if (!empty($jobs)) {
            $params = json_decode((string)reset($jobs)->params, true);
            return is_array($params) ? $params : array();
        }
        if ($depth < 10 && (int)($mission->sourcemissionid ?? 0) > 0) {
            $origin = $DB->get_record(job_handler::TABLE_MISSION, array('id' => (int)$mission->sourcemissionid));
            if ($origin) {
                return self::source_params($origin, $depth + 1);
            }
        }
        return array();
    }

    /**
     * Rembobine le projet d'un cran si on vient de supprimer son dernier sprint :
     * décrémente currentsprint et retire la dernière entrée du dossier, afin
     * qu'une nouvelle génération reparte du même numéro de sprint.
     */
    private static function rollback_project(int $projectid, int $sprint): void {
        global $DB;

        $project = $DB->get_record(job_handler::TABLE_PROJECT, array('id' => $projectid));
        if (!$project) {
            return;
        }
        // On ne rembobine que si on a retiré le sprint courant (le plus récent).
        if ((int)$project->currentsprint !== $sprint) {
            return;
        }
        $dossier = json_decode((string)$project->dossier, true) ?: array();
        if (!empty($dossier['history']) && is_array($dossier['history'])) {
            array_pop($dossier['history']);
        }
        $update = new \stdClass();
        $update->id            = $projectid;
        $update->currentsprint = max(0, $sprint - 1);
        $update->dossier       = json_encode($dossier, JSON_UNESCAPED_UNICODE);
        $update->timemodified  = time();
        $DB->update_record(job_handler::TABLE_PROJECT, $update);
    }
}
