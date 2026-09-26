<?php
/**
 * Page enseignant « Heures de cours » d'un cours : emploi du temps de la
 * semaine, ouvertures exceptionnelles, périodes fermées, option EFE et choix
 * des activités restreintes.
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/grouplib.php');

use local_classhours\availability_json;
use local_classhours\efe_bridge;
use local_classhours\efe_sync;
use local_classhours\form\closed_form;
use local_classhours\form\open_form;
use local_classhours\form\slot_form;
use local_classhours\schedule;
use local_classhours\store;

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/classhours:manage', $context);

$pageurl = new moodle_url('/local/classhours/manage.php', array('courseid' => $courseid));
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('menu', 'local_classhours'));
$PAGE->set_heading(format_string($course->fullname));

$tz = core_date::get_server_timezone_object();
$groupnames = schedule::for_course($courseid)->group_names();
$groupoptions = array(0 => get_string('allcourse', 'local_classhours')) + $groupnames;
$groupname = function(int $groupid) use ($groupnames) {
    if ($groupid === 0) {
        return get_string('allcourse', 'local_classhours');
    }
    return $groupnames[$groupid] ?? get_string('unknowngroup', 'local_classhours');
};
$success = \core\output\notification::NOTIFY_SUCCESS;

// ---------------------------------------------------------------------------
//  Actions (liens et formulaire des activités)
// ---------------------------------------------------------------------------
if ($action !== '') {
    require_sesskey();

    if ($action === 'deleteslot') {
        store::delete_slot($courseid, required_param('id', PARAM_INT));
        efe_sync::sync_course($courseid);
        redirect($pageurl, get_string('deleted', 'local_classhours'), null, $success);
    }

    if ($action === 'deleteperiod') {
        store::delete_period($courseid, required_param('id', PARAM_INT));
        efe_sync::sync_course($courseid);
        redirect($pageurl, get_string('deleted', 'local_classhours'), null, $success);
    }

    if ($action === 'setuseglobal') {
        store::set_useglobal($courseid, optional_param('useglobal', 0, PARAM_BOOL));
        redirect(new moodle_url($pageurl, array(), 'closed'), get_string('saved', 'local_classhours'), null, $success);
    }

    if ($action === 'sync') {
        $count = efe_sync::sync_course($courseid);
        redirect(new moodle_url($pageurl, array(), 'activities'),
            get_string('sync_done', 'local_classhours', $count), null, $success);
    }

    if ($action === 'saveactivities') {
        if (efe_bridge::available()) {
            store::set_efeauto($courseid, optional_param('efeauto', 0, PARAM_BOOL));
        }
        $listed   = optional_param_array('listed', array(), PARAM_INT);
        $restrict = optional_param_array('restrict', array(), PARAM_BOOL);
        $exclude  = optional_param_array('exclude', array(), PARAM_BOOL);

        $cms = get_fast_modinfo($course)->get_cms();
        $efecmids = efe_bridge::reporting_cmids($courseid) ?? array();
        $excluded = store::excluded_cmids($courseid);
        $canadd = availability_json::condition_enabled();
        $changed = false;
        foreach ($listed as $cmid) {
            $cmid = (int)$cmid;
            if (!isset($cms[$cmid])) {
                continue;
            }
            // Case « Restreindre » = restriction posée à la main, qui tient
            // quelle que soit la remontée EFE ; elle remplace une condition
            // posée par l'option EFE.
            $json = $cms[$cmid]->availability;
            $new = $json;
            $want = !empty($restrict[$cmid]);
            if ($want && $canadd && !availability_json::has_root_condition($new, false)) {
                $new = availability_json::add(availability_json::remove($new, true), false);
            } else if (!$want && availability_json::has_root_condition($new, false)) {
                $new = availability_json::remove($new, false);
            }
            if ($new !== $json) {
                availability_json::store($courseid, $cmid, $new);
                $changed = true;
            }
            if (isset($efecmids[$cmid]) || isset($excluded[$cmid])) {
                store::set_excluded($cmid, $courseid, !empty($exclude[$cmid]));
            }
        }
        if ($changed) {
            availability_json::rebuild($courseid);
        }
        efe_sync::sync_course($courseid);
        redirect(new moodle_url($pageurl, array(), 'activities'),
            get_string('saved', 'local_classhours'), null, $success);
    }
}

// ---------------------------------------------------------------------------
//  Formulaires d'ajout
// ---------------------------------------------------------------------------
$customdata = array('groups' => $groupoptions, 'timezone' => $tz->getName());

$slotform = new slot_form($pageurl, $customdata);
if ($data = $slotform->get_data()) {
    store::add_slot($courseid, (int)$data->groupid, (int)$data->weekday,
        slot_form::minutes($data->start), slot_form::minutes($data->end));
    efe_sync::sync_course($courseid);
    redirect($pageurl, get_string('slot_added', 'local_classhours'), null, $success);
}

$openform = new open_form($pageurl, $customdata);
if ($data = $openform->get_data()) {
    store::add_period($courseid, (int)$data->groupid, store::OPEN, (int)$data->timestart, (int)$data->timeend,
        $data->name ?? '');
    efe_sync::sync_course($courseid);
    redirect(new moodle_url($pageurl, array(), 'open'), get_string('period_added', 'local_classhours'), null, $success);
}

$closedform = new closed_form($pageurl, $customdata);
if ($data = $closedform->get_data()) {
    list($start, $end) = closed_form::bounds($data, $tz);
    store::add_period($courseid, (int)$data->groupid, store::CLOSED, $start, $end, $data->name ?? '');
    efe_sync::sync_course($courseid);
    redirect(new moodle_url($pageurl, array(), 'closed'), get_string('period_added', 'local_classhours'), null, $success);
}

// ---------------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------------
$sched = schedule::for_course($courseid);
$now = schedule::now();

$deletelink = function(string $action, int $id) use ($pageurl, $OUTPUT) {
    $url = new moodle_url($pageurl, array('action' => $action, 'id' => $id, 'sesskey' => sesskey()));
    return html_writer::link($url, $OUTPUT->pix_icon('t/delete', get_string('delete')));
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('menu', 'local_classhours'));

if (empty($CFG->enableavailability)) {
    echo $OUTPUT->notification(get_string('warn_availabilitydisabled', 'local_classhours'), 'warning');
}
if (!availability_json::condition_enabled()) {
    echo $OUTPUT->notification(get_string('warn_conditiondisabled', 'local_classhours'), 'warning');
}
echo html_writer::tag('p', get_string('intro', 'local_classhours', s($tz->getName())));

// État actuel, par groupe.
echo $OUTPUT->heading(get_string('status_heading', 'local_classhours'), 3);
// Heure du serveur, dans le fuseau des créneaux : pour vérifier d'un coup d'œil
// que l'horloge et le fuseau de Moodle sont justes.
echo html_writer::tag('p', get_string('status_now', 'local_classhours', (object)array(
    'time' => $sched->format_time($now),
    'tz'   => s($tz->getName()),
)));
$statusrows = array();
if ($groupnames) {
    $statusrows[get_string('status_nogroup', 'local_classhours')] = array();
    foreach ($groupnames as $gid => $name) {
        $statusrows[$name] = array($gid);
    }
} else {
    $statusrows[get_string('status_everyone', 'local_classhours')] = array();
}
$table = new html_table();
$table->head = array(get_string('group'), get_string('status', 'local_classhours'));
$table->attributes['class'] = 'generaltable';
foreach ($statusrows as $label => $gids) {
    $interval = $sched->interval_at($gids, $now);
    if ($interval) {
        $status = html_writer::span(get_string('status_open', 'local_classhours', $sched->format_time($interval[1])),
            'badge bg-success text-white');
    } else {
        $next = $sched->next_opening($gids, $now);
        $status = html_writer::span(get_string('status_closed', 'local_classhours'), 'badge bg-secondary text-dark') . ' '
            . ($next ? get_string('status_next', 'local_classhours', $sched->format_time($next))
                     : get_string('status_nonext', 'local_classhours'));
    }
    $table->data[] = array($label, $status);
}
echo html_writer::table($table);

// Contrôle des tentatives (plugin facultatif) : tentatives faites hors créneau.
if (get_capability_info('local/attemptcheck:view') && has_capability('local/attemptcheck:view', $context)) {
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/local/attemptcheck/report.php', array('courseid' => $courseid, 'signal' => 'offslot')),
        get_string('offslot_link', 'local_classhours')));
}

// Emploi du temps hebdomadaire.
echo $OUTPUT->heading(get_string('slots_heading', 'local_classhours'), 3);
$slots = $sched->get_slots();
if ($slots) {
    $table = new html_table();
    $table->head = array(get_string('weekday', 'local_classhours'), get_string('hours', 'local_classhours'),
        get_string('group'), '');
    $table->attributes['class'] = 'generaltable';
    foreach ($slots as $slot) {
        $table->data[] = array(
            schedule::weekday_name((int)$slot->weekday),
            schedule::format_minutes((int)$slot->starttime) . ' – ' . schedule::format_minutes((int)$slot->endtime),
            $groupname((int)$slot->groupid),
            $deletelink('deleteslot', (int)$slot->id),
        );
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('slots_none', 'local_classhours'), 'info');
}
$slotform->display();

// Ouvertures exceptionnelles.
echo html_writer::tag('a', '', array('id' => 'open'));
echo $OUTPUT->heading(get_string('open_heading', 'local_classhours'), 3);
echo html_writer::tag('p', get_string('open_help', 'local_classhours'), array('class' => 'text-muted'));
$opens = $sched->get_periods(store::OPEN);
if ($opens) {
    $table = new html_table();
    $table->head = array(get_string('timestart', 'local_classhours'), get_string('timeend', 'local_classhours'),
        get_string('group'), get_string('periodname', 'local_classhours'), '');
    $table->attributes['class'] = 'generaltable';
    foreach ($opens as $period) {
        $row = new html_table_row(array(
            $sched->format_time((int)$period->timestart),
            $sched->format_time((int)$period->timeend),
            $groupname((int)$period->groupid),
            s((string)$period->name),
            $deletelink('deleteperiod', (int)$period->id),
        ));
        if ((int)$period->timeend <= $now) {
            $row->attributes['class'] = 'dimmed_text';
        }
        $table->data[] = $row;
    }
    echo html_writer::table($table);
}
$openform->display();

// Périodes fermées.
echo html_writer::tag('a', '', array('id' => 'closed'));
echo $OUTPUT->heading(get_string('closed_heading', 'local_classhours'), 3);
echo html_writer::tag('p', get_string('closed_help', 'local_classhours'), array('class' => 'text-muted'));

/**
 * Tableau de périodes fermées ; $editable : colonnes groupe et suppression.
 */
