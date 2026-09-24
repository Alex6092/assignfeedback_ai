<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Clics sur les résultats.
 *
 * Un lien de résultat ne porte JAMAIS l'URL : seulement le numéro de la
 * recherche et le rang du résultat, relus ici dans le journal de CET
 * utilisateur. Pas de redirection ouverte, pas de demande d'ouverture forgée
 * pour un site qui n'était pas dans ses résultats.
 */
class clicks {

    const TABLE = 'local_moodlesearch_click';

    /**
     * Résultat n°$rank d'une recherche de l'utilisateur, ou null.
     *
     * @return array|null {url, title, domain}
     */
    public static function result_of(int $userid, int $searchid, int $rank): ?array {
        global $DB;
        $search = $DB->get_record(searcher::TABLE, array('id' => $searchid, 'userid' => $userid),
            'id, results');
        if (!$search) {
            return null;
        }
        $results = json_decode((string)$search->results, true);
        if (!is_array($results) || !isset($results[$rank]['url'])) {
            return null;
        }
        $url = (string)$results[$rank]['url'];
        if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
            return null;
        }
        return array('url' => $url, 'title' => (string)($results[$rank]['title'] ?? ''),
            'domain' => (string)($results[$rank]['domain'] ?? searcher::domain($url)));
    }

    /** Enregistre un clic (statut OPNsense à venir). */
    public static function record(int $userid, int $searchid, int $rank, array $result): int {
        global $DB;
        return (int)$DB->insert_record(self::TABLE, (object)array(
            'searchid'    => $searchid,
            'userid'      => $userid,
            'resultrank'  => $rank,
            'url'         => $result['url'],
            'domain'      => \core_text::substr($result['domain'], 0, 255),
            'opnstatus'   => '',
            'timecreated' => time(),
        ));
    }

    /** Clic de l'utilisateur, ou null. */
    public static function get(int $clickid, int $userid): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, array('id' => $clickid, 'userid' => $userid));
        return $row ?: null;
    }

    /** Suite donnée par OPNsense. */
    public static function set_status(int $clickid, string $status): void {
        global $DB;
        $DB->set_field(self::TABLE, 'opnstatus', substr($status, 0, 20), array('id' => $clickid));
    }
}
