<?php
defined('MOODLE_INTERNAL') || true;

require_once(dirname(dirname(dirname(dirname(dirname(__FILE__))))).'/config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(dirname(__FILE__) . '/locallib.php');

require_sesskey();

$cmid    = required_param('id',      PARAM_INT);
$userid  = required_param('userid',  PARAM_INT);
$gradeid = required_param('gradeid', PARAM_INT); // assign_grades.id

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('assignfeedback/ai:generate', $context);

$assign = new assign($context, $cm, $course);

header('Content-Type: application/json; charset=utf-8');

try {
    $assignid = (int)$assign->get_instance()->id;

    // Si gradeid n'est pas fourni de façon valide, on crée le grade fantôme.
    if ($gradeid <= 0) {
        $grade = $assign->get_user_grade($userid, true);
        if (!$grade) {
            throw new moodle_exception('generationerror', 'assignfeedback_ai');
        }
        $gradeid = (int)$grade->id;
    }

    assign_feedback_ai::enqueue_for_grade($assignid, $userid, $gradeid);

    echo json_encode(array(
        'success' => true,
        'message' => get_string('queued', 'assignfeedback_ai'),
    ));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage(),
    ));
}
