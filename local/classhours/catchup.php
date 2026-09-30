<?php
/**
 * Élève : demander à rattraper une activité surveillée qui a eu lieu sans
 * lui (absence). Les enseignants sont prévenus et l'ouvrent pour lui depuis
 * la page Activités surveillées.
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');

use local_classhours\catchup;

$cmid = required_param('cmid', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
// Connexion au cours seulement : l'activité est fermée pour l'élève.
require_login($course);
require_sesskey();

$back = $returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/course/view.php', array('id' => $course->id));
$activityname = format_string($cm->name, true, array('context' => context_module::instance($cm->id)));

if (!$cm->visible || !catchup::is_due((int)$course->id, $cm, (int)$USER->id)) {
    redirect($back, get_string('catchup_notneeded', 'local_classhours'));
}
catchup::request($cm, (int)$USER->id);
redirect($back, get_string('catchup_sent', 'local_classhours', $activityname), null,
    \core\output\notification::NOTIFY_SUCCESS);
