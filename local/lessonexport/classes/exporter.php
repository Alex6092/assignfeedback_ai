<?php
namespace local_lessonexport;

defined('MOODLE_INTERNAL') || die();

use html_writer;

/**
 * Rendu imprimable d'une leçon : toutes ses pages, dans l'ordre, avec
 * exactement le balisage de mod_lesson (boîte « contents », puis
 * format_text(..., overflowdiv) qui ajoute « no-overflow »). Les classes du
 * « Code SCSS initial » du thème (.objectif, .astuce, .carte…), qui visent
 * « .contents .no-overflow », s'appliquent donc à l'identique.
 */
class exporter {

    /** Pages sans contenu à imprimer (fin d'embranchement, grappes). */
    const STRUCTURAL_PAGES = array(21, 30, 31);
    /** Page de contenu (les autres types sont des questions). */
    const CONTENT_PAGE = 20;

    /**
     * Leçons du cours que l'utilisateur peut imprimer, dans l'ordre du cours.
     *
     * @return \cm_info[]
     */
    public static function lessons(\course_modinfo $modinfo): array {
        global $DB;
        $out = array();
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname !== 'lesson' || !$cm->uservisible) {
                continue;
            }
            $lesson = $DB->get_record('lesson', array('id' => $cm->instance));
            if ($lesson && self::denied_reason($cm, $lesson) === null) {
                $out[] = $cm;
            }
        }
        return $out;
    }

    /**
     * Pourquoi l'utilisateur ne peut pas imprimer cette leçon (clé de chaîne),
     * ou null s'il le peut. Mêmes règles que la leçon elle-même : activité
     * visible et accessible, pas de mot de passe, dans ses dates d'ouverture.
     * Les enseignants qui gèrent la leçon passent outre.
     */
    public static function denied_reason(\cm_info $cm, \stdClass $lesson): ?string {
        $context = \context_module::instance($cm->id);
        if (has_capability('mod/lesson:manage', $context)) {
            return null;
        }
        if (!$cm->uservisible || !has_capability('mod/lesson:view', $context)) {
            return 'denied_notavailable';
        }
        if (!empty($lesson->usepassword)) {
            return 'denied_password';
        }
        $now = time();
        if ((!empty($lesson->available) && $lesson->available > $now) || (!empty($lesson->deadline) && $lesson->deadline < $now)) {
            return 'denied_closed';
        }
        return null;
    }

    /**
     * Pages de la leçon dans l'ordre de lecture.
     *
     * @return array[] {title, html, question (bool)}
     */
    public static function pages(\cm_info $cm, \stdClass $lesson): array {
        global $DB;
        $context = \context_module::instance($cm->id);
        $pages = $DB->get_records('lesson_pages', array('lessonid' => $lesson->id));
        $first = null;
        foreach ($pages as $page) {
            if ((int)$page->prevpageid === 0) {
                $first = $page;
                break;
            }
        }
        $out = array();
        $seen = array();
        for ($page = $first; $page && !isset($seen[$page->id]); $page = $pages[$page->nextpageid] ?? null) {
            $seen[$page->id] = true;
            if (in_array((int)$page->qtype, self::STRUCTURAL_PAGES, true)) {
                continue;
            }
            $html = '';
            if (trim((string)$page->contents) !== '') {
                $text = file_rewrite_pluginfile_urls($page->contents, 'pluginfile.php', $context->id, 'mod_lesson',
                    'page_contents', $page->id);
                $html = format_text($text, $page->contentsformat ?? FORMAT_HTML,
                    array('context' => $context, 'noclean' => true, 'overflowdiv' => true));
            }
            $out[] = array(
                'title'    => format_string($page->title, true, array('context' => $context)),
                'html'     => $html,
                'question' => (int)$page->qtype !== self::CONTENT_PAGE,
            );
        }
        return $out;
    }

    /**
     * Une leçon, prête à imprimer. Le titre de chaque page n'est affiché que
     * si son contenu n'a pas déjà son propre titre <h1> (cas des leçons du
     * skill moodle-ciel, qui commencent par un <h1>).
     */
    public static function render_lesson(\cm_info $cm, \stdClass $lesson): string {
        $context = \context_module::instance($cm->id);
        $course = $cm->get_course();
        $modinfo = $cm->get_modinfo();
        $section = $modinfo->get_section_info($cm->sectionnum);

        $out = html_writer::start_tag('section', array('class' => 'lessonexport-lesson'));
        $out .= html_writer::div(format_string($course->fullname, true, array('context' => $context))
            . ' · ' . get_section_name($course, $section), 'lessonexport-meta');
        $out .= html_writer::tag('h2', format_string($cm->name, true, array('context' => $context)),
            array('class' => 'lessonexport-name'));
        foreach (self::pages($cm, $lesson) as $page) {
            $out .= html_writer::start_div('lessonexport-page' . ($page['question'] ? ' lessonexport-question' : ''));
            if (!preg_match('/<h1[\s>]/i', $page['html'])) {
                $out .= html_writer::tag('h3', $page['title'], array('class' => 'lessonexport-pagetitle'));
            }
            if ($page['question']) {
                $out .= html_writer::div(get_string('question_note', 'local_lessonexport'), 'lessonexport-note');
            }
            // Même boîte que mod_lesson : $OUTPUT->box(..., 'contents').
            $out .= html_writer::div($page['html'], 'box py-3 contents');
            $out .= html_writer::end_div();
        }
        $out .= html_writer::end_tag('section');
        return $out;
    }

    /** Barre d'outils écran (jamais imprimée) : imprimer, revenir. */
    public static function toolbar(\moodle_url $back, string $backlabel): string {
        global $PAGE;
        $PAGE->requires->js_init_code(
            "document.querySelectorAll('.lessonexport-print').forEach(function(b){"
            . "b.addEventListener('click', function(){ window.print(); });});");
        return html_writer::div(
            html_writer::tag('button', '🖨 ' . get_string('print', 'local_lessonexport'),
                array('type' => 'button', 'class' => 'btn btn-primary lessonexport-print'))
            . html_writer::link($back, $backlabel, array('class' => 'btn btn-secondary'))
            . html_writer::span(get_string('print_help', 'local_lessonexport'), 'lessonexport-help'),
            'lessonexport-toolbar');
    }
}
