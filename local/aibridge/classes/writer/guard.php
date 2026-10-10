<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Garde-fous de l'écriture, vérifiés côté serveur avant toute création,
 * modification ou suppression : l'agent ne peut pas les contourner.
 *
 *   - l'écriture doit être activée par l'administrateur (enablewrites) ;
 *   - la cible doit se trouver dans une catégorie bac à sable, ou dans une de
 *     ses sous-catégories ;
 *   - le compte doit avoir local/aibridge:write sur cette catégorie.
 */
class guard {

    /** L'écriture est-elle activée ? */
    public static function writes_enabled(): bool {
        return (bool)get_config('local_aibridge', 'enablewrites');
    }

    /** @return int[] catégories bac à sable choisies par l'administrateur */
    public static function sandbox_ids(): array {
        $raw = (string)get_config('local_aibridge', 'sandboxcategories');
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /** La catégorie est-elle un bac à sable, ou dans un bac à sable ? */
    public static function in_sandbox(int $categoryid): bool {
        global $DB;
        $path = $DB->get_field('course_categories', 'path', array('id' => $categoryid));
        if ($path === false) {
            return false;
        }
        $ancestors = array_map('intval', array_filter(explode('/', $path)));
        return (bool)array_intersect($ancestors, self::sandbox_ids());
    }

    /**
     * Écriture permise dans cette catégorie : activée, bac à sable, capacité.
     *
     * @return \context_coursecat
     */
    public static function require_category(int $categoryid): \context_coursecat {
        if (!self::writes_enabled()) {
            throw new \moodle_exception('error_writesdisabled', 'local_aibridge');
        }
        if (!self::in_sandbox($categoryid)) {
            throw new \moodle_exception('error_notsandbox', 'local_aibridge', '', $categoryid);
        }
        $context = \context_coursecat::instance($categoryid);
        require_capability('local/aibridge:write', $context);
        return $context;
    }

    /**
     * Écriture permise dans ce cours (sa catégorie est vérifiée).
     *
     * @return \stdClass le cours
     */
    public static function require_course(int $courseid): \stdClass {
        $course = get_course($courseid);
        self::require_category((int)$course->category);
        return $course;
    }

    /**
     * Brouillon du pont : activité créée par le pont, encore masquée, dans un
     * cours où l'écriture est permise. Seuls ces brouillons voient leur contenu
     * remplacé (pages de leçon, questions d'un test, réglages des extensions).
     *
     * @param int         $cmid
     * @param string|null $modname type attendu (lesson, quiz...)
     * @return array [cours, cm]
     */
    public static function require_draft_cm(int $cmid, ?string $modname = null): array {
        $cm = get_coursemodule_from_id((string)$modname, $cmid, 0, false, MUST_EXIST);
        $course = self::require_course((int)$cm->course);
        if (!created::has(created::CM, $cmid)) {
            throw new \moodle_exception('error_notcreated', 'local_aibridge', '', 'cm ' . $cmid);
        }
        if ((int)$cm->visible !== 0) {
            throw new \moodle_exception('error_draftvisible', 'local_aibridge', '', 'cm ' . $cmid);
        }
        return array($course, $cm);
    }
}
