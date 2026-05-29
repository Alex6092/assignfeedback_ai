<?php
/**
 * Point d'entrée du générateur de tests IA.
 *
 * Étape 1 (squelette) : valide simplement que la plomberie est en place
 * (capability, bootstrap, navigation). Le formulaire et la pipeline de
 * génération arrivent aux étapes suivantes.
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);

require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aiquizgen:generate', $context);

$PAGE->set_url(new moodle_url('/local/aiquizgen/generate.php',
    array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_aiquizgen'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_aiquizgen'));
echo $OUTPUT->notification(get_string('comingsoon', 'local_aiquizgen'), 'info');
echo $OUTPUT->footer();
