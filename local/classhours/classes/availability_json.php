<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Pose ou retire la condition « Pendant les heures de cours » dans le JSON de
 * restriction d'accès d'une activité (course_modules.availability).
 *
 * On ne touche qu'à la RACINE de l'arbre : c'est là que la page Heures de cours
 * et l'option EFE posent la condition, et c'est la seule position où elle
 * s'ajoute sans ambiguïté aux autres restrictions (« toutes » les conditions).
 * Une condition placée à la main plus profondément (dans un « OU ») n'est ni
 * vue ni modifiée.
 *
 * Forme de la condition : {"type":"classhours"}, plus "efe":1 quand elle a été
 * posée par l'option EFE. Seules ces dernières sont retirées automatiquement.
 *
 * Ce code manipule le JSON sans passer par \core_availability\tree : il reste
 * utilisable même si la condition est désactivée, et il ne dépend d'aucune
 * autre condition installée.
 *
 * Le même code sert à la condition « Activité surveillée » (type supervised,
 * voir gate) : chaque méthode prend le type en dernier paramètre, Heures de
 * cours par défaut.
 */
class availability_json {

    /** Type de la condition (availability_classhours). */
    const TYPE = 'classhours';

    /**
     * La condition est-elle installée ET activée ? Sinon, une activité qui la
     * porte ne s'afficherait plus : on n'en pose jamais.
     *
     * @param string $type classhours ou supervised
     */
    public static function condition_enabled(string $type = self::TYPE): bool {
        if (!class_exists('\availability_' . $type . '\condition')) {
            return false;
        }
        $enabled = \core_plugin_manager::instance()->get_enabled_plugins('availability');
        return is_array($enabled) && array_key_exists($type, $enabled);
    }

    /**
     * @param string|null $json
     * @return \stdClass|null arbre racine, null si pas de restriction (ou illisible)
     */
    public static function decode(?string $json): ?\stdClass {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $tree = json_decode($json);
        if (!is_object($tree) || !isset($tree->op) || !isset($tree->c) || !is_array($tree->c)) {
            return null;
        }
        return $tree;
    }

    /**
     * La racine porte-t-elle une condition Heures de cours ?
     *
     * @param string|null $json
     * @param bool|null   $efe  true : posée par l'option EFE ; false : posée à
     *                          la main ; null : l'une ou l'autre
     * @param string      $type type de la condition
     */
    public static function has_root_condition(?string $json, ?bool $efe = null, string $type = self::TYPE): bool {
        $tree = self::decode($json);
        if (!$tree || $tree->op !== '&') {
            return false;
        }
        foreach ($tree->c as $child) {
            if (self::matches($child, $efe, $type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ajoute la condition à la racine (« toutes » les conditions, affichée
     * grisée avec les horaires). Une racine d'un autre type (OU, NON) est
     * enveloppée dans un « ET » pour que la restriction existante reste entière.
     *
     * @param string|null $json
     * @param bool        $efe marquer la condition comme posée par l'option EFE
     * @param string      $type type de la condition
     * @return string nouveau JSON
     */
    public static function add(?string $json, bool $efe, string $type = self::TYPE): string {
        $child = (object)array('type' => $type);
        if ($efe) {
            $child->efe = 1;
        }
        return self::add_child($json, $child);
    }

    /**
     * Conditions de la racine d'un arbre « toutes les conditions » ([] sinon).
     *
     * @param string|null $json
     * @return \stdClass[]
     */
    public static function root_children(?string $json): array {
        $tree = self::decode($json);
        if (!$tree || $tree->op !== '&') {
            return array();
        }
        return array_values(array_filter($tree->c, 'is_object'));
    }

    /**
     * Ajoute une condition quelconque à la racine (voir add()).
     *
     * @param string|null $json
     * @param \stdClass   $child la condition, ex. {"type":"supervised","lock":12}
     * @return string nouveau JSON
     */
    public static function add_child(?string $json, \stdClass $child): string {
        $tree = self::decode($json);
        if (!$tree) {
            return json_encode((object)array('op' => '&', 'c' => array($child), 'showc' => array(true)));
        }

        if ($tree->op === '&') {
            $showc = (isset($tree->showc) && is_array($tree->showc))
                ? array_values($tree->showc) : array_fill(0, count($tree->c), true);
            $tree->c = array_values($tree->c);
            $tree->c[] = $child;
            $showc[] = true;
            $tree->showc = $showc;
            return json_encode($tree);
        }

        // Racine OU / NON-ET (option « show » unique) ou NON-OU (showc) :
        // l'ancien arbre devient un sous-arbre, qui ne porte pas d'option
        // d'affichage ; on reprend la sienne pour sa position dans le ET.
        if (isset($tree->showc) && is_array($tree->showc)) {
            $oldshow = !in_array(false, $tree->showc, true);
        } else {
            $oldshow = !isset($tree->show) || (bool)$tree->show;
        }
        $nested = clone $tree;
        unset($nested->show, $nested->showc);
        return json_encode((object)array(
            'op'    => '&',
            'c'     => array($child, $nested),
            'showc' => array(true, $oldshow),
        ));
    }

    /**
     * Retire les conditions Heures de cours de la racine.
     *
     * @param string|null $json
     * @param bool|null   $efe  true : celles de l'option EFE ; false : celles
     *                          posées à la main ; null : toutes
     * @param string      $type type de la condition
     * @return string|null nouveau JSON, null s'il ne reste aucune restriction
     */
    public static function remove(?string $json, ?bool $efe, string $type = self::TYPE): ?string {
        return self::remove_where($json, function($child) use ($efe, $type) {
            return self::matches($child, $efe, $type);
        });
    }

    /**
     * Retire de la racine les conditions qui satisfont $match.
     *
     * @param string|null $json
     * @param callable    $match fonction(\stdClass $child): bool
     * @return string|null nouveau JSON, null s'il ne reste aucune restriction
     */
    public static function remove_where(?string $json, callable $match): ?string {
        $tree = self::decode($json);
        if (!$tree) {
            return ($json === null || trim($json) === '') ? null : $json;
        }
        if ($tree->op !== '&') {
            return $json;
        }
        $hasshowc = isset($tree->showc) && is_array($tree->showc);
        $children = array();
        $showc = array();
        foreach (array_values($tree->c) as $index => $child) {
            if (is_object($child) && $match($child)) {
                continue;
            }
            $children[] = $child;
            if ($hasshowc) {
                $showc[] = $tree->showc[$index] ?? true;
            }
        }
        if (!$children) {
            return null;
        }
        $tree->c = $children;
        if ($hasshowc) {
            $tree->showc = $showc;
        }
        return json_encode($tree);
    }

    /**
     * Enregistre la restriction d'une activité et invalide son cache. Appeler
     * {@see rebuild()} une fois toutes les activités du cours écrites.
     */
    public static function store(int $courseid, int $cmid, ?string $json): void {
        global $DB;
        $DB->set_field('course_modules', 'availability', $json, array('id' => $cmid));
        \course_modinfo::purge_course_module_cache($courseid, $cmid);
    }

    /** Reconstruit le cache du cours après des {@see store()}. */
    public static function rebuild(int $courseid): void {
        rebuild_course_cache($courseid, false, true);
    }

    /** Un enfant de l'arbre est-il une condition de ce type (et du bon genre) ? */
    private static function matches($child, ?bool $efe, string $type): bool {
        if (!is_object($child) || !isset($child->type) || $child->type !== $type) {
            return false;
        }
        if ($efe === null) {
            return true;
        }
        return $efe === !empty($child->efe);
    }
}
