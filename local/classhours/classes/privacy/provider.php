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
 * qui demande, son message, et l'enseignant qui décide. Elles sont rattachées
 * au contexte du cours.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    const TABLE = 'local_classhours_grant';

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
        $collection->add_subsystem_link('core_message', array(), 'privacy:metadata:messages');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT ctx.id
               FROM {context} ctx
               JOIN {' . self::TABLE . '} g ON g.courseid = ctx.instanceid AND ctx.contextlevel = :level
              WHERE g.userid = :userid OR g.decidedby = :decidedby',
            array('level' => CONTEXT_COURSE, 'userid' => $userid, 'decidedby' => $userid));
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
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_course) {
            $DB->delete_records(self::TABLE, array('courseid' => $context->instanceid));
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
    }
}
