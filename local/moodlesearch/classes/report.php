<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

/**
 * Lecture du journal pour les rapports : recherches et clics, d'un ensemble
 * d'utilisateurs (élèves d'un cours) ou de tout le site.
 */
class report {

    /** Période du filtre, en jours (0 = tout). */
    const PERIODS = array(1, 7, 30, 90, 0);

    /**
     * Recherches, les plus récentes d'abord, avec leurs clics.
     *
     * @param int[]|null $userids null = tout le site ; tableau vide = personne
     * @param int        $days    période (0 = tout)
     * @param int        $page
     * @param int        $perpage
     * @return array{total:int, rows:\stdClass[]} chaque ligne porte ->clicks (\stdClass[])
     */
    public static function searches(?array $userids, int $days, int $page = 0, int $perpage = 50): array {
        global $DB;
        if (is_array($userids) && empty($userids)) {
            return array('total' => 0, 'rows' => array());
        }
        $where = array('1 = 1');
        $params = array();
        if ($userids !== null) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'u');
            $where[] = "s.userid $insql";
            $params += $inparams;
        }
        if ($days > 0) {
            $where[] = 's.timecreated >= :since';
            $params['since'] = time() - $days * DAYSECS;
        }
        $wheresql = implode(' AND ', $where);

        $total = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {" . searcher::TABLE . "} s WHERE $wheresql", $params);
        $rows = $DB->get_records_sql(
            "SELECT s.id, s.userid, s.query, s.tab, s.period, s.resultcount, s.status, s.reason, s.timecreated
               FROM {" . searcher::TABLE . "} s
              WHERE $wheresql
           ORDER BY s.timecreated DESC, s.id DESC", $params, $page * $perpage, $perpage);

        foreach ($rows as $row) {
            $row->clicks = array();
        }
        if (!empty($rows)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($rows));
            foreach ($DB->get_records_select(clicks::TABLE, "searchid $insql", $inparams, 'timecreated ASC')
                    as $click) {
                $rows[$click->searchid]->clicks[] = $click;
            }
        }
        return array('total' => $total, 'rows' => array_values($rows));
    }

    /**
     * Élèves d'un cours dont l'enseignant voit les recherches : les inscrits,
     * sauf ceux qui voient eux-mêmes le rapport (enseignants, gestionnaires).
     *
     * @return array userid => nom complet
     */
    public static function course_students(\context_course $context): array {
        $out = array();
        foreach (get_enrolled_users($context, '', 0, 'u.*', 'u.lastname, u.firstname') as $user) {
            if (!has_capability('local/moodlesearch:viewreport', $context, $user)) {
                $out[(int)$user->id] = fullname($user);
            }
        }
        return $out;
    }
}
