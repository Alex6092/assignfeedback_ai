<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Accès au journal standard de Moodle (logstore_standard), s'il est activé.
 *
 * Le journal sert à affiner les mesures : premier accès à un devoir, heure de
 * remise, nombre de mots à chaque enregistrement d'un texte en ligne, arrivée
 * sur une page de test. Sans lui, on se replie sur les dates des tables des
 * activités (mesures plus grossières).
 */
class logs {

    /** @var string|null|false table du journal (false : pas encore cherchée) */
    private static $table = false;

    /** Nom de la table du journal interne, ou null si aucun journal lisible en SQL. */
    public static function table(): ?string {
        if (self::$table === false) {
            self::$table = null;
            try {
                $readers = get_log_manager()->get_readers('\core\log\sql_internal_table_reader');
                foreach ($readers as $reader) {
                    self::$table = $reader->get_internal_log_table_name();
                    break;
                }
            } catch (\Throwable $e) {
                self::$table = null;
            }
        }
        return self::$table;
    }

    /**
     * Événements d'une activité (lus dans l'ordre chronologique).
     *
     * @param int      $cmid
     * @param string[] $eventnames
     * @param int[]    $objectids restreindre à ces objets (vide : tous)
     * @return \stdClass[] userid, objectid, eventname, other (décodé), timecreated
     */
    public static function events(int $cmid, array $eventnames, array $objectids = array()): array {
        global $DB;
        $table = self::table();
        if ($table === null || !$eventnames) {
            return array();
        }
        list($esql, $params) = $DB->get_in_or_equal($eventnames, SQL_PARAMS_NAMED, 'ev');
        $where = "contextlevel = :lvl AND contextinstanceid = :cmid AND eventname $esql";
        $params['lvl'] = CONTEXT_MODULE;
        $params['cmid'] = $cmid;
        if ($objectids) {
            list($osql, $oparams) = $DB->get_in_or_equal($objectids, SQL_PARAMS_NAMED, 'obj');
            $where .= " AND objectid $osql";
            $params += $oparams;
        }
        $events = array();
        $rs = $DB->get_recordset_sql("SELECT id, userid, objectid, eventname, other, timecreated
                                        FROM {" . $table . "}
                                       WHERE $where
                                    ORDER BY timecreated, id", $params);
        foreach ($rs as $row) {
            $row->other = self::decode_other($row->other);
            $events[] = $row;
        }
        $rs->close();
        return $events;
    }

    /**
     * Premier (MIN) ou dernier (MAX) instant d'un événement, par utilisateur.
     *
     * @param int    $cmid
     * @param string $eventname
     * @param string $aggregate MIN|MAX
     * @return int[] userid => instant
     */
    public static function per_user(int $cmid, string $eventname, string $aggregate): array {
        global $DB;
        $table = self::table();
        if ($table === null) {
            return array();
        }
        $aggregate = ($aggregate === 'MAX') ? 'MAX' : 'MIN';
        $rows = $DB->get_records_sql_menu("SELECT userid, $aggregate(timecreated)
                                             FROM {" . $table . "}
                                            WHERE contextlevel = :lvl AND contextinstanceid = :cmid
                                              AND eventname = :ev
                                         GROUP BY userid",
            array('lvl' => CONTEXT_MODULE, 'cmid' => $cmid, 'ev' => $eventname));
        return array_map('intval', $rows);
    }

    /**
     * Champ « other » d'un événement journalisé (JSON, ou sérialisé sur les
     * anciennes données). Même logique que \tool_log\helper\reader::decode_other.
     *
     * @param string|null $other
     * @return array
     */
    public static function decode_other(?string $other): array {
        if ($other === null || $other === '' || $other === 'N;') {
            return array();
        }
        if (preg_match('~^.:~', $other)) {
            $value = @unserialize($other, array('allowed_classes' => array(\stdClass::class)));
        } else {
            $value = json_decode($other, true);
        }
        return is_array($value) ? $value : (array)$value;
    }
}
