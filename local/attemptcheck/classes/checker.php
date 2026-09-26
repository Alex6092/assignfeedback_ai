<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Contrôle des tentatives d'un cours : collecte puis indicateurs, activité par
 * activité (la classe d'une activité sert de référence à ses tentatives).
 */
class checker {

    /**
     * @param \stdClass $course
     * @param int       $cmid           0 = tous les tests et devoirs du cours
     * @param bool      $restrictedonly hors créneau : activités restreintes seulement
     * @return \stdClass[] items (voir collector) avec ->signals et ->cm
     */
    public static function run(\stdClass $course, int $cmid = 0, bool $restrictedonly = true): array {
        $analyser = analyser::from_config();
        $out = array();
        foreach (collector::activities($course) as $id => $cm) {
            if ($cmid && $id !== $cmid) {
                continue;
            }
            $items = collector::collect($cm);
            if (!$items) {
                continue;
            }
            $restricted = !empty($cm->availability) && strpos($cm->availability, '"classhours"') !== false;
            $signals = $analyser->analyse($items, review::get_minduration($id),
                offslot::checker((int)$course->id, $restricted, $restrictedonly));
            foreach ($items as $item) {
                $item->signals = $signals[$item->key] ?? array();
                $item->cm = $cm;
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Indicateurs d'une seule tentative (notification après remise).
     *
     * @return \stdClass|null l'item, avec ->signals et ->cm
     */
    public static function run_item(\stdClass $course, int $cmid, string $key): ?\stdClass {
        foreach (self::run($course, $cmid, true) as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }
        return null;
    }
}
