<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Création de groupes depuis le formulaire de génération (surtout utile au
 * démarrage d'un projet : un groupe = une entreprise cliente).
 */
class group_helper {

    /** Longueur maximale d'un nom de groupe ({groups}.name). */
    const MAXNAME = 254;

    /**
     * Noms saisis, un par ligne : nettoyés, sans vide ni doublon.
     *
     * @param string $text
     * @return string[]
     */
    public static function parse_names(string $text): array {
        $names = array();
        foreach (preg_split('/\R/u', $text) as $line) {
            $name = trim(preg_replace('/\s+/u', ' ', $line));
            if ($name === '') {
                continue;
            }
            if (\core_text::strlen($name) > self::MAXNAME) {
                $name = \core_text::substr($name, 0, self::MAXNAME);
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Crée les groupes absents du cours. Un nom qui existe déjà n'est pas
     * recréé : son groupe est simplement ciblé.
     *
     * L'appelant vérifie moodle/course:managegroups (groups_create_group ne le
     * fait pas).
     *
     * @param int    $courseid
     * @param string $text un nom par ligne
     * @return array{ids:int[], created:int} groupes ciblés, nombre créés
     */
    public static function create_from_text(int $courseid, string $text): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $ids = array();
        $created = 0;
        foreach (self::parse_names($text) as $name) {
            $existing = groups_get_group_by_name($courseid, $name);
            if ($existing) {
                $ids[] = (int)$existing;
                continue;
            }
            $group = new \stdClass();
            $group->courseid = $courseid;
            $group->name     = $name;
            $ids[] = (int)groups_create_group($group);
            $created++;
        }
        return array('ids' => array_values(array_unique($ids)), 'created' => $created);
    }
}
