<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\formatactions;

/**
 * Suppression des brouillons du pont. Seul ce que le pont a créé (registre
 * local_aibridge_created) peut être supprimé, et seulement tant que c'est
 * masqué : dès qu'un enseignant publie un objet, le pont n'y touche plus.
 * Une catégorie de questions n'a pas de visibilité : elle n'est supprimée que
 * si toutes ses questions viennent du compte du pont et ne servent plus.
 */
class draft_writer {

    const TYPES = array(created::CM, created::SECTION, created::COURSE, question_writer::CATEGORY);

    /**
     * Supprime un brouillon.
     *
     * @param string $type   cm, section (id de course_sections), course ou questioncategory
     * @param int    $id
     * @param bool   $dryrun vérifier sans supprimer
     * @return array
     */
    public static function delete(string $type, int $id, bool $dryrun): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        if (!in_array($type, self::TYPES, true)) {
            throw new \moodle_exception('error_drafttype', 'local_aibridge', '', $type);
        }
        if (!created::has($type, $id)) {
            throw new \moodle_exception('error_notcreated', 'local_aibridge', '', $type . ' ' . $id);
        }

        switch ($type) {
            case created::CM:
                $cm = $DB->get_record('course_modules', array('id' => $id, 'deletioninprogress' => 0));
                if (!$cm) {
                    created::remove($type, $id);
                    throw new \moodle_exception('error_draftgone', 'local_aibridge', '', $type . ' ' . $id);
                }
                guard::require_course((int)$cm->course);
                if ((int)$cm->visible !== 0) {
                    throw new \moodle_exception('error_draftvisible', 'local_aibridge', '', $type . ' ' . $id);
                }
                if (!$dryrun) {
                    formatactions::cm((int)$cm->course)->delete($id);
                    created::remove($type, $id);
                }
                return array('deleted' => !$dryrun, 'type' => $type, 'id' => $id, 'courseid' => (int)$cm->course);

            case created::SECTION:
                $section = $DB->get_record('course_sections', array('id' => $id));
                if (!$section) {
                    created::remove($type, $id);
                    throw new \moodle_exception('error_draftgone', 'local_aibridge', '', $type . ' ' . $id);
                }
                $course = guard::require_course((int)$section->course);
                if ((int)$section->section === 0) {
                    throw new \moodle_exception('error_sectionzero', 'local_aibridge');
                }
                if ((int)$section->visible !== 0) {
                    throw new \moodle_exception('error_draftvisible', 'local_aibridge', '', $type . ' ' . $id);
                }
                if (trim((string)$section->sequence) !== '') {
                    throw new \moodle_exception('error_sectionnotempty', 'local_aibridge', '', $section->section);
                }
                if (!$dryrun) {
                    $info = get_fast_modinfo($course)->get_section_info_by_id($id);
                    formatactions::section($course)->delete($info, false);
                    created::remove($type, $id);
                }
                return array('deleted' => !$dryrun, 'type' => $type, 'id' => $id, 'courseid' => (int)$course->id,
                    'section' => (int)$section->section);

            case created::COURSE:
                $course = guard::require_course($id);
                require_capability('moodle/course:delete', \context_course::instance($id));
                if ((int)$course->visible !== 0) {
                    throw new \moodle_exception('error_draftvisible', 'local_aibridge', '', $type . ' ' . $id);
                }
                // Un cours n'est un brouillon que si tout ce qu'il contient vient du pont et reste masqué.
                foreach ($DB->get_records('course_modules', array('course' => $id, 'deletioninprogress' => 0),
                        '', 'id, visible') as $cm) {
                    if (!created::has(created::CM, (int)$cm->id) || (int)$cm->visible !== 0) {
                        throw new \moodle_exception('error_coursenotdraft', 'local_aibridge', '', $cm->id);
                    }
                }
                if (!$dryrun) {
                    delete_course($course, false);
                    created::remove_course($id);
                }
                return array('deleted' => !$dryrun, 'type' => $type, 'id' => $id, 'courseid' => $id);

            case question_writer::CATEGORY:
                return self::delete_category($id, $dryrun);
        }
        return array();
    }

    /**
     * Supprime une catégorie de questions créée par le pont, ses sous-catégories
     * (« — IA ») et leurs questions, pour réimporter au même nom. Refusé si une
     * sous-catégorie ne vient pas du pont, si une question (ou une de ses
     * versions) a été créée ou modifiée par un autre compte, ou si une question
     * sert encore : supprimer d'abord le test qui l'utilise.
     *
     * @return array
     */
    private static function delete_category(int $id, bool $dryrun): array {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/questionlib.php');

        $type = question_writer::CATEGORY;
        $category = $DB->get_record('question_categories', array('id' => $id));
        if (!$category) {
            created::remove($type, $id);
            throw new \moodle_exception('error_draftgone', 'local_aibridge', '', $type . ' ' . $id);
        }
        $coursecontext = \context::instance_by_id((int)$category->contextid)->get_course_context(false);
        if (!$coursecontext) {
            throw new \moodle_exception('error_notcreated', 'local_aibridge', '', $type . ' ' . $id);
        }
        $course = guard::require_course((int)$coursecontext->instanceid);

        $manager = new \core_question\category_manager();
        $ids = self::category_tree($id);
        $questionids = array();
        foreach ($ids as $catid) {
            if (!created::has($type, $catid)) {
                throw new \moodle_exception('error_notcreated', 'local_aibridge', '', $type . ' ' . $catid);
            }
            $questionids = array_merge($questionids, $manager->get_real_question_ids_in_category($catid));
        }
        if ($questionids) {
            list($insql, $params) = $DB->get_in_or_equal($questionids);
            $params[] = (int)$USER->id;
            if ($DB->record_exists_select('question', "id $insql AND createdby <> ?", $params)) {
                throw new \moodle_exception('error_categoryforeign', 'local_aibridge', '', $category->name);
            }
            if (questions_in_use($questionids)) {
                throw new \moodle_exception('error_categoryinuse', 'local_aibridge', '', $category->name);
            }
        }

        if (!$dryrun) {
            foreach ($ids as $catid) {
                foreach ($manager->get_real_question_ids_in_category($catid) as $questionid) {
                    question_delete_question($questionid);
                }
                $manager->delete_category($catid);
                created::remove($type, $catid);
            }
        }
        return array('deleted' => !$dryrun, 'type' => $type, 'id' => $id, 'courseid' => (int)$course->id,
            'category' => $category->name, 'categories' => count($ids), 'questions' => count($questionids));
    }

    /**
     * Une catégorie et toutes ses descendantes, les plus profondes d'abord
     * (ordre de suppression).
     *
     * @return int[]
     */
    private static function category_tree(int $id): array {
        global $DB;
        $out = array();
        foreach ($DB->get_fieldset_select('question_categories', 'id', 'parent = ?', array($id)) as $child) {
            $out = array_merge($out, self::category_tree((int)$child));
        }
        $out[] = $id;
        return $out;
    }
}
