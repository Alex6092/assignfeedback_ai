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
$eventid   = optional_param('eventid', 0, PARAM_INT);
$projectid = optional_param('projectid', 0, PARAM_INT);
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

// --- Actions sur un événement (publier / masquer / supprimer) ------------
if (($action === 'eventpublish' || $action === 'eventhide' || $action === 'eventdelete')
        && $eventid > 0 && confirm_sesskey()) {
    $ev = $DB->get_record('local_aimissions_event', array('id' => $eventid));
    if ($ev) {
        $proj = $DB->get_record('local_aimissions_project', array('id' => $ev->projectid));
        if ($proj && (int)$proj->courseid === $courseid) {
            if ($action === 'eventpublish') {
                $DB->set_field('local_aimissions_event', 'applied', 1, array('id' => $eventid));
            } else if ($action === 'eventhide') {
                $DB->set_field('local_aimissions_event', 'applied', 0, array('id' => $eventid));
            } else {
                $DB->delete_records('local_aimissions_event', array('id' => $eventid));
            }
            // L'ensemble des consignes publiées a changé : on resynchronise
            // la grille de correction du devoir concerné.
            if ((int)$ev->missionid > 0) {
                \local_aimissions\correction_sync::sync_for_mission((int)$ev->missionid);
            }
        }
    }
    redirect($baseurl);
}

