<?php
/**
 * Page enseignant « Activités surveillées » d'un cours :
 *   - ouvrir et fermer les activités faites en classe, suivre qui est
 *     présent, qui travaille et qui a rendu ;
 *   - élèves à rattraper, avec « Ouvrir pour lui » ;
 *   - réglages de chaque activité : date prévue, code de séance, mode
 *     examen, activités fermées pendant la séance ;
 *   - tiers-temps des élèves, choix des activités surveillées, historique ;
 *   - affichage en grand (vidéoprojecteur) : code, compte à rebours,
 *     compteurs (?display=cmid).
 *
 * Les boutons du bloc block_supervised envoient aussi leurs actions ici (avec
 * une page de retour).
 *
 * @package local_classhours
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/grouplib.php');

use local_classhours\availability_json;
use local_classhours\catchup;
use local_classhours\extratime;
use local_classhours\gate;
use local_classhours\plan;
use local_classhours\schedule;
use local_classhours\supervised_view;

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);
$display  = optional_param('display', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$cansupervise = has_capability('local/classhours:supervise', $context);
$canmanage = has_capability('local/classhours:manage', $context);
if (!$cansupervise && !$canmanage) {
    require_capability('local/classhours:supervise', $context);
}

$pageurl = new moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
$PAGE->set_url($display ? new moodle_url($pageurl, array('display' => $display)) : $pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout($display ? 'popup' : 'incourse');
$PAGE->set_title(get_string('supervised_menu', 'local_classhours'));
$PAGE->set_heading(format_string($course->fullname));

$success = \core\output\notification::NOTIFY_SUCCESS;

/**
 * Date et heure saisies (champs date + heure, fuseau de l'utilisateur), 0 si vides.
 */
$readdate = function(string $date, string $time): int {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $d)) {
        return 0;
    }
    $h = 8;
    $i = 0;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $t)) {
        $h = (int)$t[1];
        $i = (int)$t[2];
    }
    return make_timestamp((int)$d[1], (int)$d[2], (int)$d[3], $h, $i);
};