$closedtable = function(array $periods, bool $editable) use ($sched, $tz, $now, $groupname, $deletelink) {
    $table = new html_table();
    $table->head = array(get_string('datestart', 'local_classhours'), get_string('dateend', 'local_classhours'));
    if ($editable) {
        $table->head[] = get_string('group');
    }
    $table->head[] = get_string('periodname', 'local_classhours');
    if ($editable) {
        $table->head[] = '';
    }
    $table->attributes['class'] = 'generaltable';
    foreach ($periods as $period) {
        // timeend = minuit du lendemain du dernier jour.
        $lastday = (new DateTimeImmutable('@' . (int)$period->timeend))->setTimezone($tz)->modify('-1 day');
        $cells = array($sched->format_date((int)$period->timestart), $sched->format_date($lastday->getTimestamp()));
        if ($editable) {
            $cells[] = $groupname((int)$period->groupid);
        }
        $cells[] = s((string)$period->name);
        if ($editable) {
            $cells[] = $deletelink('deleteperiod', (int)$period->id);
        }
        $row = new html_table_row($cells);
        if ((int)$period->timeend <= $now) {
            $row->attributes['class'] = 'dimmed_text';
        }
        $table->data[] = $row;
    }
    return html_writer::table($table);
};

// Périodes globales du site (vacances), appliquées si la case est cochée.
$useglobal = store::get_useglobal($courseid);
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false),
    'class' => 'd-flex flex-wrap align-items-center gap-2 mb-2'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'setuseglobal'));
