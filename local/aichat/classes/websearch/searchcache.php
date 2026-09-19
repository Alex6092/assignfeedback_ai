<?php
namespace local_aichat\websearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Cache des résultats de recherche Web, partagé par tout le site.
 *
 * Une classe qui travaille sur le même sujet pose souvent les mêmes requêtes :
 * une recherche servie par le cache est gratuite et instantanée. On ne stocke
 * que les résultats (données publiques), jamais la requête en clair ni quoi
 * que ce soit de l'élève : la clé est un hachage.
 *
 * Seules les recherches réussies sont mises en cache. La durée de validité est
 * vérifiée à la lecture (réglage websearch_cachedays, 0 = pas de cache).
 */
class searchcache {

    /**
     * Clé de cache : fournisseur + requête normalisée (casse et espaces) +
     * nombre de résultats demandés. Les opérateurs (site:, filetype:) font
     * partie de la requête et sont conservés.
     *
     * @param string $provider
     * @param string $query
     * @param int    $count
     * @return string
     */
    public static function key($provider, $query, $count) {
        $normalised = trim(preg_replace('/\s+/u', ' ', \core_text::strtolower((string)$query)));
        return sha1((string)$provider . '|' . $normalised . '|' . (int)$count);
    }

    /**
     * Résultats en cache encore valides, ou null.
     *
     * @param string $provider
     * @param string $query
     * @param int    $count
     * @return array|null {items: array[], time: int}
     */
    public static function get($provider, $query, $count) {
        $ttl = manager::cachedays() * DAYSECS;
        if ($ttl <= 0) {
            return null;
        }
        $entry = self::store()->get(self::key($provider, $query, $count));
        if (!is_array($entry) || !isset($entry['items'], $entry['time']) || !is_array($entry['items'])) {
            return null;
        }
        if ((int)$entry['time'] + $ttl < time()) {
            return null; // expiré : la prochaine recherche réussie le remplacera
        }
        return $entry;
    }

    /**
     * Met en cache les résultats d'une recherche réussie.
     *
     * @param string  $provider
     * @param string  $query
     * @param int     $count
     * @param array[] $items
     */
    public static function set($provider, $query, $count, array $items) {
        if (manager::cachedays() <= 0) {
            return;
        }
        self::store()->set(self::key($provider, $query, $count), array(
            'items' => array_values($items),
            'time'  => time(),
        ));
    }

    /** @return \cache */
    private static function store() {
        return \cache::make('local_aichat', 'searchcache');
    }
}
