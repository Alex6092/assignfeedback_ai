<?php
namespace local_classhours\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Vie privée : l'emploi du temps et les réglages d'activités ne sont pas des
 * données personnelles. Les demandes d'accès exceptionnel en sont : l'élève
 * qui demande, son message, et l'enseignant qui décide. Les ouvertures
 * d'activités surveillées aussi : l'élève visé par une ouverture individuelle,
 * et les enseignants qui ouvrent et ferment. Toutes sont rattachées au
 * contexte du cours.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    const TABLE = 'local_classhours_grant';

    /** Ouvertures d'activités surveillées (l'élève visé quand scope = user, et les enseignants). */
    const GATE = 'local_classhours_gate';

    /** Demandes de rattrapage, tiers-temps, codes de séance saisis (activités surveillées). */
    const CATCHUP = 'local_classhours_catchup';
    const EXTRATIME = 'local_classhours_extratime';
    const PRESENT = 'local_classhours_present';

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, array(
            'userid'          => 'privacy:metadata:grant:userid',
            'reason'          => 'privacy:metadata:grant:reason',
            'status'          => 'privacy:metadata:grant:status',
            'requestedat'     => 'privacy:metadata:grant:requestedat',
            'decidedby'       => 'privacy:metadata:grant:decidedby',
            'decidedat'       => 'privacy:metadata:grant:decidedat',
            'decisioncomment' => 'privacy:metadata:grant:decisioncomment',
            'timestart'       => 'privacy:metadata:grant:timestart',
            'timeend'         => 'privacy:metadata:grant:timeend',
        ), 'privacy:metadata:grant');
        $collection->add_database_table(self::GATE, array(
            'scopeid'    => 'privacy:metadata:gate:scopeid',
            'openedby'   => 'privacy:metadata:gate:openedby',
            'timeopened' => 'privacy:metadata:gate:timeopened',
            'closeat'    => 'privacy:metadata:gate:closeat',
            'closedby'   => 'privacy:metadata:gate:closedby',
            'timeclosed' => 'privacy:metadata:gate:timeclosed',
        ), 'privacy:metadata:gate');
        $collection->add_database_table(self::CATCHUP, array(
            'userid'      => 'privacy:metadata:catchup:userid',
            'status'      => 'privacy:metadata:catchup:status',
            'timecreated' => 'privacy:metadata:catchup:timecreated',
        ), 'privacy:metadata:catchup');
        $collection->add_database_table(self::EXTRATIME, array(
            'userid'  => 'privacy:metadata:extratime:userid',
            'percent' => 'privacy:metadata:extratime:percent',
        ), 'privacy:metadata:extratime');
        $collection->add_database_table(self::PRESENT, array(
            'userid'      => 'privacy:metadata:present:userid',
            'timecreated' => 'privacy:metadata:present:timecreated',
        ), 'privacy:metadata:present');
        $collection->add_subsystem_link('core_message', array(), 'privacy:metadata:messages');
        return $collection;
    }

    /**
     * Données d'élève des activités surveillées, par cours : demandes de
     * rattrapage, tiers-temps, codes de séance saisis.
     *
     * @return array [sql, params] : ids de cours de l'élève
     */
    private static function supervised_courses_sql(int $userid): array {
        return array(
            'SELECT ctx.id
               FROM {context} ctx
              WHERE ctx.contextlevel = :level
                AND (ctx.instanceid IN (SELECT courseid FROM {' . self::CATCHUP . '} WHERE userid = :u1)
                     OR ctx.instanceid IN (SELECT courseid FROM {' . self::EXTRATIME . '} WHERE userid = :u2)
                     OR ctx.instanceid IN (SELECT g.courseid FROM {' . self::PRESENT . '} p
                                             JOIN {' . self::GATE . '} g ON g.id = p.gateid WHERE p.userid = :u3))',
            array('level' => CONTEXT_COURSE, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid),
        );
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . self::TABLE . '} g ON g.courseid = ctx.instanceid AND ctx.contextlevel = :level
              WHERE g.userid = :userid OR g.decidedby = :decidedby',
            array('level' => CONTEXT_COURSE, 'userid' => $userid, 'decidedby' => $userid));
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . self::GATE . '} o ON o.courseid = ctx.instanceid AND ctx.contextlevel = :level
              WHERE (o.scope = :scope AND o.scopeid = :userid) OR o.openedby = :openedby OR o.closedby = :closedby',
            array('level' => CONTEXT_COURSE, 'scope' => 'user', 'userid' => $userid,
                'openedby' => $userid, 'closedby' => $userid));
        list($sql, $params) = self::supervised_courses_sql($userid);
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        $params = array('courseid' => $context->instanceid);
        $userlist->add_from_sql('userid',
            'SELECT userid FROM {' . self::TABLE . '} WHERE courseid = :courseid', $params);
        $userlist->add_from_sql('decidedby',
            'SELECT decidedby FROM {' . self::TABLE . '} WHERE courseid = :courseid AND decidedby > 0', $params);
        $userlist->add_from_sql('scopeid',
            'SELECT scopeid FROM {' . self::GATE . "} WHERE courseid = :courseid AND scope = 'user'", $params);
        $userlist->add_from_sql('openedby',
            'SELECT openedby FROM {' . self::GATE . '} WHERE courseid = :courseid AND openedby > 0', $params);
        $userlist->add_from_sql('closedby',
            'SELECT closedby FROM {' . self::GATE . '} WHERE courseid = :courseid AND closedby > 0', $params);
        $userlist->add_from_sql('userid',
            'SELECT userid FROM {' . self::CATCHUP . '} WHERE courseid = :courseid', $params);
        $userlist->add_from_sql('userid',
            'SELECT userid FROM {' . self::EXTRATIME . '} WHERE courseid = :courseid', $params);
        $userlist->add_from_sql('userid',
            'SELECT p.userid FROM {' . self::PRESENT . '} p JOIN {' . self::GATE . '} g ON g.id = p.gateid
              WHERE g.courseid = :courseid', $params);
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $requests = array();
            foreach ($DB->get_records(self::TABLE, array('courseid' => $context->instanceid, 'userid' => $userid),
                    'requestedat') as $row) {
                $requests[] = (object)array(
                    'cmid'            => (int)$row->cmid,
                    'status'          => $row->status,
                    'reason'          => (string)$row->reason,
                    'requestedat'     => transform::datetime((int)$row->requestedat),
                    'decidedat'       => $row->decidedat ? transform::datetime((int)$row->decidedat) : '',
                    'decisioncomment' => (string)$row->decisioncomment,
                    'timestart'       => $row->timestart ? transform::datetime((int)$row->timestart) : '',
                    'timeend'         => $row->timeend ? transform::datetime((int)$row->timeend) : '',
                );
            }
            // Décisions prises par l'utilisateur (enseignant), sans l'identité de l'élève.
            $decisions = array();
            foreach ($DB->get_records(self::TABLE, array('courseid' => $context->instanceid, 'decidedby' => $userid),
                    'decidedat') as $row) {
                $decisions[] = (object)array(
                    'cmid'            => (int)$row->cmid,
                    'status'          => $row->status,
                    'decidedat'       => transform::datetime((int)$row->decidedat),
                    'decisioncomment' => (string)$row->decisioncomment,
                );
            }
            if ($requests || $decisions) {
                writer::with_context($context)->export_data(
                    array(get_string('privacy:path', 'local_classhours')),
                    (object)array('requests' => $requests, 'decisions' => $decisions));
            }

            // Activités surveillées : ouvertures pour l'élève, et celles que
            // l'enseignant a ouvertes ou fermées (sans l'identité des élèves visés).
            $foryou = array();
            $byyou = array();
            $sql = 'courseid = :courseid AND ((scope = :scope AND scopeid = :userid) OR openedby = :openedby'
                . ' OR closedby = :closedby)';
            $rows = $DB->get_records_select(self::GATE, $sql, array('courseid' => $context->instanceid,
                'scope' => 'user', 'userid' => $userid, 'openedby' => $userid, 'closedby' => $userid), 'timeopened');
            foreach ($rows as $row) {
                $entry = (object)array(
                    'cmid'       => (int)$row->cmid,
                    'timeopened' => transform::datetime((int)$row->timeopened),
                    'closeat'    => $row->closeat ? transform::datetime((int)$row->closeat) : '',
                    'timeclosed' => $row->timeclosed ? transform::datetime((int)$row->timeclosed) : '',
                );
                if ($row->scope === 'user' && (int)$row->scopeid === $userid) {
                    $foryou[] = $entry;
                }
                if ((int)$row->openedby === $userid || (int)$row->closedby === $userid) {
                    $entry = clone $entry;
                    $entry->scope = $row->scope;
                    $byyou[] = $entry;
                }
            }
            // Demandes de rattrapage, tiers-temps, codes de séance saisis.
            $catchups = array();
            foreach ($DB->get_records(self::CATCHUP, array('courseid' => $context->instanceid, 'userid' => $userid),
                    'timecreated') as $row) {
                $catchups[] = (object)array('cmid' => (int)$row->cmid, 'status' => $row->status,
                    'timecreated' => transform::datetime((int)$row->timecreated));
            }
            $extratime = $DB->get_field(self::EXTRATIME, 'percent',
                array('courseid' => $context->instanceid, 'userid' => $userid));
            $present = array();
            foreach ($DB->get_records_sql('SELECT p.id, g.cmid, p.timecreated FROM {' . self::PRESENT . '} p
                                             JOIN {' . self::GATE . '} g ON g.id = p.gateid
                                            WHERE g.courseid = :courseid AND p.userid = :userid ORDER BY p.timecreated',
                    array('courseid' => $context->instanceid, 'userid' => $userid)) as $row) {
                $present[] = (object)array('cmid' => (int)$row->cmid,
                    'timecreated' => transform::datetime((int)$row->timecreated));
            }

            if ($foryou || $byyou || $catchups || $extratime !== false || $present) {
                writer::with_context($context)->export_data(
                    array(get_string('privacy:path_supervised', 'local_classhours')),
                    (object)array('openedforyou' => $foryou, 'openedorclosedbyyou' => $byyou,
                        'catchuprequests' => $catchups, 'extratimepercent' => $extratime !== false ? (int)$extratime : null,
                        'sessioncodes' => $present));
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_course) {
            $DB->delete_records_select(self::PRESENT, 'gateid IN (SELECT id FROM {' . self::GATE . '} WHERE courseid = ?)',
                array($context->instanceid));
            $DB->delete_records(self::TABLE, array('courseid' => $context->instanceid));
            $DB->delete_records(self::GATE, array('courseid' => $context->instanceid));
            $DB->delete_records(self::CATCHUP, array('courseid' => $context->instanceid));
            $DB->delete_records(self::EXTRATIME, array('courseid' => $context->instanceid));
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::delete_users((int)$context->instanceid, array($userid));
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_course) {
            self::delete_users((int)$context->instanceid, $userlist->get_userids());
        }
    }

    /**
     * Supprime les demandes des élèves, et retire le nom de l'enseignant des
     * décisions qu'il a prises (la décision elle-même reste, elle concerne
     * l'élève).
     *
     * @param int   $courseid
     * @param int[] $userids
     */
    private static function delete_users(int $courseid, array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $DB->delete_records_select(self::TABLE, "courseid = :courseid AND userid $insql", $params);
        $DB->execute('UPDATE {' . self::TABLE . "} SET decidedby = 0 WHERE courseid = :courseid AND decidedby $insql",
            $params);
        // Ouvertures pour un élève : supprimées ; ouvertes ou fermées par un
        // enseignant : son nom est retiré.
        $DB->delete_records_select(self::GATE, "courseid = :courseid AND scope = 'user' AND scopeid $insql", $params);
        $DB->execute('UPDATE {' . self::GATE . "} SET openedby = 0 WHERE courseid = :courseid AND openedby $insql",
            $params);
        $DB->execute('UPDATE {' . self::GATE . "} SET closedby = 0 WHERE courseid = :courseid AND closedby $insql",
            $params);
        // Activités surveillées : demandes de rattrapage, tiers-temps, codes saisis.
        $DB->delete_records_select(self::CATCHUP, "courseid = :courseid AND userid $insql", $params);
        $DB->delete_records_select(self::EXTRATIME, "courseid = :courseid AND userid $insql", $params);
        $DB->delete_records_select(self::PRESENT, 'userid ' . $insql . ' AND gateid IN (SELECT id FROM {' . self::GATE
            . '} WHERE courseid = :courseid)', $params);
    }
}
