<?php
namespace local_aibridge;

defined('MOODLE_INTERNAL') || die();

/**
 * Petits outils communs aux lecteurs : JSON, dates, tables présentes,
 * conditions d'accès.
 */
class util {

    /** @var array nom de table => bool */
    private static $tables = array();

    /**
     * Encode la réponse d'une fonction du pont, secrets masqués.
     *
     * @param mixed $data
     */
    public static function encode($data): string {
        $json = json_encode(redactor::clean($data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \moodle_exception('error_json', 'local_aibridge', '', json_last_error_msg());
        }
        return $json;
    }

    /** La table existe-t-elle ? (plugin optionnel installé) */
    public static function table_exists(string $table): bool {
        global $DB;
        if (!array_key_exists($table, self::$tables)) {
            self::$tables[$table] = $DB->get_manager()->table_exists($table);
        }
        return self::$tables[$table];
    }

    /** Texte formaté par Moodle (format_string : « &amp; ») ramené en texte brut. */
    public static function plain(string $text): string {
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Date lisible (ISO 8601, fuseau du serveur), null si 0. */
    public static function date($timestamp): ?string {
        $timestamp = (int)$timestamp;
        if ($timestamp <= 0) {
            return null;
        }
        return date('c', $timestamp);
    }

    /**
     * Arbre de conditions d'accès décodé, avec le nom des activités citées
     * (conditions d'achèvement, verrous d'activité surveillée).
     *
     * @param string|null $json course_modules.availability ou course_sections.availability
     * @param \course_modinfo|null $modinfo pour résoudre les cmid en noms
     * @return array|null
     */
    public static function availability(?string $json, ?\course_modinfo $modinfo = null): ?array {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $tree = json_decode($json, true);
        if (!is_array($tree)) {
            return array('_raw' => $json);
        }
        return self::annotate_tree($tree, $modinfo);
    }

    /**
     * Ajoute « _cmname » à côté des cmid cités par une condition.
     *
     * @param array $node
     * @param \course_modinfo|null $modinfo
     */
    private static function annotate_tree(array $node, ?\course_modinfo $modinfo): array {
        foreach (array('cm', 'lock') as $field) {
            if ($modinfo && isset($node[$field]) && is_numeric($node[$field]) && (int)$node[$field] > 0) {
                $cms = $modinfo->get_cms();
                $cmid = (int)$node[$field];
                $node['_' . $field . 'name'] = isset($cms[$cmid]) ? $cms[$cmid]->name : null;
            }
        }
        if (isset($node['c']) && is_array($node['c'])) {
            foreach ($node['c'] as $i => $child) {
                if (is_array($child)) {
                    $node['c'][$i] = self::annotate_tree($child, $modinfo);
                }
            }
        }
        return $node;
    }

    /**
     * Conditions de premier niveau d'un arbre d'accès (racine « & »), au
     * format des tableaux décodés.
     *
     * @param string|null $json
     * @return array[]
     */
    public static function root_conditions(?string $json): array {
        if ($json === null || trim($json) === '') {
            return array();
        }
        $tree = json_decode($json, true);
        if (!is_array($tree) || ($tree['op'] ?? '') !== '&' || !isset($tree['c']) || !is_array($tree['c'])) {
            return array();
        }
        return array_values(array_filter($tree['c'], 'is_array'));
    }

    /**
     * Ligne de table sans les colonnes techniques ; null si absente.
     *
     * @param \stdClass|false|null $row
     * @param string[] $drop colonnes à retirer
     */
    public static function row($row, array $drop = array('id')): ?array {
        if (!$row) {
            return null;
        }
        $row = (array)$row;
        foreach ($drop as $field) {
            unset($row[$field]);
        }
        return $row;
    }

    /**
     * Fichiers d'une zone (sans les dossiers), pour signaler les images et
     * pièces jointes d'un contenu.
     *
     * @return array[] {filepath, filename, filesize, mimetype}
     */
    public static function files(int $contextid, string $component, string $filearea, $itemid = false): array {
        $fs = get_file_storage();
        $out = array();
        foreach ($fs->get_area_files($contextid, $component, $filearea, $itemid, 'itemid, filepath, filename', false) as $file) {
            $out[] = array(
                'itemid'   => (int)$file->get_itemid(),
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
                'filesize' => (int)$file->get_filesize(),
                'mimetype' => $file->get_mimetype(),
            );
        }
        return $out;
    }
}