// --- Action : forcer la reprise d'un projet rompu ------------------------
if ($action === 'resume' && $projectid > 0 && confirm_sesskey()) {
    $proj = $DB->get_record('local_aimissions_project', array('id' => $projectid));
    if ($proj && (int)$proj->courseid === $courseid && (string)$proj->clientstatus === 'ended') {
        $DB->set_field('local_aimissions_project', 'clientstatus', 'active', array('id' => $projectid));
        $DB->set_field('local_aimissions_project', 'timemodified', time(), array('id' => $projectid));
        // Message de reprise visible dans le fil (event 'resume', publié).
        $ev = new stdClass();
        $ev->projectid   = $projectid;
        $ev->missionid   = 0;
        $ev->type        = 'resume';
        $ev->body        = get_string('resume_message', 'local_aimissions');
        $ev->applied     = 1;
        $ev->timecreated = time();
        $DB->insert_record('local_aimissions_event', $ev);
    }
    redirect($baseurl, get_string('resume_done', 'local_aimissions'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// --- Action : déclencher MAINTENANT la réponse du client -----------------
if ($action === 'forcereply' && $projectid > 0 && confirm_sesskey()) {
    $proj = $DB->get_record('local_aimissions_project', array('id' => $projectid));
    if ($proj && (int)$proj->courseid === $courseid) {
        $job = $DB->get_record_select('local_aimissions_job',
            "projectid = ? AND kind = 'ticket' AND status = 'pending'",
            array($projectid), '*', IGNORE_MULTIPLE);
        if ($job) {
            \core_php_time_limit::raise(120);
            try {
                // execute() relance l'exception en cas d'échec ; on l'absorbe
                // ici (le job est déjà marqué/réenfilé par record_failure) pour
                // ne pas crasher la page enseignant.
                (new \local_aimissions\job_handler())->execute((object)array('rowid' => (int)$job->id));
            } catch (\Throwable $e) {
                debugging('[local_aimissions] forcereply failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
    redirect($baseurl, get_string('forcereply_done', 'local_aimissions'), null,
        \core\output\notification::NOTIFY_SUCCESS);
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

echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/aimissions/event.php', array('courseid' => $courseid)),
        get_string('manage_injectevent', 'local_aimissions'),
        array('class' => 'btn btn-outline-primary')),
    'mb-3');

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

    // Lien vers le fil de questions du groupe (lecture seule pour l'enseignant).
    $nbtickets = $DB->count_records('local_aimissions_ticket', array('projectid' => $project->id));
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/aimissions/ticket.php',
                array('courseid' => $courseid, 'projectid' => (int)$project->id)),
            get_string('manage_viewtickets', 'local_aimissions', $nbtickets))
        . ' · ' .
        html_writer::link(
            new moodle_url('/local/aimissions/comm.php',
                array('courseid' => $courseid, 'projectid' => (int)$project->id)),
            get_string('manage_evalcomm', 'local_aimissions')),
        'mb-2');

    // État de la relation client (jalons) + actions.
    $cs = (string)$project->clientstatus;
    if ($cs === 'ended') {
        $statusbadge = html_writer::span(get_string('clientstatus_ended', 'local_aimissions'), 'badge bg-danger');
    } else if ($cs === 'warned') {
        $statusbadge = html_writer::span(
            get_string('clientstatus_warned', 'local_aimissions', (int)$project->clientwarnings),
            'badge bg-warning text-dark');
    } else {
        $statusbadge = html_writer::span(get_string('clientstatus_active', 'local_aimissions'), 'badge bg-success');
    }
    $pendingreplies = $DB->count_records('local_aimissions_ticket',
        array('projectid' => $project->id, 'status' => 'pending'));

    $statusline = get_string('clientstatus_label', 'local_aimissions') . ' ' . $statusbadge;
    if ($pendingreplies > 0) {
        $statusline .= ' · ' . html_writer::span(
            get_string('reply_pending', 'local_aimissions', $pendingreplies), 'badge bg-secondary text-white');
    }
    $cactions = array();
    if ($pendingreplies > 0) {
        $cactions[] = html_writer::link(
            new moodle_url($baseurl, array('action' => 'forcereply', 'projectid' => (int)$project->id,
                'sesskey' => sesskey())),
            get_string('forcereply', 'local_aimissions'),
            array('class' => 'btn btn-sm btn-outline-primary'));
    }
    if ($cs === 'ended') {
        $cactions[] = html_writer::link(
            new moodle_url($baseurl, array('action' => 'resume', 'projectid' => (int)$project->id,
                'sesskey' => sesskey())),
            get_string('resume_action', 'local_aimissions'),
            array('class' => 'btn btn-sm btn-success'));
    }
    echo html_writer::div($statusline . (empty($cactions) ? '' : ' ' . implode(' ', $cactions)), 'mb-3');

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

    // Événements générés pour ce projet (communications client).
    $events = $DB->get_records('local_aimissions_event',
        array('projectid' => $project->id), 'timecreated ASC');
    if ($events) {
        $etypes = array(
            'besoin' => get_string('event_besoin', 'local_aimissions'),
            'bug'    => get_string('event_bug', 'local_aimissions'),
            'rgpd'   => get_string('event_rgpd', 'local_aimissions'),
            'budget' => get_string('event_budget', 'local_aimissions'),
        );
        echo html_writer::tag('div', get_string('manage_events', 'local_aimissions'),
            array('class' => 'fw-bold mt-2 mb-1'));
        foreach ($events as $ev) {
            $typelabel = $etypes[$ev->type] ?? $ev->type;
            $statusbadge = $ev->applied
                ? html_writer::span(get_string('event_published', 'local_aimissions'), 'badge bg-success')
                : html_writer::span(get_string('event_pending', 'local_aimissions'), 'badge bg-secondary text-white');

            $evactions = array();
            if ($ev->applied) {
                $evactions[] = html_writer::link(new moodle_url($baseurl,
                    array('action' => 'eventhide', 'eventid' => $ev->id, 'sesskey' => sesskey())),
                    get_string('manage_hide', 'local_aimissions'),
                    array('class' => 'btn btn-sm btn-outline-secondary'));
            } else {
                $evactions[] = html_writer::link(new moodle_url($baseurl,
                    array('action' => 'eventpublish', 'eventid' => $ev->id, 'sesskey' => sesskey())),
                    get_string('manage_publish', 'local_aimissions'),
                    array('class' => 'btn btn-sm btn-primary'));
            }
            $evactions[] = html_writer::link(new moodle_url($baseurl,
                array('action' => 'eventdelete', 'eventid' => $ev->id, 'sesskey' => sesskey())),
                get_string('manage_delete', 'local_aimissions'),
                array('class' => 'btn btn-sm btn-outline-danger'));

            echo html_writer::div(
                html_writer::tag('span', '[' . s($typelabel) . '] ', array('class' => 'fw-bold'))
                . $statusbadge
                . html_writer::div(format_text((string)$ev->body, FORMAT_PLAIN), 'mt-1')
                . html_writer::div(implode(' ', $evactions), 'mt-1'),
                'border rounded p-2 mb-2');
        }
    }
}

echo $OUTPUT->footer();
