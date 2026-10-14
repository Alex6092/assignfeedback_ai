<?php
namespace local_aibridge\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Pages de contenu d'une leçon créée par le pont. Les pages sont chaînées
 * comme dans les parcours de l'enseignant : « Suivant » sur la première,
 * « Précédent » / « Suivant » au milieu, « Précédent » / « Terminer » sur la
 * dernière ; une leçon d'une page n'a que « Terminer ».
 *
 * Les images citées en relatif par une page (src="images/schema.png") sont
 * rangées dans la zone de fichiers de cette page (mod_lesson, page_contents,
 * itemid = id de la page), et leur src devient « @@PLUGINFILE@@/schema.png ».
 */
class lesson_writer {

    /** Page de contenu (LESSON_PAGE_BRANCHTABLE). */
    const PAGE_CONTENT = 20;
    /** Sauts : page suivante, page précédente, fin de la leçon. */
    const JUMP_NEXT = -1;
    const JUMP_PREVIOUS = -40;
    const JUMP_END = -9;

    /** Images admises : extension => type reconnu par getimagesize (null : SVG, contrôlé à part). */
    const IMAGE_TYPES = array('png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG,
        'gif' => IMAGETYPE_GIF, 'svg' => null);
    /** Nom d'une image : lettres, chiffres, point, tiret, tiret bas, et une extension admise. */
    const IMAGE_NAME = '/^[\p{L}\p{N}][\p{L}\p{N}._-]{0,99}\.(png|jpe?g|gif|svg)$/iu';
    /** Limites d'un appel : une image, toutes les images (la requête, en base64, reste sous 8 Mo), nombre. */
    const MAX_IMAGE_BYTES = 2097152;
    const MAX_IMAGES_BYTES = 4194304;
    const MAX_IMAGES = 40;

    /**
     * Remplace toutes les pages de la leçon. Une image citée par deux pages
     * est rangée dans chacune des deux.
     *
     * @param int     $cmid   leçon brouillon du pont
     * @param array[] $pages  [{title, contents (HTML)}], dans l'ordre
     * @param bool    $dryrun
     * @param array[] $files  images citées par les pages : [{filename, content (base64)}]
     * @return array
     */
    public static function set_pages(int $cmid, array $pages, bool $dryrun, array $files = array()): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        list($course, $cm) = guard::require_draft_cm($cmid, 'lesson');
        if (!$pages) {
            throw new \moodle_exception('error_lessonpages', 'local_aibridge');
        }
        $images = self::clean_images($files, (int)get_max_upload_file_size($CFG->maxbytes, $course->maxbytes));
        $names = array_map('strval', array_keys($images));
        $warnings = array();
        $cited = array();
        $clean = array();
        foreach (array_values($pages) as $i => $page) {
            $title = trim((string)($page['title'] ?? ''));
            $contents = (string)($page['contents'] ?? '');
            if ($title === '' || trim($contents) === '') {
                throw new \moodle_exception('error_lessonpage', 'local_aibridge', '', $i + 1);
            }
            $used = array();
            $missing = array();
            $contents = self::rewrite_images($contents, $names, $used, $missing);
            foreach (array_unique($missing) as $src) {
                $warnings[] = 'Page ' . ($i + 1) . " : image « $src » non envoyée, laissée telle quelle (à déposer à la main).";
            }
            $cited += $used;
            $clean[] = array('title' => $title, 'contents' => $contents, 'images' => array_keys($used));
        }
        foreach (array_keys(array_diff_key($images, $cited)) as $name) {
            $warnings[] = "Image « $name » envoyée, mais citée par aucune page : ignorée.";
        }
        $existing = $DB->count_records('lesson_pages', array('lessonid' => $cm->instance));
        if ($dryrun) {
            return array('dryrun' => true, 'cmid' => $cmid, 'replaced' => $existing,
                'pages' => array_map(function($p) {
                    return array('title' => $p['title'], 'length' => \core_text::strlen($p['contents']), 'images' => $p['images']);
                }, $clean),
                'images' => count($cited), 'warnings' => $warnings);
        }

        $lesson = \lesson::load((int)$cm->instance);
        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        foreach ($DB->get_records('lesson_pages', array('lessonid' => $lesson->id), '', 'id') as $old) {
            \lesson_page::load((int)$old->id, $lesson)->delete();
            $fs->delete_area_files($context->id, 'mod_lesson', 'page_contents', (int)$old->id);
        }

