<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Pont OPTIONNEL vers le Tuteur IA (local_aichat).
 *
 * Les devoirs des missions sont créés par INSERT direct : ni le formulaire du
 * tuteur, ni son observateur de création ne s'exécutent. On le configure donc
 * ici, explicitement. No-op silencieux si local_aichat n'est pas installé.
 */
class aichat_bridge {

    /** Recherches du tuteur (valeurs de local_aichat_activity.websearch). */
    const SEARCH_NONE     = 0;
    const SEARCH_WEB      = 1;
    const SEARCH_MATERIAL = 2;

    public static function is_available(): bool {
        return class_exists('\local_aichat\activity') && class_exists('\local_aichat\brief');
    }

    /**
     * Active le tuteur sur un devoir, puis met son brief en file.
     *
     * La consigne et le brief sont transmis au tuteur. Le brief est fabriqué à
     * partir de l'énoncé et du corrigé de la Correction IA : à appeler APRÈS
     * leur écriture.
     *
     * @param int $cmid
     * @param int $courseid
     * @param int $search SEARCH_NONE | SEARCH_WEB | SEARCH_MATERIAL
     * @return bool false si le tuteur n'est pas installé
     */
    public static function configure(int $cmid, int $courseid, int $search = self::SEARCH_NONE): bool {
        if (!self::is_available() || $cmid <= 0) {
            return false;
        }
        \local_aichat\activity::save($cmid, $courseid, array(
            'enabled'      => 1,
            'includeintro' => 1,
            'includebrief' => 1,
            'websearch'    => max(self::SEARCH_NONE, min(self::SEARCH_MATERIAL, $search)),
        ));
        self::refresh_brief($cmid);
        return true;
    }

    /**
     * Remet le brief en file s'il ne correspond plus au corrigé (ou s'il
     * manque). Sans effet si le tuteur n'est pas activé sur ce devoir.
     */
    public static function refresh_brief(int $cmid): void {
        if (!self::is_available() || $cmid <= 0) {
            return;
        }
        try {
            \local_aichat\brief::schedule_if_stale($cmid);
        } catch (\Throwable $e) {
            debugging('local_aimissions : brief du tuteur non mis en file — ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }

    /**
     * Réglages du tuteur d'un devoir existant (copie et adaptation d'un sprint).
     *
     * @return array{aichat:int, aichatsearch:int}
     */
    public static function settings_of(int $cmid): array {
        $off = array('aichat' => 0, 'aichatsearch' => self::SEARCH_NONE);
        if (!self::is_available() || $cmid <= 0) {
            return $off;
        }
        $config = \local_aichat\activity::get($cmid);
        if ($config === null || empty($config->enabled)) {
            return $off;
        }
        return array('aichat' => 1, 'aichatsearch' => (int)($config->websearch ?? self::SEARCH_NONE));
    }

    /** Le tuteur est-il activé sur ce devoir ? */
    public static function is_enabled_on(int $cmid): bool {
        return !empty(self::settings_of($cmid)['aichat']);
    }
}
