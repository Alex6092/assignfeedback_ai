<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Cache des résultats de recherche Web, partagé par tout le site et par
 * tous les moteurs.
 *
 * Une classe qui travaille sur le même sujet pose souvent les mêmes requêtes :
 * une recherche servie par le cache est gratuite et instantanée, et ne
 * consomme la clé de personne. Un résultat obtenu avec la clé Brave d'un élève
 * sert donc aussi à un élève qui utilise Tavily, et inversement (les résultats
 * sont au même format). On ne stocke que les résultats (données publiques) et
 * le nom du moteur, jamais la requête en clair ni quoi que ce soit de l'élève :
 * la clé de cache est un hachage.
 *
 * Seules les recherches réussies sont mises en cache. La durée de validité est
 * vérifiée à la lecture (réglage websearch_cachedays, 0 = pas de cache).
 */
class searchcache {

    /**
     * Clé de cache : requête normalisée (casse et espaces) + nombre de
     * résultats demandés. Les opérateurs (site:, filetype:) font partie de la
     * requête et sont conservés.
     *
     * @param string $query
     * @param int    $count
     * @return string
     */
    public static function key($query, $count) {
        $normalised = trim(preg_replace('/\s+/u', ' ', \core_text::strtolower((string)$query)));
        return sha1($normalised . '|' . (int)$count);
    }

    /**
     * Résultats en cache encore valides, ou null.
     *
     * @param string $query
     * @param int    $count
     * @return array|null {items: array[], time: int, source: string}
     */
    public static function get($query, $count) {
        $ttl = manager::cachedays() * DAYSECS;
        if ($ttl <= 0) {
            return null;
        }
        $entry = self::store()->get(self::key($query, $count));
        if (!is_array($entry) || !isset($entry['items'], $entry['time']) || !is_array($entry['items'])) {
            return null;
        }
        if ((int)$entry['time'] + $ttl < time()) {
            return null; // expiré : la prochaine recherche réussie le remplacera
        }
        $entry['source'] = isset($entry['source']) ? (string)$entry['source'] : '';
        return $entry;
    }

    /**
     * Met en cache les résultats d'une recherche réussie.
     *
     * @param string  $query
     * @param int     $count
     * @param array[] $items
     * @param string  $source moteur qui a fourni les résultats
     */
    public static function set($query, $count, array $items, $source) {
        if (manager::cachedays() <= 0) {
            return;
        }
        self::store()->set(self::key($query, $count), array(
            'items'  => array_values($items),
            'time'   => time(),
            'source' => (string)$source,
        ));
    }

    /** @return \cache */
    private static function store() {
        return \cache::make('local_aichat', 'searchcache');
    }
}
