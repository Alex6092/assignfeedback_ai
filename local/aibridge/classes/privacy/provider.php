<?php
namespace local_aibridge\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Vie privée : le pont ne stocke que le journal de ses appels (le compte qui
 * appelle, en général un compte de service) et le registre des objets qu'il
 * a créés. Les deux sont rattachés au contexte système.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    const LOG = 'local_aibridge_log';
    const CREATED = 'local_aibridge_created';

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::LOG, array(
            'userid'      => 'privacy:metadata:log:userid',
            'function'    => 'privacy:metadata:log:function',
            'courseid'    => 'privacy:metadata:log:courseid',
            'params'      => 'privacy:metadata:log:params',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ), 'privacy:metadata:log');
        $collection->add_database_table(self::CREATED, array(
            'userid'      => 'privacy:metadata:created:userid',
            'objecttype'  => 'privacy:metadata:created:objecttype',
            'timecreated' => 'privacy:metadata:created:timecreated',
        ), 'privacy:metadata:created');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists(self::LOG, array('userid' => $userid))
                || $DB->record_exists(self::CREATED, array('userid' => $userid))) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::LOG . '}', array());
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::CREATED . '}', array());
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $calls = array();
            foreach ($DB->get_records(self::LOG, array('userid' => $userid), 'timecreated') as $row) {
                $calls[] = array(
                    'function'    => $row->function,
                    'courseid'    => $row->courseid,
                    'cmid'        => $row->cmid,
                    'params'      => $row->params,
                    'status'      => $row->status,
                    'timecreated' => transform::datetime($row->timecreated),
                );
            }
            $created = array();
            foreach ($DB->get_records(self::CREATED, array('userid' => $userid), 'timecreated') as $row) {
                $created[] = array(
                    'objecttype'  => $row->objecttype,
                    'objectid'    => $row->objectid,
                    'courseid'    => $row->courseid,
                    'timecreated' => transform::datetime($row->timecreated),
                );
            }
            if ($calls || $created) {
                writer::with_context($context)->export_data(
                    array(get_string('pluginname', 'local_aibridge')),
                    (object)array('calls' => $calls, 'created' => $created));
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records(self::LOG);
            $DB->delete_records(self::CREATED);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $DB->delete_records(self::LOG, array('userid' => $userid));
                $DB->delete_records(self::CREATED, array('userid' => $userid));
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids);
        $DB->delete_records_select(self::LOG, "userid $insql", $params);
        $DB->delete_records_select(self::CREATED, "userid $insql", $params);
    }
}
