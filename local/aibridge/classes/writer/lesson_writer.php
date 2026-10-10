<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Pages de contenu d'une leçon créée par le pont. Les pages sont chaînées
 * comme dans les parcours de l'enseignant : « Suivant » sur la première,
 * « Précédent » / « Suivant » au milieu, « Précédent » / « Terminer » sur la
 * dernière ; une leçon d'une page n'a que « Terminer ».
 */
class lesson_writer {

    /** Page de contenu (LESSON_PAGE_BRANCHTABLE). */
    const PAGE_CONTENT = 20;
    /** Sauts : page suivante, page précédente, fin de la leçon. */
    const JUMP_NEXT = -1;
    const JUMP_PREVIOUS = -40;
    const JUMP_END = -9;

    /**
     * Remplace toutes les pages de la leçon.
     *
     * @param int     $cmid   leçon brouillon du pont
     * @param array[] $pages  [{title, contents (HTML)}], dans l'ordre
     * @param bool    $dryrun
     * @return array
     */
    public static function set_pages(int $cmid, array $pages, bool $dryrun): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        list($course, $cm) = guard::require_draft_cm($cmid, 'lesson');
        if (!$pages) {
            throw new \moodle_exception('error_lessonpages', 'local_aibridge');
        }
        $clean = array();
        foreach (array_values($pages) as $i => $page) {
            $title = trim((string)($page['title'] ?? ''));
            $contents = (string)($page['contents'] ?? '');
            if ($title === '' || trim($contents) === '') {
                throw new \moodle_exception('error_lessonpage', 'local_aibridge', '', $i + 1);
            }
            $clean[] = array('title' => $title, 'contents' => $contents);
        }
        $existing = $DB->count_records('lesson_pages', array('lessonid' => $cm->instance));
        if ($dryrun) {
            return array('dryrun' => true, 'cmid' => $cmid, 'replaced' => $existing,
                'pages' => array_map(function($p) {
                    return array('title' => $p['title'], 'length' => \core_text::strlen($p['contents']));
                }, $clean));
        }

        $lesson = \lesson::load((int)$cm->instance);
        $context = \context_module::instance($cmid);
        foreach ($DB->get_records('lesson_pages', array('lessonid' => $lesson->id), '', 'id') as $old) {
            \lesson_page::load((int)$old->id, $lesson)->delete();
        }

        $count = count($clean);
        $previd = 0;
        $out = array();
        foreach ($clean as $i => $page) {
            $answers = array();
            $jumps = array();
            if ($i > 0) {
                $answers[] = 'Précédent';
                $jumps[] = self::JUMP_PREVIOUS;
            }
            if ($i < $count - 1) {
                $answers[] = 'Suivant';
                $jumps[] = self::JUMP_NEXT;
            } else {
                $answers[] = 'Terminer';
                $jumps[] = self::JUMP_END;
            }
            $properties = (object)array(
                'title'           => $page['title'],
                'contents_editor' => array('text' => $page['contents'], 'format' => FORMAT_HTML,
                    'itemid' => file_get_unused_draft_itemid()),
                'qtype'           => self::PAGE_CONTENT,
                'pageid'          => $previd,
                'layout'          => 1,
                'display'         => 1,
                'answer_editor'   => $answers,
                'jumpto'          => $jumps,
            );
            $created = \lesson_page::create($properties, $lesson, $context, $course->maxbytes ?: $CFG->maxbytes);
            $previd = (int)$created->id;
            $out[] = array('pageid' => $previd, 'title' => $page['title'], 'buttons' => $answers);
        }

        // Barre de progression et menu de gauche : utiles seulement sur plusieurs pages.
        $multi = $count > 1 ? 1 : 0;
        $DB->update_record('lesson', (object)array('id' => $lesson->id, 'progressbar' => $multi, 'displayleft' => $multi));
        rebuild_course_cache((int)$course->id, true);

        return array('cmid' => $cmid, 'replaced' => $existing, 'pages' => $out);
    }
}
