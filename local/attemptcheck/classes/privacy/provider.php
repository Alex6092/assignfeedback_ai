<?php
namespace local_attemptcheck\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Vie privée : décisions « tentative légitime » (élève concerné et enseignant
 * qui a tranché), rattachées au contexte du cours. Les indicateurs eux-mêmes
 * sont recalculés à la demande et ne sont pas stockés.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    const TABLE = 'local_attemptcheck_review';

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, array(
            'userid'       => 'privacy:metadata:review:userid',
            'itemtype'     => 'privacy:metadata:review:itemtype',
            'itemid'       => 'privacy:metadata:review:itemid',
            'status'       => 'privacy:metadata:review:status',
            'reviewerid'   => 'privacy:metadata:review:reviewerid',
            'timemodified' => 'privacy:metadata:review:timemodified',
        ), 'privacy:metadata:review');
        $collection->add_subsystem_link('core_message', array(), 'privacy:metadata:message');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql("SELECT ctx.id
                               FROM {context} ctx
                               JOIN {" . self::TABLE . "} r ON r.courseid = ctx.instanceid AND ctx.contextlevel = :lvl
                              WHERE r.userid = :u1 OR r.reviewerid = :u2",
            array('lvl' => CONTEXT_COURSE, 'u1' => $userid, 'u2' => $userid));
        return $list;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!($context instanceof \context_course)) {
            return;
        }
        $params = array('courseid' => (int)$context->instanceid);
        $userlist->add_from_sql('userid', "SELECT userid FROM {" . self::TABLE . "} WHERE courseid = :courseid", $params);
        $userlist->add_from_sql('reviewerid', "SELECT reviewerid FROM {" . self::TABLE . "}
                                                WHERE courseid = :courseid AND reviewerid > 0", $params);
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_course)) {
                continue;
            }
            $rows = $DB->get_records_select(self::TABLE, 'courseid = :courseid AND (userid = :u1 OR reviewerid = :u2)',
                array('courseid' => (int)$context->instanceid, 'u1' => $userid, 'u2' => $userid), 'timemodified ASC');
            $data = array();
            foreach ($rows as $row) {
                $data[] = (object)array(
                    'cmid'        => (int)$row->cmid,
                    'type'        => $row->itemtype,
                    'item'        => (int)$row->itemid,
                    'status'      => $row->status,
                    'yourattempt' => transform::yesno((int)$row->userid === $userid),
                    'youreviewed' => transform::yesno((int)$row->reviewerid === $userid),
                    'time'        => transform::datetime($row->timemodified),
                );
            }
            if ($data) {
                writer::with_context($context)->export_data(
                    array(get_string('pluginname', 'local_attemptcheck')), (object)array('reviews' => $data));
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_course) {
            $DB->delete_records(self::TABLE, array('courseid' => (int)$context->instanceid));
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
            self::delete_users((int)$context->instanceid, array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Supprime les décisions portant sur ces élèves, et anonymise celles
     * qu'ils ont prises en tant qu'enseignants.
     */
    private static function delete_users(int $courseid, array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $DB->delete_records_select(self::TABLE, "courseid = :courseid AND userid $insql", $params);
        $DB->set_field_select(self::TABLE, 'reviewerid', 0, "courseid = :courseid AND reviewerid $insql", $params);
    }
}
