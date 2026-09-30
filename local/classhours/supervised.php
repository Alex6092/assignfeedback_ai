<?php
/**
 * Page enseignant « Activités surveillées » d'un cours : ouvrir et fermer les
 * activités faites en classe, suivre qui travaille et qui a rendu, choisir les
 * activités surveillées, historique.
 *
 * Les boutons du bloc block_supervised envoient aussi leurs actions ici (avec
 * une page de retour).
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/grouplib.php');

use local_classhours\availability_json;
use local_classhours\gate;
use local_classhours\schedule;
use local_classhours\supervised_view;

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$cansupervise = has_capability('local/classhours:supervise', $context);
$canmanage = has_capability('local/classhours:manage', $context);
if (!$cansupervise && !$canmanage) {
    require_capability('local/classhours:supervise', $context);
}

$pageurl = new moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('supervised_menu', 'local_classhours'));
$PAGE->set_heading(format_string($course->fullname));

$success = \core\output\notification::NOTIFY_SUCCESS;

// ---------------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------------
if ($action !== '') {
    require_sesskey();
    $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
    $back = $returnurl !== '' ? new moodle_url($returnurl) : $pageurl;
    $cms = supervised_view::course_cms($courseid);

    if ($action === 'open' || $action === 'close') {
        require_capability('local/classhours:supervise', $context);
        $cmid = required_param('cmid', PARAM_INT);
        if (!isset($cms[$cmid])) {
            throw new moodle_exception('supervised_notsupervised', 'local_classhours');
        }
        $name = $cms[$cmid]->get_formatted_name();

        if ($action === 'close') {
            $openingid = optional_param('openingid', 0, PARAM_INT);
            gate::close($courseid, $cmid, $openingid ?: null, (int)$USER->id);
            redirect($back, get_string('supervised_closed_done', 'local_classhours', $name), null, $success);
        }

        // Ouvrir : pour tout le cours, un groupe (group-ID) ou un élève (user-ID).
        list($scope, $scopeid) = array_pad(explode('-', optional_param('scope', gate::SCOPE_COURSE, PARAM_ALPHANUMEXT)
            . '-0'), 2, 0);
        $scopeid = (int)$scopeid;
        if ($scope === gate::SCOPE_GROUP) {
            if (!groups_group_exists($scopeid) || (int)groups_get_group($scopeid, 'courseid')->courseid !== $courseid) {
                throw new moodle_exception('invalidgroupid');
            }
        } else if ($scope === gate::SCOPE_USER) {
            if (!is_enrolled($context, $scopeid)) {
                throw new moodle_exception('invaliduserid');
            }
        } else {
            $scope = gate::SCOPE_COURSE;
            $scopeid = 0;
        }
        $duration = optional_param('duration', gate::MANUAL, PARAM_INT);
        if (!in_array($duration, gate::DURATIONS, true)) {
            $duration = gate::MANUAL;
        }
        try {
            gate::open($cms[$cmid], $scope, $scopeid, $duration, (int)$USER->id);
        } catch (moodle_exception $e) {
            redirect($back, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect($back, get_string('supervised_opened_done', 'local_classhours', $name), null, $success);
    }

    if ($action === 'closeall') {
        require_capability('local/classhours:supervise', $context);
        $count = gate::close_course($courseid, (int)$USER->id);
        redirect($back, get_string('supervised_closedall_done', 'local_classhours', $count), null, $success);
    }

    if ($action === 'saveselection') {
        require_capability('local/classhours:manage', $context);
        $listed = optional_param_array('listed', array(), PARAM_INT);
        $wanted = optional_param_array('supervise', array(), PARAM_BOOL);
        $allcms = get_fast_modinfo($course)->get_cms();
        $canadd = availability_json::condition_enabled(gate::TYPE);
        $changed = false;
        foreach ($listed as $cmid) {
            $cmid = (int)$cmid;
            if (!isset($allcms[$cmid])) {
                continue;
            }
            $json = $allcms[$cmid]->availability;
            $has = availability_json::has_root_condition($json, null, gate::TYPE);
            $want = !empty($wanted[$cmid]);
            if ($want && !$has && $canadd) {
                // Nouvellement surveillée : fermée tant que l'enseignant ne l'ouvre pas.
                availability_json::store($courseid, $cmid, availability_json::add($json, false, gate::TYPE));
                $changed = true;
            } else if (!$want && $has) {
                // Plus surveillée : ses ouvertures sont closes, sans ramassage (elle redevient libre).
                availability_json::store($courseid, $cmid, availability_json::remove($json, null, gate::TYPE));
                gate::close($courseid, $cmid, null, (int)$USER->id, false);
                $changed = true;
            }
        }
        if ($changed) {
            availability_json::rebuild($courseid);
        }
        redirect(new moodle_url($pageurl, array(), 'selection'), get_string('saved', 'local_classhours'), null, $success);
    }
}

// ---------------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------------
$cms = supervised_view::course_cms($courseid);
$now = schedule::now();
$openany = (bool)gate::active_for_course($courseid);

supervised_view::js_confirm();
if ($cansupervise && $cms) {
    supervised_view::js_page_refresh($courseid);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('supervised_menu', 'local_classhours'));

if (empty($CFG->enableavailability)) {
    echo $OUTPUT->notification(get_string('warn_availabilitydisabled', 'local_classhours'), 'warning');
}
if (!availability_json::condition_enabled(gate::TYPE)) {
    echo $OUTPUT->notification(get_string('supervised_conditiondisabled', 'local_classhours'), 'warning');
}
echo html_writer::tag('p', get_string('supervised_intro', 'local_classhours'));

// En direct : une carte par activité surveillée.
echo $OUTPUT->heading(get_string('supervised_live', 'local_classhours'), 3);
if (!$cms) {
    echo $OUTPUT->notification(get_string('supervised_none', 'local_classhours'), 'info');
} else if ($cansupervise) {
    if ($openany) {
        echo html_writer::div(supervised_view::action_form($courseid, array('action' => 'closeall'),
            get_string('supervised_closeall', 'local_classhours'), 'btn-outline-danger', $pageurl,
            get_string('supervised_confirm_closeall', 'local_classhours')), 'mb-3');
    }

    // Menu « Pour » : tout le cours, un groupe, un élève.
    $scopeoptions = html_writer::tag('option', s(get_string('allcourse', 'local_classhours')),
        array('value' => gate::SCOPE_COURSE, 'selected' => 'selected'));
    $groups = groups_get_all_groups($courseid);
    if ($groups) {
        $opts = '';
        foreach ($groups as $group) {
            $opts .= html_writer::tag('option', format_string($group->name),
                array('value' => gate::SCOPE_GROUP . '-' . (int)$group->id));
        }
        $scopeoptions .= html_writer::tag('optgroup', $opts, array('label' => get_string('groups')));
    }
    $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.*',
        'u.lastname, u.firstname', 0, 0, true);
    if ($students) {
        $opts = '';
        foreach ($students as $student) {
            $opts .= html_writer::tag('option', s(fullname($student)),
                array('value' => gate::SCOPE_USER . '-' . (int)$student->id));
        }
        $scopeoptions .= html_writer::tag('optgroup', $opts,
            array('label' => get_string('supervised_scope_students', 'local_classhours')));
    }
    $withslot = schedule::for_course($courseid)->interval_at(null, $now) !== null;
    $durationoptions = '';
    foreach (gate::duration_options($withslot) as $value => $label) {
        $durationoptions .= html_writer::tag('option', s($label), array('value' => $value)
            + ($value === gate::MANUAL ? array('selected' => 'selected') : array()));
    }

    foreach ($cms as $cmid => $cm) {
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-body');
        echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start gap-3');

        echo html_writer::start_div();
        echo html_writer::tag('h4', supervised_view::cm_link($cm), array('class' => 'h5 mb-2'));
        echo html_writer::div(supervised_view::state_html($courseid, $cmid, $pageurl, true), '',
            array('data-supervised-state' => $cmid));
        echo html_writer::div(supervised_view::counters_html($cm, $courseid), 'mt-2',
            array('data-supervised-counters' => $cmid));
        echo html_writer::end_div();

        // Ouvrir : pour qui, combien de temps.
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false),
            'class' => 'd-flex flex-wrap align-items-end gap-2'));
        foreach (array('sesskey' => sesskey(), 'action' => 'open', 'cmid' => $cmid) as $name => $value) {
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => $name, 'value' => $value));
        }
        echo html_writer::start_div();
        echo html_writer::tag('label', get_string('supervised_scope', 'local_classhours'),
            array('for' => 'supervised-scope-' . $cmid, 'class' => 'form-label small mb-0 d-block'));
        echo html_writer::tag('select', $scopeoptions, array('name' => 'scope', 'id' => 'supervised-scope-' . $cmid,
            'class' => 'form-select form-select-sm'));
        echo html_writer::end_div();
        echo html_writer::start_div();
        echo html_writer::tag('label', get_string('supervised_duration', 'local_classhours'),
            array('for' => 'supervised-duration-' . $cmid, 'class' => 'form-label small mb-0 d-block'));
        echo html_writer::tag('select', $durationoptions, array('name' => 'duration',
            'id' => 'supervised-duration-' . $cmid, 'class' => 'form-select form-select-sm'));
        echo html_writer::end_div();
        echo html_writer::tag('button', get_string('supervised_open_button', 'local_classhours'),
            array('type' => 'submit', 'class' => 'btn btn-success btn-lg'));
        echo html_writer::end_tag('form');

        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::tag('p', get_string('supervised_collect_help', 'local_classhours'), array('class' => 'text-muted'));
} else {
    // Gestionnaire sans droit d'ouvrir : l'état seulement.
    foreach ($cms as $cmid => $cm) {
        echo html_writer::div(supervised_view::cm_link($cm) . ' '
            . supervised_view::state_html($courseid, $cmid, $pageurl, false), 'mb-2');
    }
}

// Choix des activités surveillées.
if ($canmanage) {
    echo html_writer::tag('a', '', array('id' => 'selection'));
    echo $OUTPUT->heading(get_string('supervised_selection', 'local_classhours'), 3);
    echo html_writer::tag('p', get_string('supervised_selection_help', 'local_classhours'), array('class' => 'text-muted'));
    $canadd = availability_json::condition_enabled(gate::TYPE);

    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false)));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'saveselection'));
    $table = new html_table();
    $table->head = array(get_string('activity'), get_string('supervised_supervise', 'local_classhours'), '');
    $table->attributes['class'] = 'generaltable';
    $listed = 0;
    foreach (get_fast_modinfo($course)->get_cms() as $cm) {
        if (!empty($cm->deletioninprogress)) {
            continue;
        }
        $cmid = (int)$cm->id;
        $has = isset($cms[$cmid]);
        if (!$has && !in_array($cm->modname, array('quiz', 'assign'), true)) {
            continue;
        }
        $box = html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'supervise[' . $cmid . ']',
            'value' => 1, 'class' => 'form-check-input',
            'aria-label' => get_string('supervised_supervise', 'local_classhours'))
            + ($has ? array('checked' => 'checked') : array())
            + (!$canadd && !$has ? array('disabled' => 'disabled') : array()));
        $notes = '';
        if (availability_json::has_root_condition($cm->availability)) {
            $notes = html_writer::span(get_string('menu', 'local_classhours'), 'badge bg-info text-dark');
        }
        $table->data[] = array(
            supervised_view::cm_link($cm)
                . html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'listed[]', 'value' => $cmid)),
            $box,
            $notes,
        );
        $listed++;
    }
    if ($listed) {
        echo html_writer::table($table);
        echo html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('savechanges'),
            'class' => 'btn btn-primary'));
    } else {
        echo $OUTPUT->notification(get_string('activities_none', 'local_classhours'), 'info');
    }
    echo html_writer::end_tag('form');
}

// Historique.
$history = gate::history($courseid);
if ($history) {
    echo $OUTPUT->heading(get_string('supervised_history', 'local_classhours'), 3);
    list($groupnames, $usernames) = supervised_view::scope_names($courseid, $history);
    $teacherids = array();
    foreach ($history as $row) {
        $teacherids[(int)$row->openedby] = true;
    }
    require_once($CFG->dirroot . '/user/lib.php');
    $teachers = user_get_users_by_id(array_keys($teacherids));
    $allcms = get_fast_modinfo($course)->get_cms();
    $format = get_string('strftimedatetimeshort', 'langconfig');
    $table = new html_table();
    $table->head = array(get_string('activity'), get_string('supervised_scope', 'local_classhours'),
        get_string('supervised_openedby', 'local_classhours'), get_string('timestart', 'local_classhours'),
        get_string('timeend', 'local_classhours'));
    $table->attributes['class'] = 'generaltable';
    foreach ($history as $row) {
        if (gate::is_active($row, $now)) {
            $end = html_writer::span(get_string('supervised_open', 'local_classhours'), 'badge bg-success text-white');
        } else {
            $end = userdate((int)$row->timeclosed ?: (int)$row->closeat, $format);
        }
        $table->data[] = array(
            isset($allcms[(int)$row->cmid]) ? $allcms[(int)$row->cmid]->get_formatted_name() : '#' . (int)$row->cmid,
            s(gate::scope_label($row, $groupnames, $usernames)),
            isset($teachers[(int)$row->openedby]) ? s(fullname($teachers[(int)$row->openedby])) : '',
            userdate((int)$row->timeopened, $format),
            $end,
        );
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
