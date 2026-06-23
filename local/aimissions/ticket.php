<?php
/**
 * Fil de discussion « questions au client IA » (Agent 2).
 *
 *   - Étudiant (capacité askclient) : voit le fil de SON groupe (entreprise) et
 *     pose des questions ; le client répond en DIFFÉRÉ (délai selon le persona),
 *     et peut recadrer voire rompre la collaboration.
 *   - Enseignant (capacité review) : consulte en lecture seule le fil d'un
 *     projet donné (paramètre projectid).
 */

require_once(__DIR__ . '/../../config.php');

use local_aimissions\job_handler;

$courseid  = required_param('courseid', PARAM_INT);
$projectid = optional_param('projectid', 0, PARAM_INT);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);

$PAGE->set_url(new moodle_url('/local/aimissions/ticket.php',
    array('courseid' => $courseid, 'projectid' => $projectid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('ticket_title', 'local_aimissions'));
$PAGE->set_heading(format_string($course->fullname));

$isreviewer = has_capability('local/aimissions:review', $context);
$canask     = has_capability('local/aimissions:askclient', $context);

// -------------------------------------------------------------------------
//  Résolution du projet et du mode (étudiant propriétaire vs enseignant lecture)
// -------------------------------------------------------------------------
$myprojects = array(); // projets de l'utilisateur (via ses groupes)
foreach (groups_get_all_groups($courseid, $USER->id) as $g) {
    $p = $DB->get_record('local_aimissions_project',
        array('courseid' => $courseid, 'groupid' => (int)$g->id));
    if ($p) {
        $myprojects[(int)$p->id] = $p;
    }
}

$project   = null;
$readonly  = true;

if ($projectid > 0 && isset($myprojects[$projectid])) {
    // L'utilisateur est membre du groupe : il peut poser des questions.
    $project  = $myprojects[$projectid];
    $readonly = !$canask;
} else if ($projectid > 0 && $isreviewer) {
    // Enseignant : lecture seule d'un projet du cours.
    $candidate = $DB->get_record('local_aimissions_project', array('id' => $projectid));
    if ($candidate && (int)$candidate->courseid === $courseid) {
        $project  = $candidate;
        $readonly = true;
    }
} else if ($projectid === 0 && $canask) {
    // Pas de projet précisé : on résout via les groupes de l'étudiant.
    if (count($myprojects) === 1) {
        $project  = reset($myprojects);
        $readonly = false;
    }
    // Si plusieurs projets, on affichera un sélecteur plus bas.
}

// Mission en cours = dernier sprint PUBLIÉ (on ne divulgue pas au client un
// sprint encore en brouillon que les étudiants ne voient pas).
$currentmission = null;
if ($project) {
    $missions = $DB->get_records('local_aimissions_mission',
        array('projectid' => $project->id, 'status' => 'published'), 'sprint DESC', '*', 0, 1);
    $currentmission = $missions ? reset($missions) : null;
}

// -------------------------------------------------------------------------
//  Traitement d'une nouvelle question (étudiant propriétaire uniquement)
// -------------------------------------------------------------------------
// Si le client a rompu la collaboration, l'envoi est bloqué (jusqu'à reprise
// forcée par l'enseignant).
$ended = $project && (string)$project->clientstatus === 'ended';

if ($project && !$readonly && !$ended && ($data = data_submitted()) && confirm_sesskey()
        && isset($data->question)) {

    $question = trim(clean_param($data->question, PARAM_TEXT));
    if ($question !== '') {
        $question = \core_text::substr($question, 0, 1000);

        $ticket = new stdClass();
        $ticket->projectid    = (int)$project->id;
        $ticket->missionid    = $currentmission ? (int)$currentmission->id : 0;
        $ticket->userid       = $USER->id;
        $ticket->question     = $question;
        $ticket->answer       = null;
        $ticket->status       = 'pending';
        $ticket->reaction     = null;
        $ticket->timecreated  = time();
        $ticket->timeanswered = 0;
        $DB->insert_record('local_aimissions_ticket', $ticket);

        // Réponse DIFFÉRÉE : le client prend son temps. On planifie (ou rejoint)
        // un job de réponse pour le projet ; il traitera le lot de messages.
        job_handler::schedule_reply($project);
    }

    redirect(new moodle_url('/local/aimissions/ticket.php',
        array('courseid' => $courseid, 'projectid' => (int)$project->id)));
}

// -------------------------------------------------------------------------
//  Rendu
// -------------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ticket_title', 'local_aimissions'));

// Sélecteur si l'étudiant a plusieurs projets et aucun n'est sélectionné.
if (!$project && $canask && count($myprojects) > 1) {
    echo html_writer::div(get_string('ticket_chooseproject', 'local_aimissions'), 'mb-2');
    echo html_writer::start_tag('ul');
    foreach ($myprojects as $p) {
        $gname = format_string((string)$DB->get_field('groups', 'name', array('id' => (int)$p->groupid)));
        echo html_writer::tag('li', html_writer::link(
            new moodle_url('/local/aimissions/ticket.php',
                array('courseid' => $courseid, 'projectid' => (int)$p->id)),
            s($p->companyname) . ' — ' . $gname));
    }
    echo html_writer::end_tag('ul');
    echo $OUTPUT->footer();
    exit;
}

if (!$project) {
    echo $OUTPUT->notification(get_string('ticket_noproject', 'local_aimissions'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Bandeau « entreprise ».
$contact = trim((string)$project->persona);
echo html_writer::div(
    html_writer::tag('strong', s($project->companyname))
    . ($contact !== '' ? ' — ' . s($contact) : ''),
    'alert alert-secondary');

// Fil de discussion : tickets (question/réponse) + événements (communications
// spontanées du client), fusionnés chronologiquement.
$tickets = $DB->get_records('local_aimissions_ticket',
    array('projectid' => $project->id), 'timecreated ASC');

$eventparams = array('projectid' => $project->id);
if (!$readonly) {
    $eventparams['applied'] = 1; // l'étudiant ne voit que les événements publiés
}
$events = $DB->get_records('local_aimissions_event', $eventparams, 'timecreated ASC');

$timeline = array();
foreach ($tickets as $t) {
    $timeline[] = array('time' => (int)$t->timecreated, 'kind' => 'ticket', 'data' => $t);
}
foreach ($events as $ev) {
    $timeline[] = array('time' => (int)$ev->timecreated, 'kind' => 'event', 'data' => $ev);
}
usort($timeline, function($a, $b) {
    return $a['time'] <=> $b['time'];
});

if (empty($timeline)) {
    echo html_writer::div(get_string('ticket_empty', 'local_aimissions'), 'text-muted mb-3');
} else {
    $etypes = array(
        'besoin' => get_string('event_besoin', 'local_aimissions'),
        'bug'    => get_string('event_bug', 'local_aimissions'),
        'rgpd'   => get_string('event_rgpd', 'local_aimissions'),
        'budget' => get_string('event_budget', 'local_aimissions'),
        'resume' => get_string('event_resume', 'local_aimissions'),
    );
    $now = time();
    foreach ($timeline as $item) {
        if ($item['kind'] === 'event') {
            // Communication du client (événement ou reprise).
            $ev = $item['data'];
            $isresume  = ((string)$ev->type === 'resume');
            $typelabel = $etypes[$ev->type] ?? $ev->type;
            $badgecls  = $isresume ? 'badge bg-success' : 'badge bg-info text-white';
            $boxcls    = $isresume ? 'border rounded p-2 mb-3 border-success'
                                   : 'border rounded p-2 mb-3 border-info';
            $draft = (!$ev->applied && $readonly)
                ? ' ' . html_writer::span(get_string('event_pending', 'local_aimissions'),
                    'badge bg-secondary text-white')
                : '';
            echo html_writer::div(
                html_writer::tag('div',
                    html_writer::tag('strong', s($project->companyname))
                    . ' · ' . html_writer::span(s($typelabel), $badgecls)
                    . $draft
                    . ' · ' . userdate((int)$ev->timecreated,
                        get_string('strftimedatetimeshort', 'langconfig')),
                    array('class' => 'small'))
                . html_writer::div(format_text((string)$ev->body, FORMAT_PLAIN), 'mt-1'),
                $boxcls);
            continue;
        }

        // Ticket : message étudiant + éventuelle réponse du client.
        $t = $item['data'];
        $asker = \core_user::get_user((int)$t->userid);
        $askername = $asker ? fullname($asker) : '?';
        echo html_writer::div(
            html_writer::tag('div', s($askername) . ' · '
                . userdate((int)$t->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                array('class' => 'small text-muted'))
            . html_writer::div(s($t->question), 'mt-1'),
            'border rounded p-2 mb-1 bg-light');

        if ($t->status === 'pending') {
            // En attente : réponse différée, on indique le temps écoulé.
            echo html_writer::div(
                get_string('ticket_waiting', 'local_aimissions', format_time($now - (int)$t->timecreated)),
                'text-muted fst-italic ms-4 mb-3');
        } else if ($t->status === 'failed') {
            echo html_writer::div(
                html_writer::tag('div', s($project->companyname), array('class' => 'small text-muted'))
                . html_writer::div(get_string('ticket_failed', 'local_aimissions'), 'mt-1'),
                'border rounded p-2 mb-3 ms-4 border-danger');
        } else if (trim((string)$t->answer) !== '') {
            // Réponse du client. Les messages REGROUPÉS ont answer=null (pas de bulle).
            $reaction = (string)$t->reaction;
            if ($reaction === 'ended') {
                $boxcls = 'border rounded p-2 mb-3 ms-4 border-danger';
                $tag = ' ' . html_writer::span(get_string('reaction_ended', 'local_aimissions'), 'badge bg-danger');
            } else if ($reaction === 'warning') {
                $boxcls = 'border rounded p-2 mb-3 ms-4 border-warning';
                $tag = ' ' . html_writer::span(get_string('reaction_warning', 'local_aimissions'),
                    'badge bg-warning text-dark');
            } else {
                $boxcls = 'border rounded p-2 mb-3 ms-4';
                $tag = '';
            }
            echo html_writer::div(
                html_writer::tag('div', s($project->companyname) . $tag, array('class' => 'small text-muted'))
                . html_writer::div(format_text((string)$t->answer, FORMAT_PLAIN), 'mt-1'),
                $boxcls);
        }
    }
}

// Formulaire de question (étudiant propriétaire), sauf si le client a rompu.
if (!$readonly && $ended) {
    echo $OUTPUT->notification(get_string('ticket_ended', 'local_aimissions'), 'error');
} else if (!$readonly) {
    if ((string)$project->clientstatus === 'warned') {
        echo $OUTPUT->notification(get_string('ticket_warned', 'local_aimissions'), 'warning');
    }
    echo html_writer::start_tag('form', array('method' => 'post',
        'action' => $PAGE->url->out(false)));
    echo html_writer::empty_tag('input', array('type' => 'hidden',
        'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::tag('label', get_string('ticket_yourquestion', 'local_aimissions'),
        array('for' => 'aimissions_question', 'class' => 'form-label fw-bold'));
    echo html_writer::tag('textarea', '', array('id' => 'aimissions_question',
        'name' => 'question', 'rows' => 3, 'class' => 'form-control mb-2',
        'maxlength' => 1000, 'required' => 'required'));
    echo html_writer::tag('button', get_string('ticket_send', 'local_aimissions'),
        array('type' => 'submit', 'class' => 'btn btn-primary'));
    echo html_writer::end_tag('form');
    echo html_writer::div(get_string('ticket_delayhint', 'local_aimissions'), 'small text-muted mt-1');
} else if ($isreviewer) {
    echo html_writer::div(get_string('ticket_readonly', 'local_aimissions'), 'text-muted mt-2');
}

echo $OUTPUT->footer();
