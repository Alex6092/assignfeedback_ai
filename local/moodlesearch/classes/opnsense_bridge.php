<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Pont OPTIONNEL vers le bloc OPNsense (block_opnsenseaccess) : ouverture d'un
 * site pour la classe quand l'élève clique un résultat, liste noire, mode examen.
 *
 * No-op silencieux si le bloc n'est pas installé (ou trop ancien pour avoir
 * l'API site_request) : MoodleSearch fonctionne alors sans ouverture de site.
 * Aucune erreur du pare-feu ne doit empêcher une recherche.
 */
class opnsense_bridge {

    /** Statut d'un clic quand le pare-feu n'est pas concerné (pas de bloc, pas de classe). */
    const NONE = 'none';

    public static function available(): bool {
        return class_exists('\block_opnsenseaccess\site_request');
    }

    /**
     * L'utilisateur est-il un élève d'une classe gérée par OPNsense ? Sinon
     * (personnel, élève sans classe configurée), un clic ouvre directement le site.
     */
    public static function concerned(int $userid): bool {
        if (!self::available()) {
            return false;
        }
        try {
            $cohortid = \block_opnsenseaccess\manager::get_user_cohort($userid);
            return $cohortid !== null && (bool)\block_opnsenseaccess\manager::get_classmap($cohortid);
        } catch (\Throwable $e) {
            debugging('local_moodlesearch : OPNsense — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /** Un mode examen est-il actif dans la classe de l'élève ? */
    public static function exam_active(int $userid): bool {
        if (!self::available()) {
            return false;
        }
        try {
            return \block_opnsenseaccess\site_request::exam_active_for_user($userid);
        } catch (\Throwable $e) {
            debugging('local_moodlesearch : OPNsense — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /** Un mode examen est-il actif pour cette cohorte ? (page des cohortes) */
    public static function exam_active_for_cohort(int $cohortid): bool {
        if (!self::available()) {
            return false;
        }
        try {
            return \block_opnsenseaccess\exam_mode::get_active($cohortid) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Quels résultats pointent vers un site en liste noire pour la classe de
     * l'élève ? (badge « Bloqué par l'établissement »)
     *
     * @param int      $userid
     * @param string[] $urls
     * @return array url => bool
     */
    public static function blocked(int $userid, array $urls): array {
        $none = array_fill_keys($urls, false);
        if (!self::available() || empty($urls)) {
            return $none;
        }
        try {
            return \block_opnsenseaccess\site_request::blocked_domains_for_user($userid, $urls) + $none;
        } catch (\Throwable $e) {
            debugging('local_moodlesearch : OPNsense — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $none;
        }
    }

    /**
     * Demande l'ouverture du site pour la classe de l'élève : liste noire,
     * mode examen, ouverture automatique ou demande à l'enseignant, selon la
     * politique du bloc OPNsense. Un site en liste noire n'est JAMAIS ouvert,
     * et le clic n'encombre pas la liste des demandes de l'enseignant (il reste
     * dans le rapport MoodleSearch).
     *
     * @return string statut (\block_opnsenseaccess\site_request::*, ou self::NONE)
     */
    public static function open(int $userid, string $url): string {
        if (!self::available()) {
            return self::NONE;
        }
        try {
            $result = \block_opnsenseaccess\site_request::request_for_user($userid, $url,
                get_string('opnsense_reason', 'local_moodlesearch'), array('recordblocked' => false));
            return (string)$result->status;
        } catch (\Throwable $e) {
            debugging('local_moodlesearch : OPNsense — ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 'error';
        }
    }
}