echo html_writer::start_div('form-check');
echo html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'useglobal', 'value' => 1,
    'id' => 'classhours-useglobal', 'class' => 'form-check-input') + ($useglobal ? array('checked' => 'checked') : array()));
echo html_writer::tag('label', get_string('useglobal', 'local_classhours'),
    array('for' => 'classhours-useglobal', 'class' => 'form-check-label fw-bold'));
echo html_writer::end_div();
echo html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('savechanges'),
    'class' => 'btn btn-secondary btn-sm'));
echo html_writer::end_tag('form');

$globals = schedule::global_periods();
if ($globals) {
    echo html_writer::start_div($useglobal ? '' : 'dimmed_text');
    echo $closedtable($globals, false);
    echo html_writer::end_div();
} else {
    echo html_writer::tag('p', get_string('global_none', 'local_classhours'), array('class' => 'text-muted'));
}
if (has_capability('moodle/site:config', context_system::instance())) {
    echo html_writer::tag('p', html_writer::link(new moodle_url('/local/classhours/globalperiods.php'),
        get_string('global_manage', 'local_classhours')));
}

// Périodes propres au cours.
echo $OUTPUT->heading(get_string('closed_course_heading', 'local_classhours'), 4);
$closeds = $sched->get_periods(store::CLOSED, false);
if ($closeds) {
    echo $closedtable($closeds, true);
}
$closedform->display();

