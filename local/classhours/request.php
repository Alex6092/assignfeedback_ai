<?php
/**
 * Page élève : demander un accès exceptionnel à une activité fermée (devoir ou
 * test restreint aux heures de cours). L'enseignant est prévenu et accepte ou
 * refuse depuis la page « Demandes d'accès ».
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');

use local_classhours\access;
use local_classhours\form\request_form;
use local_classhours\grant;

$cmid = required_param('cmid', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
// Connexion au COURS seulement : l'activité est justement fermée, un
// require_login() avec le module renverrait vers la page « activité restreinte ».
require_login($course);
$coursecontext = context_course::instance($course->id);
require_capability('local/classhours:requestaccess', $coursecontext);

$courseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$pageurl = new moodle_url('/local/classhours/request.php', array('cmid' => $cmid));

// Seulement un devoir ou un test visible, restreint et fermé pour l'élève.
if (!$cm->visible || !in_array($cm->modname, access::READ_MODS, true)
        || !access::is_closed_for($cm, (int)$USER->id)) {
    redirect($courseurl, get_string('request_notneeded', 'local_classhours'));
}

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('request_heading', 'local_classhours'));
$PAGE->set_heading(format_string($course->fullname));

$activityname = format_string($cm->name, true, array('context' => context_module::instance($cm->id)));

if (grant::pending_id((int)$course->id, (int)$cm->id, (int)$USER->id) !== null) {
    redirect($courseurl, get_string('request_already', 'local_classhours', $activityname));
}

$form = new request_form($pageurl);
$form->set_data(array('cmid' => $cmid));
if ($form->is_cancelled()) {
    redirect($courseurl);
}
if ($data = $form->get_data()) {
    grant::request($cm, (int)$USER->id, (string)($data->reason ?? ''));
    redirect($courseurl, get_string('request_sent', 'local_classhours', $activityname), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('request_heading', 'local_classhours'));
echo html_writer::tag('p', get_string('request_intro', 'local_classhours', $activityname));
$form->display();
echo $OUTPUT->footer();
