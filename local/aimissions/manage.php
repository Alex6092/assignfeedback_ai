<?php
/**
 * Revue et publication des missions générées d'un cours.
 *
 * Les missions sont créées en devoirs CACHÉS (visible=0). L'enseignant les
 * relit (lien vers le devoir) puis les publie (rend le module visible). Il
 * peut aussi re-masquer une mission publiée.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$missionid = optional_param('missionid', 0, PARAM_INT);

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

// --- Action publier / masquer --------------------------------------------
if ($action !== '' && $missionid > 0 && confirm_sesskey()) {
    $mission = $DB->get_record('local_aimissions_mission', array('id' => $missionid), '*', MUST_EXIST);
    $project = $DB->get_record('local_aimissions_project', array('id' => $mission->projectid), '*', MUST_EXIST);
    if ((int)$project->courseid === $courseid && (int)$mission->assigncmid > 0) {
        if ($action === 'publish') {
            set_coursemodule_visible($mission->assigncmid, 1);
            $DB->set_field('local_aimissions_mission', 'status', 'published', array('id' => $missionid));
        } else if ($action === 'hide') {
            set_coursemodule_visible($mission->assigncmid, 0);
            $DB->set_field('local_aimissions_mission', 'status', 'draft', array('id' => $missionid));
        }
        rebuild_course_cache($courseid, true);
    }
    redirect($baseurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_title', 'local_aimissions'));

// Projets du cours + missions, regroupés par projet (= par groupe/entreprise).
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

    $table = new html_table();
    $table->head = array(
        get_string('manage_col_sprint', 'local_aimissions'),
        get_string('manage_col_title', 'local_aimissions'),
        get_string('manage_col_status', 'local_aimissions'),
        get_string('manage_col_actions', 'local_aimissions'),
    );
    $table->attributes['class'] = 'generaltable';

    foreach ($missions as $m) {
        $title = s($m->title);
        if ((int)$m->assigncmid > 0) {
            $title = html_writer::link(
                new moodle_url('/mod/assign/view.php', array('id' => (int)$m->assigncmid)), $title);
        }

        $statusstr = get_string('mission_' . $m->status, 'local_aimissions');

        $actions = '';
        if ($m->status === 'published') {
            $actions = html_writer::link(
                new moodle_url($baseurl, array('action' => 'hide', 'missionid' => $m->id,
                    'sesskey' => sesskey())),
                get_string('manage_hide', 'local_aimissions'),
                array('class' => 'btn btn-sm btn-outline-secondary'));
        } else {
            $actions = html_writer::link(
                new moodle_url($baseurl, array('action' => 'publish', 'missionid' => $m->id,
                    'sesskey' => sesskey())),
                get_string('manage_publish', 'local_aimissions'),
                array('class' => 'btn btn-sm btn-primary'));
        }

        $table->data[] = array((int)$m->sprint, $title, s($statusstr), $actions);
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