// Activités restreintes et option EFE.
echo html_writer::tag('a', '', array('id' => 'activities'));
echo $OUTPUT->heading(get_string('activities_heading', 'local_classhours'), 3);
echo html_writer::tag('p', get_string('activities_help', 'local_classhours'), array('class' => 'text-muted'));

$efeavailable = efe_bridge::available();
$efeauto = store::get_efeauto($courseid);
$efecmids = $efeavailable ? (efe_bridge::reporting_cmids($courseid) ?? array()) : array();
$excluded = store::excluded_cmids($courseid);
$canadd = availability_json::condition_enabled();

echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false)));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'saveactivities'));

if ($efeavailable) {
    echo html_writer::start_div('border rounded p-3 mb-3');
    echo html_writer::start_div('form-check');
    echo html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'efeauto', 'value' => 1,
        'id' => 'classhours-efeauto', 'class' => 'form-check-input') + ($efeauto ? array('checked' => 'checked') : array()));
    echo html_writer::tag('label', get_string('efeauto', 'local_classhours'),
        array('for' => 'classhours-efeauto', 'class' => 'form-check-label fw-bold'));
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('efeauto_help', 'local_classhours'), array('class' => 'text-muted mb-1'));
    if ($efeauto && !$sched->has_slots($now)) {
        echo $OUTPUT->notification(get_string('efeauto_noslots', 'local_classhours'), 'warning');
    }
    $syncurl = new moodle_url($pageurl, array('action' => 'sync', 'sesskey' => sesskey()));
    echo html_writer::link($syncurl, get_string('sync_now', 'local_classhours'), array('class' => 'btn btn-secondary btn-sm'));
    echo html_writer::end_div();
}

$table = new html_table();
$table->head = array(get_string('activity'), get_string('efe', 'local_classhours'), get_string('status', 'local_classhours'),
    get_string('restrict', 'local_classhours'), $efeavailable ? get_string('exclude', 'local_classhours') : '');
$table->attributes['class'] = 'generaltable';
$listed = 0;
foreach (get_fast_modinfo($course)->get_cms() as $cm) {
    if (!empty($cm->deletioninprogress)) {
        continue;
    }
    $cmid = (int)$cm->id;
    $json = $cm->availability;
    $isefe = isset($efecmids[$cmid]);
    $isexcluded = isset($excluded[$cmid]);
    $manual = availability_json::has_root_condition($json, false);
    $auto = availability_json::has_root_condition($json, true);
    $nested = !$manual && !$auto && $json !== null && strpos($json, '"' . availability_json::TYPE . '"') !== false;
    if (!in_array($cm->modname, array('quiz', 'assign'), true) && !$isefe && !$isexcluded
            && !$manual && !$auto && !$nested) {
        continue;
    }

    if ($manual) {
        $state = html_writer::span(get_string('state_manual', 'local_classhours'), 'badge bg-primary text-white');
    } else if ($auto) {
        $state = html_writer::span(get_string('state_auto', 'local_classhours'), 'badge bg-info text-dark');
    } else if ($nested) {
        $state = html_writer::span(get_string('state_nested', 'local_classhours'), 'badge bg-primary text-white');
    } else if ($isefe && $isexcluded) {
        $state = html_writer::span(get_string('state_excluded', 'local_classhours'), 'badge bg-warning text-dark');
    } else {
        $state = html_writer::span(get_string('state_free', 'local_classhours'), 'badge bg-light text-dark');
    }

    $restrictbox = html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'restrict[' . $cmid . ']',
        'value' => 1, 'class' => 'form-check-input', 'aria-label' => get_string('restrict', 'local_classhours'))
        + ($manual ? array('checked' => 'checked') : array())
        + (!$canadd && !$manual ? array('disabled' => 'disabled') : array()));
    $excludebox = '';
    if ($efeavailable && ($isefe || $isexcluded)) {
        $excludebox = html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'exclude[' . $cmid . ']',
            'value' => 1, 'class' => 'form-check-input', 'aria-label' => get_string('exclude', 'local_classhours'))
            + ($isexcluded ? array('checked' => 'checked') : array()));
    }

    $name = html_writer::img($cm->get_icon_url(), '', array('class' => 'icon')) . ' '
        . ($cm->url ? html_writer::link($cm->url, $cm->get_formatted_name()) : $cm->get_formatted_name());
    $table->data[] = array(
        $name . html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'listed[]', 'value' => $cmid)),
        $isefe ? html_writer::span(get_string('efe', 'local_classhours'), 'badge bg-success text-white') : '',
        $state,
        $restrictbox,
        $excludebox,
    );
    $listed++;
}
if ($listed) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('activities_none', 'local_classhours'), 'info');
}
echo html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('savechanges'),
    'class' => 'btn btn-primary'));
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
