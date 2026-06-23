<?php
/**
 * Fil de discussion « questions au client IA » (Agent 2).
 *
 *   - Étudiant (capacité askclient) : voit le fil de SON groupe (entreprise) et
 *     pose des questions ; le client IA répond en synchrone.
 *   - Enseignant (capacité review) : consulte en lecture seule le fil d'un
 *     projet donné (paramètre projectid).
 */

require_once(__DIR__ . '/../../config.php');

use local_aimissions\client;

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
if ($project && !$readonly && ($data = data_submitted()) && confirm_sesskey()
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
        $ticket->timecreated  = time();
        $ticket->timeanswered = 0;
        $ticket->id = $DB->insert_record('local_aimissions_ticket', $ticket);

        try {
            $answer = client::answer($project, $currentmission, $question);
            $ticket->answer       = $answer;
            $ticket->status       = 'answered';
            $ticket->timeanswered = time();
        } catch (\Throwable $e) {
            // On ne montre pas l'erreur brute à l'étudiant ; on la journalise.
            debugging('[local_aimissions] client answer failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $ticket->answer = get_string('ticket_failed', 'local_aimissions');
            $ticket->status = 'failed';
        }
        $DB->update_record('local_aimissions_ticket', $ticket);

        // Les précisions données par le client comptent dans la correction.
        if ($ticket->status === 'answered' && (int)$ticket->missionid > 0) {
            \local_aimissions\correction_sync::sync_for_mission((int)$ticket->missionid);
        }
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
    );
    foreach ($timeline as $item) {
        if ($item['kind'] === 'event') {
            // Communication spontanée du client (événement).
            $ev = $item['data'];
            $typelabel = $etypes[$ev->type] ?? $ev->type;
            $draft = (!$ev->applied && $readonly)
                ? ' ' . html_writer::span(get_string('event_pending', 'local_aimissions'),
                    'badge bg-secondary text-white')
                : '';
            echo html_writer::div(
                html_writer::tag('div',
                    html_writer::tag('strong', s($project->companyname))
                    . ' · ' . html_writer::span(s($typelabel), 'badge bg-info text-white')
                    . $draft
                    . ' · ' . userdate((int)$ev->timecreated,
                        get_string('strftimedatetimeshort', 'langconfig')),
                    array('class' => 'small'))
                . html_writer::div(format_text((string)$ev->body, FORMAT_PLAIN), 'mt-1'),
                'border rounded p-2 mb-3 border-info');
            continue;
        }

        // Ticket : question (étudiant) + réponse (client).
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
            echo html_writer::div(get_string('ticket_pending', 'local_aimissions'),
                'text-muted fst-italic ms-4 mb-3');
        } else {
            $cls = $t->status === 'failed' ? 'border rounded p-2 mb-3 ms-4 border-danger'
                                           : 'border rounded p-2 mb-3 ms-4';
            echo html_writer::div(
                html_writer::tag('div', s($project->companyname), array('class' => 'small text-muted'))
                . html_writer::div(format_text((string)$t->answer, FORMAT_PLAIN), 'mt-1'),
                $cls);
        }
    }
}

// Formulaire de question (étudiant propriétaire).
if (!$readonly) {
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
} else if ($isreviewer) {
    echo html_writer::div(get_string('ticket_readonly', 'local_aimissions'), 'text-muted mt-2');
}

echo $OUTPUT->footer();
