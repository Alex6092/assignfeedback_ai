<?php
namespace local_aifeedback\privacy;

defined('MOODLE_INTERNAL') || die();

use context;
use context_module;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Fournisseur de confidentialité pour local_aifeedback.
 *
 * Deux jeux de données personnelles :
 *   - {local_aifeedback_qgrading} : corrections IA des questions de quiz
 *     (feedback, note). Rattachées au CONTEXTE MODULE du quiz concerné.
 *   - {local_aifeedback_slot}     : tickets de file du pool de serveurs LLM
 *     (métadonnées techniques d'attente, sans contenu). Rattachés au
 *     CONTEXTE UTILISATEUR, faute de rattachement métier stable.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aifeedback_qgrading', array(
            'userid'       => 'privacy:metadata:qgrading:userid',
            'status'       => 'privacy:metadata:qgrading:status',
            'aifeedback'   => 'privacy:metadata:qgrading:aifeedback',
            'mark'         => 'privacy:metadata:qgrading:mark',
            'timecreated'  => 'privacy:metadata:qgrading:timecreated',
        ), 'privacy:metadata:qgrading');

        $collection->add_database_table('local_aifeedback_slot', array(
            'userid'      => 'privacy:metadata:slot:userid',
            'purpose'     => 'privacy:metadata:slot:purpose',
            'component'   => 'privacy:metadata:slot:component',
            'status'      => 'privacy:metadata:slot:status',
            'timecreated' => 'privacy:metadata:slot:timecreated',
        ), 'privacy:metadata:slot');

        $collection->add_external_location_link('llm', array(
            'content' => 'privacy:metadata:llm:content',
        ), 'privacy:metadata:llm');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Corrections de questions de quiz → contexte du module quiz.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_aifeedback_qgrading} g
               JOIN {question_attempts} qatt ON qatt.id = g.questionattemptid
               JOIN {quiz_attempts} qza ON qza.uniqueid = qatt.questionusageid
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.instance = qza.quiz AND cm.module = m.id
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :clmodule
              WHERE g.userid = :userid1",
            array('clmodule' => CONTEXT_MODULE, 'userid1' => $userid));

        // Tickets de file → contexte de l'utilisateur.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
              WHERE ctx.contextlevel = :cluser AND ctx.instanceid = :userid2
                AND EXISTS (SELECT 1 FROM {local_aifeedback_slot} s WHERE s.userid = ctx.instanceid)",
            array('cluser' => CONTEXT_USER, 'userid2' => $userid));

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof context_module) {
            $userlist->add_from_sql('userid',
                "SELECT g.userid
                   FROM {local_aifeedback_qgrading} g
                   JOIN {question_attempts} qatt ON qatt.id = g.questionattemptid
                   JOIN {quiz_attempts} qza ON qza.uniqueid = qatt.questionusageid
                   JOIN {modules} m ON m.name = 'quiz'
                   JOIN {course_modules} cm ON cm.instance = qza.quiz AND cm.module = m.id
                  WHERE cm.id = :cmid",
                array('cmid' => $context->instanceid));
            return;
        }

        if ($context instanceof context_user) {
            $userlist->add_from_sql('userid',
                "SELECT s.userid FROM {local_aifeedback_slot} s WHERE s.userid = :userid",
                array('userid' => $context->instanceid));
        }
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $root   = array(get_string('pluginname', 'local_aifeedback'));

        foreach ($contextlist->get_contexts() as $context) {

            if ($context instanceof context_module) {
                $rows = $DB->get_records_sql(
                    "SELECT g.*
                       FROM {local_aifeedback_qgrading} g
                       JOIN {question_attempts} qatt ON qatt.id = g.questionattemptid
                       JOIN {quiz_attempts} qza ON qza.uniqueid = qatt.questionusageid
                       JOIN {modules} m ON m.name = 'quiz'
                       JOIN {course_modules} cm ON cm.instance = qza.quiz AND cm.module = m.id
                      WHERE cm.id = :cmid AND g.userid = :userid
                   ORDER BY g.timecreated ASC",
                    array('cmid' => $context->instanceid, 'userid' => $userid));
                if ($rows) {
                    $out = array();
                    foreach ($rows as $row) {
                        $out[] = array(
                            'component'   => $row->component,
                            'status'      => $row->status,
                            'aifeedback'  => $row->aifeedback,
                            'mark'        => $row->mark,
                            'timecreated' => transform::datetime($row->timecreated),
                        );
                    }
                    writer::with_context($context)->export_data(
                        array_merge($root, array(get_string('privacy:path:qgrading', 'local_aifeedback'))),
                        (object)array('gradings' => $out));
                }
                continue;
            }

            if ($context instanceof context_user && (int)$context->instanceid === (int)$userid) {
                $rows = $DB->get_records('local_aifeedback_slot',
                    array('userid' => $userid), 'timecreated ASC');
                if ($rows) {
                    $out = array();
                    foreach ($rows as $row) {
                        $out[] = array(
                            'purpose'     => $row->purpose,
                            'component'   => $row->component,
                            'status'      => $row->status,
                            'timecreated' => transform::datetime($row->timecreated),
                        );
                    }
                    writer::with_context($context)->export_data(
                        array_merge($root, array(get_string('privacy:path:pool', 'local_aifeedback'))),
                        (object)array('slots' => $out));
                }
            }
        }
    }

    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if ($context instanceof context_module) {
            $ids = self::qgrading_ids_for_cm((int)$context->instanceid);
            if (!empty($ids)) {
                list($insql, $params) = $DB->get_in_or_equal($ids);
                $DB->delete_records_select('local_aifeedback_qgrading', "id $insql", $params);
            }
            return;
        }

        if ($context instanceof context_user) {
            $DB->delete_records('local_aifeedback_slot', array('userid' => $context->instanceid));
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_module) {
                $ids = self::qgrading_ids_for_cm((int)$context->instanceid, $userid);
                if (!empty($ids)) {
                    list($insql, $params) = $DB->get_in_or_equal($ids);
                    $DB->delete_records_select('local_aifeedback_qgrading', "id $insql", $params);
                }
            } else if ($context instanceof context_user && (int)$context->instanceid === (int)$userid) {
                $DB->delete_records('local_aifeedback_slot', array('userid' => $userid));
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        if ($context instanceof context_module) {
            $ids = self::qgrading_ids_for_cm((int)$context->instanceid);
            if (empty($ids)) {
                return;
            }
            list($insql, $inparams)   = $DB->get_in_or_equal($ids);
            list($usql, $userparams)  = $DB->get_in_or_equal($userids);
            $DB->delete_records_select('local_aifeedback_qgrading',
                "id $insql AND userid $usql", array_merge($inparams, $userparams));
            return;
        }

        if ($context instanceof context_user) {
            list($usql, $params) = $DB->get_in_or_equal($userids);
            $DB->delete_records_select('local_aifeedback_slot', "userid $usql", $params);
        }
    }

    /**
     * Identifiants des lignes de correction rattachées à un module quiz
     * (éventuellement filtrées sur un utilisateur).
     *
     * @return int[]
     */
    private static function qgrading_ids_for_cm(int $cmid, ?int $userid = null): array {
        global $DB;
        $params = array('cmid' => $cmid);
        $where  = '';
        if ($userid !== null) {
            $where = ' AND g.userid = :userid';
            $params['userid'] = $userid;
        }
        return $DB->get_fieldset_sql(
            "SELECT g.id
               FROM {local_aifeedback_qgrading} g
               JOIN {question_attempts} qatt ON qatt.id = g.questionattemptid
               JOIN {quiz_attempts} qza ON qza.uniqueid = qatt.questionusageid
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.instance = qza.quiz AND cm.module = m.id
              WHERE cm.id = :cmid" . $where,
            $params);
    }
}
