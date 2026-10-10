<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

use core_courseformat\formatactions;

/**
 * Suppression des brouillons du pont. Seul ce que le pont a créé (registre
 * local_aibridge_created) peut être supprimé, et seulement tant que c'est
 * masqué : dès qu'un enseignant publie un objet, le pont n'y touche plus.
 */
class draft_writer {

    const TYPES = array(created::CM, created::SECTION, created::COURSE);

    /**
     * Supprime un brouillon.
     *
     * @param string $type   cm, section (id de course_sections) ou course
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
        }
        return array();
    }
}
