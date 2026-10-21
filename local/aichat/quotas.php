<?php
/**
 * Page enseignant « Quotas du tuteur IA » d'un cours :
 *   - fenêtre en cours de chaque élève : ouverture, heure de réinitialisation,
 *     tokens et messages consommés, bonus ;
 *   - réinitialiser la fenêtre ou accorder un bonus, à un élève, aux élèves
 *     cochés ou à tous les élèves affichés (groupe choisi).
 *
 * Le quota est commun à toutes les activités et à tous les cours : la page
 * montre la consommation totale de l'élève, pas seulement celle de ce cours.
 *
 * @package local_aichat
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/grouplib.php');

use local_aichat\quota;

$courseid = required_param('courseid', PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/aichat:managequota', $context);

$pageurl = new moodle_url('/local/aichat/quotas.php', array('courseid' => $courseid));
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('quotas_page', 'local_aichat'));
$PAGE->set_heading(format_string($course->fullname));

$success = \core\output\notification::NOTIFY_SUCCESS;
$warning = \core\output\notification::NOTIFY_WARNING;

// Élèves affichés : ceux du groupe choisi (mode de groupe du cours). En
// groupes séparés, sans accès à tous les groupes, jamais « tous les élèves ».
$groupmode    = groups_get_course_groupmode($course);
$currentgroup = (int)groups_get_course_group($course, true);
$canseeall    = ($groupmode != SEPARATEGROUPS) || has_capability('moodle/site:accessallgroups', $context);
$students     = ($currentgroup || $canseeall)
    ? get_enrolled_users($context, 'moodle/course:isincompletionreports', $currentgroup, 'u.*',
        'u.lastname, u.firstname', 0, 0, true)
    : array();

$tokenslimit   = quota::tokens_limit();
$messageslimit = quota::messages_limit();

// ---------------------------------------------------------------------------
//  Actions (seulement sur des élèves affichés)
// ---------------------------------------------------------------------------
if ($action === 'reset' || $action === 'bonus') {
    require_sesskey();
    $listed = array_keys($students);
    $single = optional_param('userid', 0, PARAM_INT);
    if ($single) {
        $targets = in_array($single, $listed) ? array($single) : array();
    } else if (optional_param('target', '', PARAM_ALPHA) === 'all') {
        $targets = $listed;
    } else {
        $targets = array_values(array_intersect($listed, optional_param_array('userids', array(), PARAM_INT)));
    }
    if (!$targets) {
        redirect($pageurl, get_string('quotas_nothing_selected', 'local_aichat'), null, $warning);
    }

    if ($action === 'reset') {
        $count = quota::reset_windows($targets, $USER->id);
        redirect($pageurl, get_string('quotas_reset_done', 'local_aichat', $count), null, $success);
    }

    $tokens   = ($tokenslimit > 0) ? max(0, optional_param('bonustokens', 0, PARAM_INT)) : 0;
    $messages = ($messageslimit > 0) ? max(0, optional_param('bonusmessages', 0, PARAM_INT)) : 0;
    if ($tokens === 0 && $messages === 0) {
        redirect($pageurl, get_string('quotas_bonus_empty', 'local_aichat'), null, $warning);
    }
    $count = quota::grant($targets, $tokens, $messages, $USER->id);
    redirect($pageurl, get_string('quotas_bonus_done', 'local_aichat', $count), null, $success);
}

// ---------------------------------------------------------------------------
//  Affichage
// ---------------------------------------------------------------------------
$number = function($n) {
    return number_format((int)$n, 0, '', get_string('thousandssep', 'langconfig'));
};
$used = function($used, $max) use ($number) {
    return $number($used) . ' / ' . (($max > 0) ? $number($max) : get_string('quotas_nolimit', 'local_aichat'));
};
$bar = function($used, $max) {
    if ($max <= 0) {
        return '';
    }
    $pct = min(100, (int)round(100 * $used / $max));
    $class = ($pct >= 100) ? 'bg-danger' : (($pct >= 80) ? 'bg-warning' : 'bg-success');
    return html_writer::div(
        html_writer::div('', 'progress-bar ' . $class, array('role' => 'progressbar',
            'style' => 'width: ' . $pct . '%', 'aria-valuenow' => $pct, 'aria-valuemin' => 0, 'aria-valuemax' => 100)),
        'progress mt-1', array('style' => 'height: 6px; max-width: 10rem;'));
};
$roundedtime = function($seconds) {
    return format_time((int)ceil(max(60, $seconds) / MINSECS) * MINSECS);
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('quotas_page', 'local_aichat'));
echo html_writer::tag('p', get_string('quotas_intro', 'local_aichat', (object)array(
    'hours'    => quota::window_hours(),
    'tokens'   => ($tokenslimit > 0) ? $number($tokenslimit) : get_string('quotas_nolimit', 'local_aichat'),
    'messages' => ($messageslimit > 0) ? $number($messageslimit) : get_string('quotas_nolimit', 'local_aichat'),
)), array('class' => 'text-muted'));

if ($tokenslimit <= 0 && $messageslimit <= 0) {
    echo $OUTPUT->notification(get_string('quotas_nolimits', 'local_aichat'), 'info');
}

echo groups_print_course_menu($course, $pageurl, true);

if (!$students) {
    echo $OUTPUT->notification(get_string('quotas_nostudents', 'local_aichat'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$states = quota::states(array_keys($students));

$granters = array();
foreach ($states as $state) {
    if ($state->grantedby > 0 && ($state->bonustokens > 0 || $state->bonusmessages > 0)) {
        $granters[$state->grantedby] = true;
    }
}
$granternames = array();
foreach ($granters ? $DB->get_records_list('user', 'id', array_keys($granters)) : array() as $user) {
    $granternames[(int)$user->id] = fullname($user);
}

$open = 0;
$blocked = 0;
foreach ($states as $state) {
    $open    += ($state->windowstart > 0) ? 1 : 0;
    $blocked += $state->ok ? 0 : 1;
}
echo html_writer::tag('p', get_string('quotas_summary', 'local_aichat', (object)array(
    'students' => count($students),
    'open'     => $open,
    'blocked'  => $blocked,
)));

// Tableau (formulaire des actions groupées).
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $pageurl->out(false)));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

$master = new \core\output\checkbox_toggleall('aichatquota', true, array(
    'id' => 'aichatquota-all', 'name' => 'aichatquota-all', 'label' => get_string('selectall'),
    'labelclasses' => 'accesshide'));
$table = new html_table();
$table->head = array($OUTPUT->render($master), get_string('student', 'local_aichat'),
    get_string('quotas_col_window', 'local_aichat'), get_string('quotas_col_messages', 'local_aichat'),
    get_string('quotas_col_tokens', 'local_aichat'), get_string('quotas_col_bonus', 'local_aichat'),
    get_string('quotas_col_state', 'local_aichat'), '');
$table->attributes['class'] = 'generaltable';

$now = time();
foreach ($students as $student) {
    $state = $states[(int)$student->id];
    $checkbox = new \core\output\checkbox_toggleall('aichatquota', false, array(
        'id' => 'aichatquota-' . $student->id, 'name' => 'userids[]', 'value' => $student->id,
        'label' => get_string('selectitem', 'moodle', fullname($student)), 'labelclasses' => 'accesshide'));

    if ($state->windowstart > 0) {
        $window = get_string('quotas_window_open', 'local_aichat', (object)array(
                'start' => quota::time_label($state->windowstart),
                'reset' => quota::time_label($state->resetat),
            ))
            . html_writer::div(get_string('quotas_window_resetin', 'local_aichat',
                $roundedtime($state->resetat - $now)), 'small text-muted');
    } else {
        $window = html_writer::span(get_string('quotas_window_none', 'local_aichat'), 'text-muted');
    }

    $bonus = '';
    if ($state->bonustokens > 0 || $state->bonusmessages > 0) {
        $parts = array();
        if ($state->bonustokens > 0) {
            $parts[] = get_string('quotas_bonus_tokens_value', 'local_aichat', $number($state->bonustokens));
        }
        if ($state->bonusmessages > 0) {
            $parts[] = get_string('quotas_bonus_messages_value', 'local_aichat', $number($state->bonusmessages));
        }
        $bonus = implode(' · ', $parts);
        if ($state->windowstart === 0) {
            $bonus .= html_writer::div(get_string('quotas_bonus_pending', 'local_aichat'), 'small text-muted');
        }
        if ($state->granttime > 0) {
            $bonus .= html_writer::div(get_string('quotas_granted_by', 'local_aichat', (object)array(
                'name' => isset($granternames[$state->grantedby]) ? $granternames[$state->grantedby] : '?',
                'time' => quota::time_label($state->granttime),
            )), 'small text-muted');
        }
    }

    $actions = '';
    if ($state->windowstart > 0 || $bonus !== '') {
        $actions = html_writer::link(new moodle_url($pageurl, array(
            'action' => 'reset', 'userid' => $student->id, 'sesskey' => sesskey())),
            get_string('quotas_action_reset', 'local_aichat'));
    }

    $table->data[] = array(
        $OUTPUT->render($checkbox),
        html_writer::link(new moodle_url('/user/view.php', array('id' => $student->id, 'course' => $courseid)),
            s(fullname($student))),
        $window,
        $used($state->messagesused, $state->messagesmax) . $bar($state->messagesused, $state->messagesmax),
        $used($state->tokensused, $state->tokensmax) . $bar($state->tokensused, $state->tokensmax),
        $bonus,
        $state->ok ? '' : html_writer::span(get_string('quotas_state_blocked', 'local_aichat'), 'badge bg-danger text-white'),
        $actions,
    );
}
echo html_writer::table($table);

// Actions groupées. Le bonus vient en premier : Entrée dans un de ses champs
// déclenche le premier bouton du formulaire.
if ($tokenslimit > 0 || $messageslimit > 0) {
    echo html_writer::start_div('card card-body mt-3');
    echo $OUTPUT->heading(get_string('quotas_bulk_heading', 'local_aichat'), 4);

    $radio = function($value, $label, $checked) {
        $id = 'aichatquota-target-' . $value;
        $attrs = array('type' => 'radio', 'name' => 'target', 'value' => $value, 'id' => $id,
            'class' => 'form-check-input');
        if ($checked) {
            $attrs['checked'] = 'checked';
        }
        return html_writer::div(html_writer::empty_tag('input', $attrs)
            . html_writer::tag('label', $label, array('for' => $id, 'class' => 'form-check-label')),
            'form-check form-check-inline');
    };
    echo html_writer::div(
        html_writer::span(get_string('quotas_target', 'local_aichat'), 'me-2')
        . $radio('selected', get_string('quotas_target_selected', 'local_aichat'), true)
        . $radio('all', get_string('quotas_target_all', 'local_aichat', count($students)), false),
        'mb-3');

    $field = function($name, $label, $value) {
        $id = 'aichatquota-' . $name;
        return html_writer::div(
            html_writer::tag('label', $label, array('for' => $id, 'class' => 'form-label small mb-0'))
            . html_writer::empty_tag('input', array('type' => 'number', 'name' => $name, 'id' => $id,
                'value' => $value, 'min' => 0, 'step' => 1, 'class' => 'form-control', 'style' => 'width: 9rem;')));
    };
    echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-end');
    if ($tokenslimit > 0) {
        echo $field('bonustokens', get_string('quotas_bonus_tokens', 'local_aichat'), $tokenslimit);
    }
    if ($messageslimit > 0) {
        echo $field('bonusmessages', get_string('quotas_bonus_messages', 'local_aichat'), $messageslimit);
    }
    echo html_writer::tag('button', get_string('quotas_bonus_submit', 'local_aichat'),
        array('type' => 'submit', 'name' => 'action', 'value' => 'bonus', 'class' => 'btn btn-primary'));
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('quotas_bonus_help', 'local_aichat'), array('class' => 'small text-muted mt-1'));

    echo html_writer::tag('button', get_string('quotas_reset_submit', 'local_aichat'),
        array('type' => 'submit', 'name' => 'action', 'value' => 'reset', 'class' => 'btn btn-secondary'));
    echo html_writer::tag('p', get_string('quotas_reset_help', 'local_aichat'), array('class' => 'small text-muted mt-1 mb-0'));
    echo html_writer::end_div();
}
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
