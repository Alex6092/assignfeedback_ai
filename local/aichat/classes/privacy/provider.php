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
 * messages, rattachées au CONTEXTE MODULE de l'activité concernée ; et les
 * clés de recherche personnelles (préférences utilisateur, chiffrées). La
 * configuration du tuteur (table ..._activity) est une donnée d'enseignement,
 * pas une donnée personnelle.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\user_preference_provider {

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
            'websearches' => 'privacy:metadata:message:websearches',
            'pagereads'   => 'privacy:metadata:message:pagereads',
            'toolcalls'   => 'privacy:metadata:message:toolcalls',
            'flagstatus'   => 'privacy:metadata:message:flagstatus',
            'flagcategory' => 'privacy:metadata:message:flagcategory',
            'flagreason'   => 'privacy:metadata:message:flagreason',
            'timecreated' => 'privacy:metadata:message:timecreated',
        ), 'privacy:metadata:message');

        // Fichiers joints par l'élève à ses questions (originaux dans la File API).
        $collection->add_database_table('local_aichat_attachment', array(
            'userid'      => 'privacy:metadata:attachment:userid',
            'filename'    => 'privacy:metadata:attachment:filename',
            'content'     => 'privacy:metadata:attachment:content',
            'timecreated' => 'privacy:metadata:attachment:timecreated',
        ), 'privacy:metadata:attachment');
        $collection->add_subsystem_link('core_files', array(), 'privacy:metadata:core_files');

        // Fenêtre de quota de l'élève (commune à toutes les activités) et bonus
        // accordé par un enseignant : rattachée au contexte UTILISATEUR.
        $collection->add_database_table('local_aichat_quota', array(
            'userid'        => 'privacy:metadata:quota:userid',
            'windowstart'   => 'privacy:metadata:quota:windowstart',
            'bonustokens'   => 'privacy:metadata:quota:bonustokens',
            'bonusmessages' => 'privacy:metadata:quota:bonusmessages',
            'grantedby'     => 'privacy:metadata:quota:grantedby',
            'granttime'     => 'privacy:metadata:quota:granttime',
        ), 'privacy:metadata:quota');

        $collection->add_external_location_link('llm', array(
            'message'     => 'privacy:metadata:llm:message',
            'attachments' => 'privacy:metadata:llm:attachments',
        ), 'privacy:metadata:llm');

        // Recherche Web (si activée) : seule la requête rédigée par le modèle
        // part chez le moteur, après retrait de l'identité de l'élève.
        $collection->add_external_location_link('websearch', array(
            'query' => 'privacy:metadata:websearch:query',
        ), 'privacy:metadata:websearch');

        // Mode « recherche de matériel » : le serveur télécharge les pages lues
        // (seule l'adresse part vers le site concerné, rien de l'élève).
        $collection->add_external_location_link('pagereader', array(
            'url' => 'privacy:metadata:pagereader:url',
        ), 'privacy:metadata:pagereader');

        // Clés de recherche personnelles de l'élève (chiffrées) et leur état.
        foreach (\local_aichat\websearch\userkeys::PROVIDERS as $id) {
            $collection->add_user_preference(\local_aichat\websearch\userkeys::PREF_KEY . $id,
                'privacy:metadata:preference:key');
            $collection->add_user_preference(\local_aichat\websearch\userkeys::PREF_STATE . $id,
                'privacy:metadata:preference:keystate');
        }

        return $collection;
    }

    /**
     * Préférences exportées : on indique qu'une clé est configurée et ses 4
     * derniers caractères, JAMAIS la clé elle-même.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        foreach (\local_aichat\websearch\userkeys::PROVIDERS as $id) {
            $key = \local_aichat\websearch\userkeys::get($userid, $id);
            if ($key === '') {
                continue;
            }
            writer::export_user_preference('local_aichat', \local_aichat\websearch\userkeys::PREF_KEY . $id,
                get_string('privacy:keyconfigured', 'local_aichat', \local_aichat\websearch\userkeys::masked($key)),
                get_string('privacy:metadata:preference:key', 'local_aichat'));
        }
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
        // Quota : celui de l'élève, ou un bonus accordé par cet enseignant.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
              WHERE ctx.contextlevel = :cluser AND ctx.instanceid = :userid
                AND EXISTS (SELECT 1 FROM {local_aichat_quota} q
                             WHERE q.userid = :quser OR q.grantedby = :qgranter)",
            array('cluser' => CONTEXT_USER, 'userid' => $userid, 'quser' => $userid, 'qgranter' => $userid));
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            $userid = (int)$context->instanceid;
            if ($DB->record_exists_select('local_aichat_quota', 'userid = ? OR grantedby = ?', array($userid, $userid))) {
                $userlist->add_user($userid);
            }
            return;
        }
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
            if ($context instanceof \context_user && (int)$context->instanceid === (int)$userid) {
                $quota = $DB->get_record('local_aichat_quota', array('userid' => $userid));
                if ($quota) {
                    writer::with_context($context)->export_data(
                        array_merge($root, array(get_string('privacy:path:quota', 'local_aichat'))),
                        (object)array(
                            'windowstart'   => $quota->windowstart ? transform::datetime($quota->windowstart) : '-',
                            'bonustokens'   => (int)$quota->bonustokens,
                            'bonusmessages' => (int)$quota->bonusmessages,
                            'granttime'     => $quota->granttime ? transform::datetime($quota->granttime) : '-',
                        ));
                }
                continue;
            }
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
                $subcontext = array_merge($root, array(
                    get_string('privacy:path:conversations', 'local_aichat'),
                    get_string('privacy:path:conversation', 'local_aichat', $index),
                ));
                $messages = $DB->get_records('local_aichat_message',
                    array('conversationid' => $conversation->id), 'id ASC');
                $files = \local_aichat\attachment::for_messages(array_keys($messages));
                $rows = array();
                foreach ($messages as $message) {
                    $row = array(
                        'role'        => $message->role,
                        'content'     => $message->content,
                        'status'      => $message->status,
                        'tokens'      => (int)$message->tokens,
                        'timecreated' => transform::datetime($message->timecreated),
                    );
                    // Résultat de la modération : l'élève a le droit de savoir
                    // qu'un de ses messages a été signalé, et pourquoi.
                    if ($message->flagstatus === 'flagged') {
                        $row['flagcategory'] = $message->flagcategory;
                        $row['flagreason']   = $message->flagreason;
                    }
                    // Recherches Web faites par le tuteur pour cette réponse.
                    $searches = \local_aichat\conversation::searches($message);
                    if (!empty($searches)) {
                        $row['websearches'] = array_map(function($search) {
                            return array('query' => $search['q'], 'status' => $search['status']);
                        }, $searches);
                    }
                    // Fichiers joints : noms ici, originaux exportés à côté.
                    if (isset($files[(int)$message->id])) {
                        $row['attachments'] = array();
                        foreach ($files[(int)$message->id] as $file) {
                            $row['attachments'][] = $file->filename;
                            writer::with_context($context)->export_area_files($subcontext, 'local_aichat',
                                \local_aichat\attachment::FILEAREA, (int)$file->id);
                        }
                    }
                    $rows[] = $row;
                }
                writer::with_context($context)->export_data(
                    $subcontext,
                    (object)array(
                        'status'      => $conversation->status,
                        'timecreated' => transform::datetime($conversation->timecreated),
                        'messages'    => $rows,
                    ));
            }
        }
    }

    public static function delete_data_for_all_users_in_context(context $context): void {
        if ($context instanceof \context_user) {
            self::delete_quota((int)$context->instanceid);
            return;
        }
        if (!$context instanceof context_module) {
            return;
        }
        \local_aichat\activity::delete_conversations_for_cmid((int)$context->instanceid);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int)$context->instanceid === (int)$userid) {
                self::delete_quota((int)$userid);
                continue;
            }
            if (!$context instanceof context_module) {
                continue;
            }
            self::delete_for_users((int)$context->instanceid, array($userid));
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            if (in_array((int)$context->instanceid, array_map('intval', $userlist->get_userids()), true)) {
                self::delete_quota((int)$context->instanceid);
            }
            return;
        }
        if (!$context instanceof context_module) {
            return;
        }
        self::delete_for_users((int)$context->instanceid, $userlist->get_userids());
    }

    /**
     * Supprime le quota d'un élève, et retire son nom des bonus qu'il a
     * accordés en tant qu'enseignant (le bonus lui-même reste à l'élève).
     *
     * @param int $userid
     */
    private static function delete_quota(int $userid): void {
        global $DB;
        $DB->delete_records('local_aichat_quota', array('userid' => $userid));
        $DB->set_field('local_aichat_quota', 'grantedby', 0, array('grantedby' => $userid));
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
        \local_aichat\attachment::delete_for_conversations($conversationids);
        list($insql, $inparams) = $DB->get_in_or_equal($conversationids);
        $DB->delete_records_select('local_aichat_message', "conversationid $insql", $inparams);
        $DB->delete_records_select('local_aichat_conversation', "id $insql", $inparams);
    }
}