// ---------------------------------------------------------------------------
//  Actions
// ---------------------------------------------------------------------------
if ($action !== '') {
    require_sesskey();
    $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
    $back = $returnurl !== '' ? new moodle_url($returnurl) : $pageurl;
    $cms = supervised_view::course_cms($courseid);

    if (in_array($action, array('open', 'close', 'savesettings'), true)) {
        $cmid = required_param('cmid', PARAM_INT);
        if (!isset($cms[$cmid])) {
            throw new moodle_exception('supervised_notsupervised', 'local_classhours');
        }
        $name = $cms[$cmid]->get_formatted_name();
    }

    if ($action === 'close') {
        require_capability('local/classhours:supervise', $context);
        $openingid = optional_param('openingid', 0, PARAM_INT);
        $force = optional_param('force', 0, PARAM_BOOL);
        gate::close($courseid, $cmid, $openingid ?: null, (int)$USER->id, true, (bool)$force);
        redirect($back, get_string('supervised_closed_done', 'local_classhours', $name), null, $success);
    }

    if ($action === 'open') {
        require_capability('local/classhours:supervise', $context);
        // Pour tout le cours, un groupe (group-ID) ou un élève (user-ID).
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

    if ($action === 'savesettings') {
        require_capability('local/classhours:supervise', $context);
        // Dates prévues : tout le cours (0) et, au besoin, par groupe.
        $dates = array(0 => $readdate(optional_param('date_0', '', PARAM_RAW), optional_param('time_0', '', PARAM_RAW)));
        foreach (groups_get_all_groups($courseid) as $group) {
            $gid = (int)$group->id;
            $dates[$gid] = $readdate(optional_param('date_' . $gid, '', PARAM_RAW), optional_param('time_' . $gid, '',
                PARAM_RAW));
        }
        plan::save_dates($courseid, $cmid, $dates);
        plan::save_options($courseid, $cmid, (bool)optional_param('sessioncode', 0, PARAM_BOOL),
            (bool)optional_param('exammode', 0, PARAM_BOOL));

        // Activités fermées pendant la séance : modifie leur restriction d'accès.
        if ($canmanage) {
            $listed = optional_param_array('linklisted', array(), PARAM_INT);
            $wanted = optional_param_array('link', array(), PARAM_BOOL);
            $allcms = get_fast_modinfo($course)->get_cms();
            $changed = false;
            foreach ($listed as $otherid) {
                $otherid = (int)$otherid;
                if (!isset($allcms[$otherid]) || $otherid === $cmid) {
                    continue;
                }
                $json = $allcms[$otherid]->availability;
                $has = in_array($cmid, gate::lock_cmids($json), true);
                $want = !empty($wanted[$otherid]);
                if ($want && !$has) {
                    availability_json::store($courseid, $otherid, gate::add_lock($json, $cmid));
                    $changed = true;
                } else if (!$want && $has) {
                    availability_json::store($courseid, $otherid, gate::remove_lock($json, $cmid));
                    $changed = true;
                }
            }
            if ($changed) {
                availability_json::rebuild($courseid);
            }
        }
        redirect(new moodle_url($pageurl, array(), 'cm' . $cmid), get_string('saved', 'local_classhours'), null, $success);
    }

    if ($action === 'saveextratime') {
        require_capability('local/classhours:supervise', $context);
        $checked = optional_param_array('extra', array(), PARAM_BOOL);
        $percents = optional_param_array('percent', array(), PARAM_INT);
        $values = array();
        foreach ($checked as $userid => $on) {
            if ($on && is_enrolled($context, (int)$userid)) {
                $values[(int)$userid] = (int)($percents[$userid] ?? extratime::default_percent());
            }
        }
        extratime::save($courseid, $values);
        redirect(new moodle_url($pageurl, array(), 'extratime'), get_string('saved', 'local_classhours'), null, $success);
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
            $has = gate::is_supervised_json($json);
            $want = !empty($wanted[$cmid]);
            if ($want && !$has && $canadd) {
                // Nouvellement surveillée : fermée tant que l'enseignant ne l'ouvre pas.
                availability_json::store($courseid, $cmid, gate::add_supervised($json));
                $changed = true;
            } else if (!$want && $has) {
                // Plus surveillée : ses ouvertures sont closes, sans ramassage (elle redevient libre).
                availability_json::store($courseid, $cmid, gate::remove_supervised($json));
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

$now = schedule::now();

// ---------------------------------------------------------------------------
//  Affichage en grand (vidéoprojecteur) : nom, code, compte à rebours, compteurs.
// ---------------------------------------------------------------------------
if ($display) {
    require_capability('local/classhours:supervise', $context);
    $cms = supervised_view::course_cms($courseid);
    if (!isset($cms[$display])) {
        throw new moodle_exception('supervised_notsupervised', 'local_classhours');
    }
    $cm = $cms[$display];
    $openings = gate::active_for_cm($courseid, $display, $now);
    $codes = array_unique(array_filter(array_map(function($o) {
        return (string)$o->code;
    }, $openings)));
    $ends = array_map(function($o) {
        return (int)$o->closeat;
    }, $openings);
    $end = ($openings && !in_array(0, $ends, true)) ? max($ends) : 0;
    supervised_view::js_page_refresh($courseid);
    if ($end) {
        $PAGE->requires->js_amd_inline("
            (function() {
                var node = document.getElementById('supervised-countdown');
                var end = " . (int)$end . " * 1000;
                var tick = function() {
                    var left = Math.max(0, Math.round((end - Date.now()) / 1000));
                    var m = Math.floor(left / 60), s = left % 60;
                    node.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                };
                tick();
                setInterval(tick, 1000);
            })();");
    }
    echo $OUTPUT->header();
    echo html_writer::start_div('text-center py-4');
    echo html_writer::tag('h1', $cm->get_formatted_name(), array('class' => 'display-5 mb-4'));
    if (!$openings) {
        echo html_writer::tag('p', get_string('supervised_closed', 'local_classhours'), array('class' => 'display-6'));
    } else {
        if ($codes) {
            echo html_writer::div(get_string('supervised_code', 'local_classhours'), 'fs-3 text-muted');
            echo html_writer::div(s(implode('  ', $codes)), 'font-monospace fw-bold mb-4',
                array('style' => 'font-size: 12vw; letter-spacing: .2em; line-height: 1;'));
        }
        if ($end) {
            echo html_writer::div(get_string('display_left', 'local_classhours'), 'fs-3 text-muted');
            echo html_writer::div('', 'fw-bold mb-4', array('id' => 'supervised-countdown', 'style' => 'font-size: 6vw;'));
        }
        echo html_writer::div(supervised_view::counters_html($cm, $courseid), 'fs-3',
            array('data-supervised-counters' => $display));
    }
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ---------------------------------------------------------------------------
//  Page de pilotage
// ---------------------------------------------------------------------------
$cms = supervised_view::planned_cms($courseid);
$openany = (bool)gate::active_for_course($courseid);
$groups = groups_get_all_groups($courseid);
$students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.*',
    'u.lastname, u.firstname', 0, 0, true);
$dates = plan::dates_for_course($courseid);
$pendingrequests = catchup::pending_for_course($courseid);

supervised_view::js_confirm();
if ($cansupervise && $cms) {
    supervised_view::js_page_refresh($courseid);
    supervised_view::js_prefill();
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

// En direct : une carte par activité surveillée, par date prévue.
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
    if ($groups) {
        $opts = '';
        foreach ($groups as $group) {
            $opts .= html_writer::tag('option', format_string($group->name),
                array('value' => gate::SCOPE_GROUP . '-' . (int)$group->id));
        }
        $scopeoptions .= html_writer::tag('optgroup', $opts, array('label' => get_string('groups')));
    }
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
    $allcms = get_fast_modinfo($course)->get_cms();
    $studentids = array_map('intval', array_keys($students));

    foreach ($cms as $cmid => $cm) {
        $options = plan::options($courseid, $cmid);
        echo html_writer::tag('a', '', array('id' => 'cm' . $cmid));
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-body');
        echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start gap-3');

        // État, compteurs, badges.
        echo html_writer::start_div();
        echo html_writer::tag('h4', supervised_view::cm_link($cm), array('class' => 'h5 mb-1'));
        $badges = array();
        $planned = $dates[$cmid][0] ?? 0;
        if ($planned) {
            $badges[] = get_string('frise_planned', 'local_classhours', supervised_view::format_date((int)$planned));
        }
        if ($options->sessioncode) {
            $badges[] = get_string('option_sessioncode', 'local_classhours');
        }
        if ($options->exammode) {
            $badges[] = get_string('option_exammode', 'local_classhours');
        }
        if ($badges) {
            echo html_writer::div(s(implode(' · ', $badges)), 'small text-muted mb-2');
        }
        echo html_writer::div(supervised_view::state_html($courseid, $cmid, $pageurl, true), '',
            array('data-supervised-state' => $cmid));
        echo html_writer::div(supervised_view::counters_html($cm, $courseid), 'mt-2',
            array('data-supervised-counters' => $cmid));
        echo html_writer::div(html_writer::link(new moodle_url($pageurl, array('display' => $cmid)),
            get_string('display_link', 'local_classhours'), array('target' => '_blank', 'rel' => 'noopener')), 'small mt-1');
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

        // À rattraper.
        $due = catchup::students_due($courseid, $cm, $studentids);
        $requested = $pendingrequests[$cmid] ?? array();
        foreach (array_keys($requested) as $uid) {
            if (!in_array($uid, $due, true) && isset($students[$uid])) {
                $due[] = $uid;
            }
        }
        if ($due) {
            echo html_writer::start_tag('details', array('class' => 'mt-3', 'id' => 'catchup' . $cmid)
                + ($requested ? array('open' => 'open') : array()));
            echo html_writer::tag('summary', get_string('catchup_list', 'local_classhours', count($due))
                . ($requested ? ' ' . html_writer::span(get_string('catchup_requests', 'local_classhours', count($requested)),
                    'badge bg-info text-white') : ''));
            echo html_writer::start_tag('ul', array('class' => 'list-unstyled mt-2'));
            foreach ($due as $uid) {
                if (!isset($students[$uid])) {
                    continue;
                }
                echo html_writer::tag('li', s(fullname($students[$uid]))
                    . (isset($requested[$uid]) ? ' ' . html_writer::span(get_string('catchup_requested', 'local_classhours'),
                        'badge bg-info text-white') : '')
                    . ' ' . html_writer::tag('button', get_string('catchup_openfor', 'local_classhours'), array(
                        'type' => 'button', 'class' => 'btn btn-sm btn-outline-success ms-2',
                        'data-supervised-prefill' => $cmid, 'data-scope' => gate::SCOPE_USER . '-' . $uid)),
                    array('class' => 'mb-1'));
            }
            echo html_writer::end_tag('ul');
            echo html_writer::end_tag('details');
        }

        // Réglages : date prévue, options, activités fermées pendant la séance.
        echo html_writer::start_tag('details', array('class' => 'mt-3'));
        echo html_writer::tag('summary', get_string('settings_heading', 'local_classhours'));
        echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false), 'class' => 'mt-2'));
        foreach (array('sesskey' => sesskey(), 'action' => 'savesettings', 'cmid' => $cmid) as $name => $value) {
            echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => $name, 'value' => $value));
        }
        $dateinputs = function(int $gid, string $label) use ($dates, $cmid) {
            $t = (int)($dates[$cmid][$gid] ?? 0);
            return html_writer::div(
                html_writer::tag('label', s($label), array('class' => 'form-label small mb-0 me-2', 'for' => 'date-' . $cmid . '-' . $gid))
                . html_writer::empty_tag('input', array('type' => 'date', 'name' => 'date_' . $gid,
                    'id' => 'date-' . $cmid . '-' . $gid, 'class' => 'form-control form-control-sm d-inline-block w-auto',
                    'value' => $t ? userdate($t, '%Y-%m-%d') : ''))
                . ' ' . html_writer::empty_tag('input', array('type' => 'time', 'name' => 'time_' . $gid,
                    'class' => 'form-control form-control-sm d-inline-block w-auto', 'aria-label' => get_string('time'),
                    'value' => $t ? userdate($t, '%H:%M') : '')),
                'mb-2');
        };
        echo $dateinputs(0, get_string('settings_planned', 'local_classhours'));
        foreach ($groups as $group) {
            echo $dateinputs((int)$group->id, get_string('settings_planned_group', 'local_classhours',
                format_string($group->name)));
        }
        foreach (array('sessioncode' => $options->sessioncode, 'exammode' => $options->exammode) as $name => $value) {
            echo html_writer::div(
                html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => $name, 'value' => 1,
                    'id' => $name . '-' . $cmid, 'class' => 'form-check-input') + ($value ? array('checked' => 'checked') : array()))
                . html_writer::tag('label', get_string('option_' . $name, 'local_classhours'),
                    array('for' => $name . '-' . $cmid, 'class' => 'form-check-label'))
                . html_writer::div(get_string('option_' . $name . '_help', 'local_classhours'), 'form-text'),
                'form-check mb-2');
        }
        if ($canmanage) {
            echo html_writer::tag('div', get_string('settings_links', 'local_classhours'), array('class' => 'fw-bold mt-2'));
            echo html_writer::div(get_string('settings_links_help', 'local_classhours'), 'form-text mb-1');
            $links = '';
            foreach ($allcms as $other) {
                $otherid = (int)$other->id;
                if ($otherid === $cmid || !empty($other->deletioninprogress) || $other->modname === 'label'
                        || isset($cms[$otherid])) {
                    continue;
                }
                $checked = in_array($cmid, gate::lock_cmids($other->availability), true);
                $links .= html_writer::div(
                    html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'linklisted[]', 'value' => $otherid))
                    . html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'link[' . $otherid . ']',
                        'value' => 1, 'id' => 'link-' . $cmid . '-' . $otherid, 'class' => 'form-check-input')
                        + ($checked ? array('checked' => 'checked') : array()))
                    . html_writer::tag('label', html_writer::img($other->get_icon_url(), '', array('class' => 'icon'))
                        . ' ' . $other->get_formatted_name(),
                        array('for' => 'link-' . $cmid . '-' . $otherid, 'class' => 'form-check-label')),
                    'form-check');
            }
            echo html_writer::div($links, 'border rounded p-2 mb-2', array('style' => 'max-height: 16rem; overflow-y: auto;'));
        }
        echo html_writer::tag('button', get_string('savechanges'), array('type' => 'submit', 'class' => 'btn btn-primary'));
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');

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

