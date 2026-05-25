<?php
defined('MOODLE_INTERNAL') || true;

require_once(dirname(dirname(dirname(dirname(dirname(__FILE__))))).'/config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(dirname(__FILE__) . '/locallib.php');

$cmid   = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('assignfeedback/ai:generate', $context);

$assign   = new assign($context, $cm, $course);
$assignid = (int)$assign->get_instance()->id;

$pageurl = new moodle_url('/mod/assign/feedback/ai/manage.php', array('id' => $cmid));
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('managepagetitle', 'assignfeedback_ai'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

// =========================================================
//  ACTIONS
// =========================================================

if ($action !== '') {
    require_sesskey();

    $count = 0;

    if ($action === 'requeue_one') {
        $rowid = required_param('rowid', PARAM_INT);
        $row   = $DB->get_record(assign_feedback_ai::TABLE_GRADE, array(
            'id'         => $rowid,
            'assignment' => $assignid,
        ), '*', MUST_EXIST);
        assign_feedback_ai::enqueue_for_grade($assignid, (int)$row->userid, (int)$row->grade);
        $count = 1;

    } else if ($action === 'requeue_failed') {
        $failed = $DB->get_records(assign_feedback_ai::TABLE_GRADE, array(
            'assignment' => $assignid,
            'status'     => assign_feedback_ai::STATUS_FAILED,
        ));
        foreach ($failed as $row) {
            assign_feedback_ai::enqueue_for_grade($assignid, (int)$row->userid, (int)$row->grade);
            $count++;
        }

    } else if ($action === 'requeue_all') {
        // Pour toutes les soumissions soumises, on (re)génère.
        $submissions = $DB->get_records('assign_submission', array(
            'assignment' => $assignid,
            'status'     => 'submitted',
            'latest'     => 1,
        ));
        foreach ($submissions as $sub) {
            $grade = $assign->get_user_grade((int)$sub->userid, true);
            if (!$grade) {
                continue;
            }
            assign_feedback_ai::enqueue_for_grade($assignid, (int)$sub->userid, (int)$grade->id);
            $count++;
        }

    } else if ($action === 'requeue_missing') {
        // Soumissions soumises sans ligne assignfeedback_ai_grade (rare, défense).
        $submissions = $DB->get_records('assign_submission', array(
            'assignment' => $assignid,
            'status'     => 'submitted',
            'latest'     => 1,
        ));
        foreach ($submissions as $sub) {
            $grade = $assign->get_user_grade((int)$sub->userid, true);
            if (!$grade) {
                continue;
            }
            $exists = $DB->record_exists(assign_feedback_ai::TABLE_GRADE, array(
                'assignment' => $assignid,
                'grade'      => (int)$grade->id,
            ));
            if (!$exists) {
                assign_feedback_ai::enqueue_for_grade($assignid, (int)$sub->userid, (int)$grade->id);
                $count++;
            }
        }
    }

    redirect($pageurl, get_string('queuedcount', 'assignfeedback_ai', $count));
}

// =========================================================
//  AFFICHAGE
// =========================================================

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managepagetitle', 'assignfeedback_ai'));

// Compteurs.
$counts = array(
    'pending'   => 0,
    'generated' => 0,
    'failed'    => 0,
);
foreach ($DB->get_records_sql(
    'SELECT status, COUNT(*) AS n FROM {' . assign_feedback_ai::TABLE_GRADE
    . '} WHERE assignment = ? GROUP BY status',
    array($assignid)
) as $r) {
    if (isset($counts[$r->status])) {
        $counts[$r->status] = (int)$r->n;
    }
}

echo html_writer::tag('p',
    html_writer::span(get_string('countgenerated', 'assignfeedback_ai', $counts['generated']),
        'badge badge-success mr-2') .
    html_writer::span(get_string('countpending', 'assignfeedback_ai', $counts['pending']),
        'badge badge-info mr-2') .
    html_writer::span(get_string('countfailed', 'assignfeedback_ai', $counts['failed']),
        'badge badge-danger')
);

// Boutons globaux.
$baseparams = array('id' => $cmid, 'sesskey' => sesskey());
echo html_writer::start_div('mb-3');
echo $OUTPUT->single_button(
    new moodle_url($pageurl, array_merge($baseparams, array('action' => 'requeue_failed'))),
    get_string('requeuefailed', 'assignfeedback_ai'), 'post',
    array('class' => 'mr-2')
);
echo $OUTPUT->single_button(
    new moodle_url($pageurl, array_merge($baseparams, array('action' => 'requeue_missing'))),
    get_string('requeuemissing', 'assignfeedback_ai'), 'post',
    array('class' => 'mr-2')
);
echo $OUTPUT->single_button(
    new moodle_url($pageurl, array_merge($baseparams, array('action' => 'requeue_all'))),
    get_string('requeueall', 'assignfeedback_ai'), 'post'
);
echo html_writer::end_div();

// Liste détaillée.
$sql = "SELECT s.id AS subid, s.userid,
               u.firstname, u.lastname, u.email,
               fb.id AS fbid, fb.status, fb.attempts, fb.error_message, fb.timemodified
          FROM {assign_submission} s
          JOIN {user} u ON u.id = s.userid
     LEFT JOIN {" . assign_feedback_ai::TABLE_GRADE . "} fb
            ON fb.assignment = s.assignment AND fb.userid = s.userid
         WHERE s.assignment = :assignid
           AND s.status = :submitted
           AND s.latest = 1
      ORDER BY u.lastname, u.firstname";
$rows = $DB->get_records_sql($sql, array(
    'assignid'  => $assignid,
    'submitted' => 'submitted',
));

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('nosubmissions', 'assignfeedback_ai'), 'info');
} else {
    $table = new html_table();
    $table->head = array(
        get_string('student',       'assignfeedback_ai'),
        get_string('status',        'assignfeedback_ai'),
        get_string('attempts',      'assignfeedback_ai'),
        get_string('lasterror',     'assignfeedback_ai'),
        get_string('lastupdate',    'assignfeedback_ai'),
        get_string('actions',       'assignfeedback_ai'),
    );
    $table->attributes['class'] = 'generaltable';

    foreach ($rows as $r) {
        $name = fullname($r);

        if (empty($r->fbid)) {
            $statushtml = html_writer::span(
                get_string('nofbrow', 'assignfeedback_ai'), 'badge badge-secondary');
            $action_btn = '';
        } else {
            switch ($r->status) {
                case assign_feedback_ai::STATUS_GENERATED:
                    $cls = 'success'; $lbl = get_string('statusgenerated', 'assignfeedback_ai'); break;
                case assign_feedback_ai::STATUS_FAILED:
                    $cls = 'danger';  $lbl = get_string('statusfailed',    'assignfeedback_ai'); break;
                default:
                    $cls = 'info';    $lbl = get_string('statuspending',   'assignfeedback_ai'); break;
            }
            $statushtml = html_writer::span($lbl, 'badge badge-' . $cls);

            $btnurl = new moodle_url($pageurl, array_merge(
                $baseparams,
                array('action' => 'requeue_one', 'rowid' => (int)$r->fbid)
            ));
            $action_btn = $OUTPUT->single_button($btnurl,
                get_string('retry', 'assignfeedback_ai'), 'post',
                array('class' => 'btn-sm'));
        }

        $err = !empty($r->error_message)
            ? html_writer::tag('small', s($r->error_message), array('class' => 'text-muted'))
            : '—';

        $time = !empty($r->timemodified) ? userdate((int)$r->timemodified) : '—';

        $table->data[] = array(
            $name,
            $statushtml,
            !empty($r->fbid) ? (int)$r->attempts : '—',
            $err,
            $time,
            $action_btn,
        );
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
