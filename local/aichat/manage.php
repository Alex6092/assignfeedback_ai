<?php
/**
 * Page enseignant du tuteur IA pour une activité : relecture du brief
 * pédagogique, statistiques d'usage et consultation des conversations.
 *
 * @package local_aichat
 */

require_once(__DIR__ . '/../../config.php');

use local_aichat\activity;
use local_aichat\brief;
use local_aichat\conversation;
use local_aichat\moderation;

$cmid           = required_param('id', PARAM_INT);
$conversationid = optional_param('conversation', 0, PARAM_INT);
$action         = optional_param('action', '', PARAM_ALPHA);
$flaggedonly    = optional_param('flagged', 0, PARAM_BOOL);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$canconfigure = has_capability('local/aichat:configure', $context);
$canview      = has_capability('local/aichat:viewconversations', $context);
if (!$canconfigure && !$canview) {
    require_capability('local/aichat:configure', $context);
}

$pageurl = new moodle_url('/local/aichat/manage.php', array('id' => $cmid));
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('managepagetitle', 'local_aichat'));
$PAGE->set_heading(format_string($course->fullname));

$config = activity::get($cmid);

// ---------------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------------
if ($action !== '' && $canconfigure) {
    require_sesskey();

    if ($action === 'regenerate') {
        if (brief::schedule_if_stale($cmid, true)) {
            redirect($pageurl, get_string('brief_queued', 'local_aichat'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($pageurl, get_string('brief_notavailable', 'local_aichat'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    if ($action === 'savebrief' && $config !== null) {
        $text = trim(optional_param('brief', '', PARAM_RAW));
        activity::save($cmid, (int)$course->id, array(
            'brief'       => ($text === '') ? null : $text,
            'briefstatus' => ($text === '') ? 'none' : 'ready',
            'brieferror'  => null,
        ));
        redirect($pageurl, get_string('brief_saved', 'local_aichat'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Relance des analyses de modération échouées d'une conversation.
if ($action === 'reanalyse' && $canview && $conversationid > 0) {
    require_sesskey();
    $DB->get_record('local_aichat_conversation',
        array('id' => $conversationid, 'cmid' => $cmid), 'id', MUST_EXIST);
    $count = moderation::reschedule_failed($conversationid);
    redirect(new moodle_url($pageurl, array('conversation' => $conversationid)),
        get_string('moderation_requeued', 'local_aichat', $count), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managepagetitle', 'local_aichat'));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/mod/' . $cm->modname . '/view.php', array('id' => $cmid)),
    format_string($cm->name)), array('class' => 'lead'));

if ($config === null || empty($config->enabled)) {
    echo $OUTPUT->notification(get_string('notenabledhere', 'local_aichat'),
        \core\output\notification::NOTIFY_WARNING);
}

// ---------------------------------------------------------------------------
//  Transcription d'une conversation
// ---------------------------------------------------------------------------
if ($conversationid > 0) {
    require_capability('local/aichat:viewconversations', $context);
    $conv = $DB->get_record('local_aichat_conversation',
        array('id' => $conversationid, 'cmid' => $cmid), '*', MUST_EXIST);
    $student = $DB->get_record('user', array('id' => $conv->userid));

    echo $OUTPUT->heading(get_string('transcript_for', 'local_aichat',
        $student ? fullname($student) : $conv->userid), 3);
    echo html_writer::tag('p', html_writer::link($pageurl,
        '← ' . get_string('backtolist', 'local_aichat')));

    $messages = conversation::messages($conv->id);
    $hasfailed = false;
    foreach ($messages as $message) {
        if ($message->role === 'user' && $message->flagstatus === 'failed') {
            $hasfailed = true;
        }
    }
    if ($hasfailed) {
        echo html_writer::tag('p', html_writer::link(
            new moodle_url($pageurl, array('conversation' => $conv->id,
                'action' => 'reanalyse', 'sesskey' => sesskey())),
            get_string('moderation_reanalyse', 'local_aichat'),
            array('class' => 'btn btn-sm btn-outline-secondary')));
    }

    foreach ($messages as $message) {
        $isuser = ($message->role === 'user');
        $body   = $isuser
            ? html_writer::tag('div', nl2br(s((string)$message->content)))
            : format_text((string)$message->contenthtml, FORMAT_HTML,
                array('context' => $context, 'noclean' => false));
        if (!$isuser && trim((string)$message->contenthtml) === '') {
            $body = html_writer::tag('em', s(get_string('status_' . $message->status,
                'local_aichat')));
        }

        // Résultat de la modération, sous le message de l'élève.
        $flaghtml = '';
        if ($isuser && $message->flagstatus === 'flagged') {
            $category = get_string('flagcat_' . $message->flagcategory, 'local_aichat');
            $flaghtml = html_writer::div(
                html_writer::tag('strong', get_string('flag_label', 'local_aichat', $category))
                . (trim((string)$message->flagreason) !== ''
                    ? ' — ' . s((string)$message->flagreason) : ''),
                'alert alert-danger mt-2 mb-0 py-2');
        } else if ($isuser && in_array($message->flagstatus, array('pending', 'retry'), true)) {
            $flaghtml = html_writer::div(get_string('flag_pending', 'local_aichat'),
                'text-muted small mt-1');
        } else if ($isuser && $message->flagstatus === 'failed') {
            $flaghtml = html_writer::div(get_string('flag_failed', 'local_aichat'),
                'text-warning small mt-1');
        }
        $body .= $flaghtml;

        echo html_writer::div(
            html_writer::tag('div',
                html_writer::tag('strong', $isuser
                    ? ($student ? fullname($student) : get_string('student', 'local_aichat'))
                    : get_string('pluginname', 'local_aichat'))
                . ' · ' . html_writer::tag('span', userdate($message->timecreated),
                    array('class' => 'text-muted small')),
                array('class' => 'mb-1'))
            . $body,
            'card card-body mb-2 ' . ($isuser ? 'bg-light' : '')
        );
    }

    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
//  Brief pédagogique
// ---------------------------------------------------------------------------
if ($canconfigure) {
    echo $OUTPUT->heading(get_string('brief_heading', 'local_aichat'), 3);
    echo html_writer::tag('p', get_string('brief_explain', 'local_aichat'),
        array('class' => 'text-muted'));

    $status = ($config !== null) ? $config->briefstatus : 'none';
    $badges = array('ready' => 'success', 'pending' => 'info',
                    'failed' => 'danger', 'none' => 'secondary');
    echo html_writer::tag('p',
        html_writer::span(get_string('briefstatus_' . $status, 'local_aichat'),
            'badge badge-' . (isset($badges[$status]) ? $badges[$status] : 'secondary')));

    if ($config !== null && $status === 'failed' && !empty($config->brieferror)) {
        echo $OUTPUT->notification(s($config->brieferror),
            \core\output\notification::NOTIFY_ERROR);
    }

    $stale = false;
    if ($config !== null && $status === 'ready') {
        $fbcfg = brief::source_config($cm);
        $stale = ($fbcfg !== null && brief::hash($fbcfg) !== $config->briefhash);
    }
    if ($stale) {
        echo $OUTPUT->notification(get_string('brief_stale', 'local_aichat'),
            \core\output\notification::NOTIFY_WARNING);
    }

    // Édition libre : l'enseignant reste maître du texte donné au tuteur.
    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false)));
    echo html_writer::empty_tag('input', array('type' => 'hidden',
        'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden',
        'name' => 'action', 'value' => 'savebrief'));
    echo html_writer::tag('textarea',
        s(($config !== null) ? (string)$config->brief : ''),
        array('name' => 'brief', 'rows' => 12, 'class' => 'form-control mb-2'));
    echo html_writer::tag('button', get_string('brief_save', 'local_aichat'),
        array('type' => 'submit', 'class' => 'btn btn-primary mr-2'));
    echo html_writer::link(
        new moodle_url($pageurl, array('action' => 'regenerate', 'sesskey' => sesskey())),
        get_string('brief_regenerate', 'local_aichat'),
        array('class' => 'btn btn-secondary'));
    echo html_writer::end_tag('form');
}

// ---------------------------------------------------------------------------
//  Conversations
// ---------------------------------------------------------------------------
if ($canview) {
    echo $OUTPUT->heading(get_string('conversations_heading', 'local_aichat'), 3);

    $rows = $DB->get_records_sql(
        "SELECT c.id, c.userid, c.status, c.msgcount, c.timecreated, c.timemodified,
                (SELECT COUNT(1) FROM {local_aichat_message} m
                  WHERE m.conversationid = c.id AND m.role = :roleuser) AS questions,
                (SELECT COALESCE(SUM(m2.tokens), 0) FROM {local_aichat_message} m2
                  WHERE m2.conversationid = c.id) AS tokens,
                (SELECT COUNT(1) FROM {local_aichat_message} m3
                  WHERE m3.conversationid = c.id AND m3.flagstatus = :flagged) AS flags
           FROM {local_aichat_conversation} c
          WHERE c.cmid = :cmid
       ORDER BY c.timemodified DESC",
        array('roleuser' => 'user', 'flagged' => 'flagged', 'cmid' => $cmid));

    if (empty($rows)) {
        echo $OUTPUT->notification(get_string('noconversations', 'local_aichat'),
            \core\output\notification::NOTIFY_INFO);
    } else {
        $userids = array();
        foreach ($rows as $row) {
            $userids[(int)$row->userid] = true;
        }
        list($insql, $params) = $DB->get_in_or_equal(array_keys($userids));
        $users = $DB->get_records_select('user', "id $insql", $params, '',
            'id, ' . implode(', ', \core_user\fields::get_name_fields()));

        $totalq = 0;
        $totalt = 0;
        $totalf = 0;
        foreach ($rows as $row) {
            $totalq += (int)$row->questions;
            $totalt += (int)$row->tokens;
            $totalf += ((int)$row->flags > 0) ? 1 : 0;
        }
        echo html_writer::tag('p', get_string('stats_summary', 'local_aichat', (object)array(
            'conversations' => count($rows),
            'students'      => count($userids),
            'questions'     => $totalq,
            'tokens'        => $totalt,
        )), array('class' => 'text-muted'));

        // Filtre « conversations signalées », avec leur nombre en évidence.
        if ($totalf > 0 || $flaggedonly) {
            $filterlinks = $flaggedonly
                ? html_writer::link($pageurl, get_string('filter_all', 'local_aichat'),
                    array('class' => 'btn btn-sm btn-outline-secondary'))
                : html_writer::link(new moodle_url($pageurl, array('flagged' => 1)),
                    get_string('filter_flagged', 'local_aichat', $totalf),
                    array('class' => 'btn btn-sm btn-danger'));
            echo html_writer::tag('p', $filterlinks);
        }

        $table = new html_table();
        $table->head = array(
            get_string('student', 'local_aichat'),
            get_string('col_flags', 'local_aichat'),
            get_string('col_questions', 'local_aichat'),
            get_string('col_tokens', 'local_aichat'),
            get_string('col_lastactivity', 'local_aichat'),
            get_string('col_actions', 'local_aichat'),
        );
        $table->attributes['class'] = 'table table-sm table-striped';

        foreach ($rows as $row) {
            if ($flaggedonly && (int)$row->flags === 0) {
                continue;
            }
            $name = isset($users[$row->userid]) ? fullname($users[$row->userid])
                : get_string('deleteduser', 'local_aichat');
            $flagcell = ((int)$row->flags > 0)
                ? html_writer::span((int)$row->flags, 'badge badge-danger')
                : '';
            $table->data[] = array(
                s($name),
                $flagcell,
                (int)$row->questions,
                (int)$row->tokens,
                userdate($row->timemodified),
                html_writer::link(
                    new moodle_url($pageurl, array('conversation' => (int)$row->id)),
                    get_string('viewtranscript', 'local_aichat'),
                    array('class' => 'btn btn-sm btn-outline-secondary')),
            );
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