        $count = count($clean);
        $previd = 0;
        $stored = 0;
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
            // Après create() : l'enregistrement de l'éditeur (zone de brouillon vide) viderait la zone de la page.
            foreach ($page['images'] as $name) {
                $fs->create_file_from_string(array(
                    'contextid' => $context->id,
                    'component' => 'mod_lesson',
                    'filearea'  => 'page_contents',
                    'itemid'    => $previd,
                    'filepath'  => '/',
                    'filename'  => $name,
                    'userid'    => (int)$USER->id,
                ), $images[$name]);
                $stored++;
            }
            $out[] = array('pageid' => $previd, 'title' => $page['title'], 'buttons' => $answers, 'images' => $page['images']);
        }

        // Barre de progression et menu de gauche : utiles seulement sur plusieurs pages.
        $multi = $count > 1 ? 1 : 0;
        $DB->update_record('lesson', (object)array('id' => $lesson->id, 'progressbar' => $multi, 'displayleft' => $multi));
        rebuild_course_cache((int)$course->id, true);

        return array('cmid' => $cmid, 'replaced' => $existing, 'pages' => $out, 'images' => $stored, 'warnings' => $warnings);
    }

    /**
     * Remplace les src relatifs des images d'une page par
     * « @@PLUGINFILE@@/nom » quand l'image a été envoyée ; le nom est le
     * dernier segment du chemin (images/schema.png → schema.png). Les
     * commentaires HTML et les src absolus (http:, data:, /…, @@PLUGINFILE@@)
     * restent tels quels.
     *
     * @param string   $html
     * @param string[] $names   images envoyées
     * @param bool[]   $used    (sortie) nom => true pour chaque image envoyée citée par la page
     * @param string[] $missing (sortie) src relatifs dont l'image n'a pas été envoyée
     * @return string
     */
    public static function rewrite_images(string $html, array $names, array &$used, array &$missing): string {
        $src = function(array $m) use ($names, &$used, &$missing): string {
            $value = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($value === '' || preg_match('~^([a-z][a-z0-9+.-]*:|/|#|@@)~i', $value)) {
                return $m[0];
            }
            $segments = explode('/', str_replace('\\', '/', rawurldecode(preg_replace('/[?#].*$/s', '', $value))));
            $name = end($segments);
            if (!in_array($name, $names, true)) {
                $missing[] = $value;
                return $m[0];
            }
            $used[$name] = true;
            return $m[1] . $m[2] . '@@PLUGINFILE@@/' . rawurlencode($name) . $m[2];
        };
        $out = preg_replace_callback('/<!--.*?-->|<img\b[^>]*>/is', function(array $m) use ($src): string {
            if (strncmp($m[0], '<!--', 4) === 0) {
                return $m[0];
            }
            return preg_replace_callback('/(\ssrc\s*=\s*)(["\'])(.*?)\2/is', $src, $m[0], 1);
        }, $html);
        if ($out === null) {
            throw new \coding_exception('rewrite_images : ' . preg_last_error_msg());
        }
        return $out;
    }

    /**
     * Images envoyées avec les pages, décodées et contrôlées : nom sûr et
     * unique, base64 valide, contenu conforme à l'extension, poids bornés.
     *
     * @param array[] $files    [{filename, content (base64)}]
     * @param int     $maxbytes poids maximal d'un fichier dans ce cours (site, cours, PHP)
     * @return string[] nom => contenu
     */
    private static function clean_images(array $files, int $maxbytes): array {
        if (count($files) > self::MAX_IMAGES) {
            throw new \moodle_exception('error_lessonimagecount', 'local_aibridge', '', self::MAX_IMAGES);
        }
        $limit = $maxbytes > 0 ? min($maxbytes, self::MAX_IMAGE_BYTES) : self::MAX_IMAGE_BYTES;
        $images = array();
        $total = 0;
        foreach (array_values($files) as $file) {
            if (!is_array($file)) {
                throw new \moodle_exception('error_lessonimages', 'local_aibridge');
            }
            $name = (string)($file['filename'] ?? '');
            if (!preg_match(self::IMAGE_NAME, $name) || clean_param($name, PARAM_FILE) !== $name) {
                throw new \moodle_exception('error_lessonimagename', 'local_aibridge', '', s(\core_text::substr($name, 0, 120)));
            }
            if (isset($images[$name])) {
                throw new \moodle_exception('error_lessonimagetwice', 'local_aibridge', '', $name);
            }
            $bytes = base64_decode((string)($file['content'] ?? ''), true);
            if ($bytes === false || $bytes === '') {
                throw new \moodle_exception('error_lessonimagecontent', 'local_aibridge', '', $name);
            }
            if (strlen($bytes) > $limit) {
                throw new \moodle_exception('error_lessonimagesize', 'local_aibridge', '',
                    (object)array('name' => $name, 'size' => display_size(strlen($bytes)), 'max' => display_size($limit)));
            }
            self::check_image($name, $bytes);
            $total += strlen($bytes);
            $images[$name] = $bytes;
        }
        if ($total > self::MAX_IMAGES_BYTES) {
            throw new \moodle_exception('error_lessonimagestotal', 'local_aibridge', '',
                (object)array('size' => display_size($total), 'max' => display_size(self::MAX_IMAGES_BYTES)));
        }
        return $images;
    }

    /** Le contenu est-il l'image annoncée par l'extension ? Un SVG ne doit rien contenir d'exécutable. */
    private static function check_image(string $name, string $bytes): void {
        $type = self::IMAGE_TYPES[strtolower(substr(strrchr($name, '.'), 1))];
        if ($type !== null) {
            $info = @getimagesizefromstring($bytes);
            if (!$info || (int)$info[2] !== $type) {
                throw new \moodle_exception('error_lessonimagecontent', 'local_aibridge', '', $name);
            }
            return;
        }
        if (!mb_check_encoding($bytes, 'UTF-8') || !preg_match('/<svg[\s>]/i', $bytes)) {
            throw new \moodle_exception('error_lessonimagecontent', 'local_aibridge', '', $name);
        }
        if (preg_match('/<(script|foreignObject|iframe|embed|object)[\s>\/]|<!ENTITY|\son[a-z]+\s*=|javascript:/i', $bytes)) {
            throw new \moodle_exception('error_lessonimagesvg', 'local_aibridge', '', $name);
        }
    }
}
