<?php
/**
 * Revue, publication et suppression des missions générées d'un cours.
 *
 * Les missions sont créées en devoirs CACHÉS (visible=0). L'enseignant les
 * relit (lien vers le devoir), les publie (rend le module visible), peut les
 * re-masquer, et peut SUPPRIMER le dernier sprint d'un groupe (ou une mission
 * orpheline dont le devoir a déjà été supprimé) pour le régénérer.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_aimissions\mission_manager;

$courseid  = required_param('courseid', PARAM_INT);
$action    = optional_param('action', '', PARAM_ALPHA);
$missionid = optional_param('missionid', 0, PARAM_INT);
$confirm   = optional_param('confirm', 0, PARAM_BOOL);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aimissions:review', $context);

$baseurl = new moodle_url('/local/aimissions/manage.php', array('courseid' => $courseid));
$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('manage_title', 'local_aimissions'));
$PAGE->set_heading(format_string($course->fullname));

/**
 * Vérifie que la mission appartient bien au cours courant.
 */
$load_mission_in_course = function(int $mid) use ($DB, $courseid) {
    $mission = $DB->get_record('local_aimissions_mission', array('id' => $mid));
    if (!$mission) {
        return null;
    }
    $project = $DB->get_record('local_aimissions_project', array('id' => $mission->projectid));
    if (!$project || (int)$project->courseid !== $courseid) {
        return null;
    }
    return $mission;
};

// --- Actions publier / masquer (idempotentes, redirigent) ----------------
if (($action === 'publish' || $action === 'hide') && $missionid > 0 && confirm_sesskey()) {
    $mission = $load_mission_in_course($missionid);
    if ($mission && (int)$mission->assigncmid > 0) {
        if ($action === 'publish') {
            set_coursemodule_visible($mission->assigncmid, 1);
            $DB->set_field('local_aimissions_mission', 'status', 'published', array('id' => $missionid));
        } else {
            set_coursemodule_visible($mission->assigncmid, 0);
            $DB->set_field('local_aimissions_mission', 'status', 'draft', array('id' => $missionid));
        }
        rebuild_course_cache($courseid, true);
    }
    redirect($baseurl);
}

// --- Action supprimer (destructive → confirmation) -----------------------
if ($action === 'delete' && $missionid > 0) {
    $mission = $load_mission_in_course($missionid);
    if (!$mission) {
        redirect($baseurl);
    }

    if ($confirm && confirm_sesskey()) {
        mission_manager::delete_mission_full($missionid);
        redirect($baseurl, get_string('manage_deleted', 'local_aimissions'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    // Page de confirmation.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('manage_title', 'local_aimissions'));
    $confirmurl = new moodle_url($baseurl, array('action' => 'delete', 'missionid' => $missionid,
        'confirm' => 1, 'sesskey' => sesskey()));
    echo $OUTPUT->confirm(
        get_string('manage_delete_confirm', 'local_aimissions', s($mission->title)),
        $confirmurl,
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_title', 'local_aimissions'));

$projects = $DB->get_records('local_aimissions_project', array('courseid' => $courseid), 'companyname ASC');

if (empty($projects)) {
    echo $OUTPUT->notification(get_string('manage_noprojects', 'local_aimissions'), 'info');
    echo $OUTPUT->footer();
    exit;
}

foreach ($projects as $project) {
    $groupname = $project->groupid
        ? format_string((string)$DB->get_field('groups', 'name', array('id' => (int)$project->groupid)))
        : get_string('manage_nogroup', 'local_aimissions');

    echo $OUTPUT->heading(s($project->companyname) . ' — ' . $groupname, 4);

    $missions = $DB->get_records('local_aimissions_mission',
        array('projectid' => $project->id), 'sprint ASC');
    if (empty($missions)) {
        continue;
    }

    // Sprint max du projet : seul le dernier sprint (ou une orpheline) est supprimable.
    $maxsprint = 0;
    foreach ($missions as $m) {
        $maxsprint = max($maxsprint, (int)$m->sprint);
    }

    $table = new html_table();
    $table->head = array(
        get_string('manage_col_sprint', 'local_aimissions'),
        get_string('manage_col_title', 'local_aimissions'),
        get_string('manage_col_status', 'local_aimissions'),
        get_string('manage_col_actions', 'local_aimissions'),
    );
    $table->attributes['class'] = 'generaltable';

    foreach ($missions as $m) {
        $cmexists = (int)$m->assigncmid > 0
            && $DB->record_exists('course_modules', array('id' => (int)$m->assigncmid));

        $title = s($m->title);
        if ($cmexists) {
            $title = html_writer::link(
                new moodle_url('/mod/assign/view.php', array('id' => (int)$m->assigncmid)), $title);
        }

        if (!$cmexists) {
            $statuscell = html_writer::span(
                get_string('manage_orphan', 'local_aimissions'), 'badge bg-warning text-dark');
        } else {
            $statuscell = s(get_string('mission_' . $m->status, 'local_aimissions'));
        }

        $actions = array();
        if ($cmexists) {
            if ($m->status === 'published') {
                $actions[] = html_writer::link(
                    new moodle_url($baseurl, array('action' => 'hide', 'missionid' => $m->id,
                        'sesskey' => sesskey())),
                    get_string('manage_hide', 'local_aimissions'),
                    array('class' => 'btn btn-sm btn-outline-secondary'));
            } else {
                $actions[] = html_writer::link(
                    new moodle_url($baseurl, array('action' => 'publish', 'missionid' => $m->id,
                        'sesskey' => sesskey())),
                    get_string('manage_publish', 'local_aimissions'),
                    array('class' => 'btn btn-sm btn-primary'));
            }
        }
        // Suppression : seulement le dernier sprint, ou une orpheline.
        if ((int)$m->sprint === $maxsprint || !$cmexists) {
            $actions[] = html_writer::link(
                new moodle_url($baseurl, array('action' => 'delete', 'missionid' => $m->id)),
                get_string('manage_delete', 'local_aimissions'),
                array('class' => 'btn btn-sm btn-outline-danger'));
        }

        $table->data[] = array((int)$m->sprint, $title, $statuscell, implode(' ', $actions));
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