// Tiers-temps.
if ($cansupervise && $students) {
    echo html_writer::tag('a', '', array('id' => 'extratime'));
    echo $OUTPUT->heading(get_string('extratime_heading', 'local_classhours'), 3);
    echo html_writer::tag('p', get_string('extratime_help', 'local_classhours', extratime::default_percent()),
        array('class' => 'text-muted'));
    $current = extratime::for_course($courseid);
    echo html_writer::start_tag('details', $current ? array('open' => 'open') : array());
    echo html_writer::tag('summary', get_string('extratime_count', 'local_classhours', count($current)));
    echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false), 'class' => 'mt-2'));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'action', 'value' => 'saveextratime'));
    $table = new html_table();
    $table->head = array(get_string('supervised_scope_students', 'local_classhours'),
        get_string('extratime_on', 'local_classhours'), get_string('extratime_percent', 'local_classhours'));
    $table->attributes['class'] = 'generaltable';
    foreach ($students as $student) {
        $uid = (int)$student->id;
        $table->data[] = array(
            s(fullname($student)),
            html_writer::empty_tag('input', array('type' => 'checkbox', 'name' => 'extra[' . $uid . ']', 'value' => 1,
                'class' => 'form-check-input', 'aria-label' => get_string('extratime_on', 'local_classhours'))
                + (isset($current[$uid]) ? array('checked' => 'checked') : array())),
            html_writer::empty_tag('input', array('type' => 'number', 'name' => 'percent[' . $uid . ']', 'min' => 1,
                'max' => 200, 'class' => 'form-control form-control-sm', 'style' => 'width: 6rem;',
                'aria-label' => get_string('extratime_percent', 'local_classhours'),
                'value' => $current[$uid] ?? extratime::default_percent())) . ' %',
        );
    }
    echo html_writer::table($table);
    echo html_writer::tag('button', get_string('savechanges'), array('type' => 'submit', 'class' => 'btn btn-primary'));
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
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
