<?php
namespace local_aibridge\extension;

defined('MOODLE_INTERNAL') || die();

/**
 * Liste des adaptateurs connus. Seuls ceux dont le plugin est installé sont
 * utilisés.
 */
class registry {

    /** @var extension[]|null */
    private static $available = null;

    /** @return extension[] */
    public static function all(): array {
        return array(
            new aifeedback(),
            new aichat(),
            new efe(),
            new classhours(),
            new attemptcheck(),
            new opnsense(),
            new avancement(),
        );
    }

    /** @return extension[] adaptateurs dont le plugin est installé */
    public static function available(): array {
        if (self::$available === null) {
            self::$available = array();
            foreach (self::all() as $extension) {
                if ($extension->is_available()) {
                    self::$available[] = $extension;
                }
            }
        }
        return self::$available;
    }

    /**
     * Réglages de toutes les extensions pour une activité.
     *
     * @return array clé => réglages (seulement les extensions réglées)
     */
    public static function read_all(\cm_info $cm): array {
        $out = array();
        foreach (self::available() as $extension) {
            $data = $extension->read($cm);
            if ($data !== null) {
                $out[$extension->key()] = $data;
            }
        }
        return $out;
    }

    /**
     * Résumés d'une ligne, pour le plan du cours.
     *
     * @param array $all ce que read_all() a renvoyé
     * @return string[]
     */
    public static function summaries(array $all): array {
        $out = array();
        foreach (self::available() as $extension) {
            if (isset($all[$extension->key()])) {
                $summary = $extension->summary($all[$extension->key()]);
                if ($summary !== null && $summary !== '') {
                    $out[] = $summary;
                }
            }
        }
        return $out;
    }
}
