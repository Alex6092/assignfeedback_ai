<?php
namespace local_aimissions\privacy;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Fournisseur de confidentialité pour local_aimissions.
 *
 * Données personnelles :
 *   - {local_aimissions_ticket} : questions d'un étudiant au client IA + réponses.
 *   - {local_aimissions_job}     : jobs de génération (userid = enseignant).
 * Les projets / missions ne sont pas des données personnelles (entreprises
 * fictives). Le contexte de rattachement est le CONTEXTE COURS.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aimissions_ticket', array(
            'userid'      => 'privacy:metadata:ticket:userid',
            'question'    => 'privacy:metadata:ticket:question',
            'answer'      => 'privacy:metadata:ticket:answer',
            'reaction'    => 'privacy:metadata:ticket:reaction',
            'timecreated' => 'privacy:metadata:ticket:timecreated',
        ), 'privacy:metadata:ticket');

        $collection->add_database_table('local_aimissions_job', array(
            'userid'      => 'privacy:metadata:job:userid',
            'timecreated' => 'privacy:metadata:job:timecreated',
        ), 'privacy:metadata:job');

        $collection->add_database_table('local_aimissions_commeval', array(
            'userid'      => 'privacy:metadata:commeval:userid',
            'colour'      => 'privacy:metadata:commeval:colour',
            'score'       => 'privacy:metadata:commeval:score',
            'comment'     => 'privacy:metadata:commeval:comment',
            'timecreated' => 'privacy:metadata:commeval:timecreated',
        ), 'privacy:metadata:commeval');

        $collection->add_external_location_link('llm', array(
            'dossier'  => 'privacy:metadata:llm:dossier',
            'question' => 'privacy:metadata:llm:question',
        ), 'privacy:metadata:llm');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_aimissions_project} p
                 ON p.courseid = ctx.instanceid AND ctx.contextlevel = :clcourse1
               JOIN {local_aimissions_ticket} t ON t.projectid = p.id
              WHERE t.userid = :userid1",
            array('clcourse1' => CONTEXT_COURSE, 'userid1' => $userid));

        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_aimissions_job} j
                 ON j.courseid = ctx.instanceid AND ctx.contextlevel = :clcourse2
              WHERE j.userid = :userid2",
            array('clcourse2' => CONTEXT_COURSE, 'userid2' => $userid));

        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_aimissions_project} p
                 ON p.courseid = ctx.instanceid AND ctx.contextlevel = :clcourse3
               JOIN {local_aimissions_commeval} ce ON ce.projectid = p.id
              WHERE ce.userid = :userid3",
            array('clcourse3' => CONTEXT_COURSE, 'userid3' => $userid));

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $params = array('courseid' => $context->instanceid);

        $userlist->add_from_sql('userid',
            "SELECT t.userid
               FROM {local_aimissions_ticket} t
               JOIN {local_aimissions_project} p ON p.id = t.projectid
              WHERE p.courseid = :courseid", $params);

        $userlist->add_from_sql('userid',
            "SELECT j.userid
               FROM {local_aimissions_job} j
              WHERE j.courseid = :courseid", $params);

        $userlist->add_from_sql('userid',
            "SELECT ce.userid
               FROM {local_aimissions_commeval} ce
               JOIN {local_aimissions_project} p ON p.id = ce.projectid
              WHERE p.courseid = :courseid", $params);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $root   = array(get_string('pluginname', 'local_aimissions'));

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            $courseid = $context->instanceid;

            $tickets = $DB->get_records_sql(
                "SELECT t.*
                   FROM {local_aimissions_ticket} t
                   JOIN {local_aimissions_project} p ON p.id = t.projectid
                  WHERE p.courseid = :courseid AND t.userid = :userid
               ORDER BY t.timecreated ASC",
                array('courseid' => $courseid, 'userid' => $userid));
            if ($tickets) {
                $rows = array();
                foreach ($tickets as $t) {
                    $rows[] = array(
                        'question'    => $t->question,
                        'answer'      => $t->answer,
                        'status'      => $t->status,
                        'reaction'    => $t->reaction,
                        'timecreated' => transform::datetime($t->timecreated),
                    );
                }
                writer::with_context($context)->export_data(
                    array_merge($root, array(get_string('privacy:path:tickets', 'local_aimissions'))),
                    (object)array('tickets' => $rows));
            }

            $jobs = $DB->get_records('local_aimissions_job',
                array('courseid' => $courseid, 'userid' => $userid), 'timecreated ASC');
            if ($jobs) {
                $rows = array();
                foreach ($jobs as $j) {
                    $rows[] = array(
                        'kind'        => $j->kind,
                        'status'      => $j->status,
                        'params'      => $j->params,
                        'timecreated' => transform::datetime($j->timecreated),
                    );
                }
                writer::with_context($context)->export_data(
                    array_merge($root, array(get_string('privacy:path:jobs', 'local_aimissions'))),
                    (object)array('jobs' => $rows));
            }

            $commevals = $DB->get_records_sql(
                "SELECT ce.*
                   FROM {local_aimissions_commeval} ce
                   JOIN {local_aimissions_project} p ON p.id = ce.projectid
                  WHERE p.courseid = :courseid AND ce.userid = :userid
               ORDER BY ce.timecreated ASC",
                array('courseid' => $courseid, 'userid' => $userid));
            if ($commevals) {
                $rows = array();
                foreach ($commevals as $ce) {
                    $rows[] = array(
                        'colour'      => $ce->colour,
                        'score'       => $ce->score,
                        'comment'     => $ce->comment,
                        'status'      => $ce->status,
                        'timecreated' => transform::datetime($ce->timecreated),
                    );
                }
                writer::with_context($context)->export_data(
                    array_merge($root, array(get_string('privacy:path:commeval', 'local_aimissions'))),
                    (object)array('commeval' => $rows));
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof context_course) {
            return;
        }
        $courseid = $context->instanceid;
        $projectids = $DB->get_fieldset_select('local_aimissions_project', 'id', 'courseid = ?', array($courseid));
        if (!empty($projectids)) {
            list($insql, $params) = $DB->get_in_or_equal($projectids);
            $DB->delete_records_select('local_aimissions_ticket', "projectid $insql", $params);
            $DB->delete_records_select('local_aimissions_commeval', "projectid $insql", $params);
        }
        $DB->delete_records('local_aimissions_job', array('courseid' => $courseid));
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            $courseid = $context->instanceid;
            $projectids = $DB->get_fieldset_select('local_aimissions_project', 'id', 'courseid = ?', array($courseid));
            if (!empty($projectids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($projectids);
                $DB->delete_records_select('local_aimissions_ticket',
                    "projectid $insql AND userid = ?", array_merge($inparams, array($userid)));
                $DB->delete_records_select('local_aimissions_commeval',
                    "projectid $insql AND userid = ?", array_merge($inparams, array($userid)));
            }
            $DB->delete_records('local_aimissions_job', array('courseid' => $courseid, 'userid' => $userid));
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $courseid = $context->instanceid;
        $userids  = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        list($uinsql, $uparams) = $DB->get_in_or_equal($userids);

        $projectids = $DB->get_fieldset_select('local_aimissions_project', 'id', 'courseid = ?', array($courseid));
        if (!empty($projectids)) {
            list($pinsql, $pparams) = $DB->get_in_or_equal($projectids);
            $DB->delete_records_select('local_aimissions_ticket',
                "projectid $pinsql AND userid $uinsql", array_merge($pparams, $uparams));
            $DB->delete_records_select('local_aimissions_commeval',
                "projectid $pinsql AND userid $uinsql", array_merge($pparams, $uparams));
        }
        $params2 = array_merge(array($courseid), $uparams);
        $DB->delete_records_select('local_aimissions_job',
            "courseid = ? AND userid $uinsql", $params2);
    }
}
