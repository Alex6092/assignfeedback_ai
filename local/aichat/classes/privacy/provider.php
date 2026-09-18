<?php
namespace local_aichat\privacy;

defined('MOODLE_INTERNAL') || die();

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Fournisseur de confidentialité pour local_aichat.
 *
 * Données personnelles : les conversations des élèves avec le tuteur et leurs
 * messages. Rattachées au CONTEXTE MODULE de l'activité concernée. La
 * configuration du tuteur (table ..._activity) est une donnée d'enseignement,
 * pas une donnée personnelle.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aichat_conversation', array(
            'userid'       => 'privacy:metadata:conversation:userid',
            'status'       => 'privacy:metadata:conversation:status',
            'timecreated'  => 'privacy:metadata:conversation:timecreated',
        ), 'privacy:metadata:conversation');

        $collection->add_database_table('local_aichat_message', array(
            'userid'      => 'privacy:metadata:message:userid',
            'role'        => 'privacy:metadata:message:role',
            'content'     => 'privacy:metadata:message:content',
            'tokens'      => 'privacy:metadata:message:tokens',
            'timecreated' => 'privacy:metadata:message:timecreated',
        ), 'privacy:metadata:message');

        $collection->add_external_location_link('llm', array(
            'message' => 'privacy:metadata:llm:message',
        ), 'privacy:metadata:llm');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_aichat_conversation} c
                 ON c.cmid = ctx.instanceid AND ctx.contextlevel = :clmodule
              WHERE c.userid = :userid",
            array('clmodule' => CONTEXT_MODULE, 'userid' => $userid));
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $userlist->add_from_sql('userid',
            "SELECT c.userid FROM {local_aichat_conversation} c WHERE c.cmid = :cmid",
            array('cmid' => $context->instanceid));
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $root   = array(get_string('pluginname', 'local_aichat'));

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $conversations = $DB->get_records('local_aichat_conversation',
                array('cmid' => $context->instanceid, 'userid' => $userid), 'timecreated ASC');
            if (empty($conversations)) {
                continue;
            }

            $index = 0;
            foreach ($conversations as $conversation) {
                $index++;
                $messages = $DB->get_records('local_aichat_message',
                    array('conversationid' => $conversation->id), 'id ASC');
                $rows = array();
                foreach ($messages as $message) {
                    $rows[] = array(
                        'role'        => $message->role,
                        'content'     => $message->content,
                        'status'      => $message->status,
                        'tokens'      => (int)$message->tokens,
                        'timecreated' => transform::datetime($message->timecreated),
                    );
                }
                writer::with_context($context)->export_data(
                    array_merge($root, array(
                        get_string('privacy:path:conversations', 'local_aichat'),
                        get_string('privacy:path:conversation', 'local_aichat', $index),
                    )),
                    (object)array(
                        'status'      => $conversation->status,
                        'timecreated' => transform::datetime($conversation->timecreated),
                        'messages'    => $rows,
                    ));
            }
        }
    }

    public static function delete_data_for_all_users_in_context(context $context): void {
        if (!$context instanceof context_module) {
            return;
        }
        \local_aichat\activity::delete_conversations_for_cmid((int)$context->instanceid);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            self::delete_for_users((int)$context->instanceid, array($userid));
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        self::delete_for_users((int)$context->instanceid, $userlist->get_userids());
    }

    /**
     * Supprime les conversations de certains utilisateurs sur un module.
     *
     * @param int   $cmid
     * @param int[] $userids
     */
    private static function delete_for_users(int $cmid, array $userids): void {
        global $DB;
        if (empty($userids)) {
            return;
        }
        list($usql, $params) = $DB->get_in_or_equal($userids);
        array_unshift($params, $cmid);

        $conversationids = $DB->get_fieldset_select('local_aichat_conversation', 'id',
            "cmid = ? AND userid $usql", $params);
        if (empty($conversationids)) {
            return;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($conversationids);
        $DB->delete_records_select('local_aichat_message', "conversationid $insql", $inparams);
        $DB->delete_records_select('local_aichat_conversation', "id $insql", $inparams);
    }
}
