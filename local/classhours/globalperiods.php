<?php
/**
 * Périodes de fermeture globales du site (vacances scolaires...) : elles
 * s'appliquent à tous les cours qui cochent « Utiliser les périodes de
 * fermeture globales » sur leur page Heures de cours. Chaque cours peut en
 * ajouter d'autres.
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_classhours\form\closed_form;
use local_classhours\schedule;
use local_classhours\store;

admin_externalpage_setup('local_classhours_globalperiods');

$pageurl = new moodle_url('/local/classhours/globalperiods.php');
$tz = core_date::get_server_timezone_object();
$success = \core\output\notification::NOTIFY_SUCCESS;

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'delete') {
    require_sesskey();
    store::delete_period(store::SITE, required_param('id', PARAM_INT));
    redirect($pageurl, get_string('deleted', 'local_classhours'), null, $success);
}

$form = new closed_form($pageurl, array('groups' => null, 'timezone' => $tz->getName()));
if ($data = $form->get_data()) {
    list($start, $end) = closed_form::bounds($data, $tz);
    store::add_period(store::SITE, 0, store::CLOSED, $start, $end, $data->name ?? '');
    redirect($pageurl, get_string('period_added', 'local_classhours'), null, $success);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('global_heading', 'local_classhours'));
echo html_writer::tag('p', get_string('global_intro', 'local_classhours', s($tz->getName())));

$periods = schedule::global_periods();
if ($periods) {
    $now = time();
    $table = new html_table();
    $table->head = array(get_string('datestart', 'local_classhours'), get_string('dateend', 'local_classhours'),
        get_string('periodname', 'local_classhours'), '');
    $table->attributes['class'] = 'generaltable';
    $format = get_string('strftimedate', 'langconfig');
    foreach ($periods as $period) {
        // timeend = minuit du lendemain du dernier jour.
        $lastday = (new DateTimeImmutable('@' . (int)$period->timeend))->setTimezone($tz)->modify('-1 day');
        $delete = new moodle_url($pageurl, array('action' => 'delete', 'id' => $period->id, 'sesskey' => sesskey()));
        $row = new html_table_row(array(
            userdate((int)$period->timestart, $format, $tz->getName()),
            userdate($lastday->getTimestamp(), $format, $tz->getName()),
            s((string)$period->name),
            html_writer::link($delete, $OUTPUT->pix_icon('t/delete', get_string('delete'))),
        ));
        if ((int)$period->timeend <= $now) {
            $row->attributes['class'] = 'dimmed_text';
        }
        $table->data[] = $row;
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('global_none', 'local_classhours'), 'info');
}

echo $OUTPUT->heading(get_string('closed_add', 'local_classhours'), 3);
$form->display();

echo $OUTPUT->footer();
