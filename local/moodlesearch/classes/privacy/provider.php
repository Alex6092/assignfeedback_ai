<?php
namespace local_moodlesearch\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Vie privée : recherches et clics de l'utilisateur (contexte utilisateur :
 * ils ne sont rattachés à aucun cours), et les deux destinations externes.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_moodlesearch_search', array(
            'userid'      => 'privacy:metadata:search:userid',
            'query'       => 'privacy:metadata:search:query',
            'results'     => 'privacy:metadata:search:results',
            'timecreated' => 'privacy:metadata:timecreated',
        ), 'privacy:metadata:search');
        $collection->add_database_table('local_moodlesearch_click', array(
            'userid'      => 'privacy:metadata:click:userid',
            'url'         => 'privacy:metadata:click:url',
            'opnstatus'   => 'privacy:metadata:click:opnstatus',
            'timecreated' => 'privacy:metadata:timecreated',
        ), 'privacy:metadata:click');
        $collection->add_external_location_link('tavily', array(
            'query' => 'privacy:metadata:tavily:query',
        ), 'privacy:metadata:tavily');
        $collection->add_external_location_link('opnsense', array(
            'domain' => 'privacy:metadata:opnsense:domain',
        ), 'privacy:metadata:opnsense');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        if (self::has_data($userid)) {
            $list->add_user_context($userid);
        }
        return $list;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::has_data((int)$context->instanceid)) {
            $userlist->add_user((int)$context->instanceid);
        }
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_user) || (int)$context->instanceid !== $userid) {
                continue;
            }
            $searches = array();
            foreach ($DB->get_records('local_moodlesearch_search', array('userid' => $userid), 'timecreated ASC')
                    as $s) {
                $searches[(int)$s->id] = (object)array(
                    'query'   => $s->query,
                    'tab'     => $s->tab,
                    'period'  => $s->period,
                    'results' => (int)$s->resultcount,
                    'status'  => $s->status,
                    'time'    => transform::datetime($s->timecreated),
                    'clicks'  => array(),
                );
            }
            foreach ($DB->get_records('local_moodlesearch_click', array('userid' => $userid), 'timecreated ASC')
                    as $c) {
                $click = (object)array('url' => $c->url, 'opnsense' => $c->opnstatus,
                    'time' => transform::datetime($c->timecreated));
                if (isset($searches[(int)$c->searchid])) {
                    $searches[(int)$c->searchid]->clicks[] = $click;
                }
            }
            writer::with_context($context)->export_data(
                array(get_string('pluginname', 'local_moodlesearch')),
                (object)array('searches' => array_values($searches)));
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            self::delete_user((int)$context->instanceid);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int)$context->instanceid === $userid) {
                self::delete_user($userid);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user
                && in_array((int)$context->instanceid, array_map('intval', $userlist->get_userids()), true)) {
            self::delete_user((int)$context->instanceid);
        }
    }

    private static function has_data(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_moodlesearch_search', array('userid' => $userid))
            || $DB->record_exists('local_moodlesearch_click', array('userid' => $userid));
    }

    private static function delete_user(int $userid): void {
        global $DB;
        $DB->delete_records('local_moodlesearch_click', array('userid' => $userid));
        $DB->delete_records('local_moodlesearch_search', array('userid' => $userid));
    }
}
