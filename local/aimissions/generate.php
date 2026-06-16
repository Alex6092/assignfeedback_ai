<?php
/**
 * Formulaire de génération de missions client : l'enseignant choisit la
 * compétence, le niveau, la complexité et les groupes cibles, puis enfile un
 * job de génération par groupe (traités en différé par le cron / dispatcher LLM).
 */

require_once(__DIR__ . '/../../config.php');

use local_aimissions\efe_bridge;
use local_aimissions\form\generate_form;
use local_aimissions\job_handler;

$courseid = required_param('courseid', PARAM_INT);

$course  = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/aimissions:generate', $context);

$PAGE->set_url(new moodle_url('/local/aimissions/generate.php', array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_aimissions'));
$PAGE->set_heading(format_string($course->fullname));

// Groupes du cours.
$groups = array();
foreach (groups_get_all_groups($courseid) as $g) {
    $groups[(int)$g->id] = format_string($g->name);
}

// Référentiel de compétences EFE (vide si plugin absent / non configuré).
$competences = efe_bridge::get_competences();

$mform = new generate_form(null, array(
    'courseid'      => $courseid,
    'groups'        => $groups,
    'competences'   => $competences,
    'efeconfigured' => !empty($competences['configured']),
));

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
}

if ($data = $mform->get_data()) {

    // --- Compétence : code EFE (level:code) ou libellé libre --------------
    $codes = array('n1' => '', 'n2' => '', 'n3' => '');
    $complabel = isset($data->competencylabel) ? trim((string)$data->competencylabel) : '';

    if (!empty($data->competency)) {
        list($lvl, $code) = array_pad(explode(':', $data->competency, 2), 2, '');
        // Cartes code → parent_code et code → nom pour remonter la hiérarchie.
        $parent = array('n2' => array(), 'n3' => array());
        $names  = array();
        foreach (array('n1', 'n2', 'n3') as $l) {
            foreach (($competences[$l] ?? array()) as $c) {
                $cc = (string)($c['code'] ?? '');
                $names[$cc] = (string)($c['nom'] ?? '');
                if ($l !== 'n1') {
                    $parent[$l][$cc] = (string)($c['parent_code'] ?? '');
                }
            }
        }
        if ($lvl === 'n3') {
            $codes['n3'] = $code;
            $codes['n2'] = $parent['n3'][$code] ?? '';
            $codes['n1'] = $codes['n2'] !== '' ? ($parent['n2'][$codes['n2']] ?? '') : '';
        } else if ($lvl === 'n2') {
            $codes['n2'] = $code;
            $codes['n1'] = $parent['n2'][$code] ?? '';
        } else {
            $codes['n1'] = $code;
        }
        if ($complabel === '') {
            $complabel = $names[$code] ?? '';
        }
    }

    // --- Un job par groupe coché -----------------------------------------
    $now = time();
    $created = 0;
    foreach ($data as $key => $value) {
        if (strpos($key, 'group_') !== 0 || empty($value)) {
            continue;
        }
        $gid = (int)substr($key, strlen('group_'));
        if ($gid <= 0 || !isset($groups[$gid])) {
            continue;
        }

        $params = array(
            'groupid'        => $gid,
            'module'         => (string)$data->module,
            'level'          => (string)$data->level,
            'complexity'     => (string)$data->complexity,
            'constraints'    => (int)$data->constraints,
            'personaprofile' => (string)$data->personaprofile,
            'competencylabel' => $complabel,
            'efe_n1'         => $codes['n1'],
            'efe_n2'         => $codes['n2'],
            'efe_n3'         => $codes['n3'],
        );

        $job = new stdClass();
        $job->courseid     = $courseid;
        $job->userid       = $USER->id;
        $job->projectid    = 0;
        $job->kind         = 'mission';
        $job->params       = json_encode($params, JSON_UNESCAPED_UNICODE);
        $job->status       = 'pending';
        $job->log          = '';
        $job->lasterror    = null;
        $job->resultmissionid = 0;
        $job->attempts     = 0;
        $job->timecreated  = $now;
        $job->timemodified = $now;
        $job->id = $DB->insert_record('local_aimissions_job', $job);

        job_handler::enqueue($job->id);
        $created++;
    }

    redirect(
        new moodle_url('/local/aimissions/status.php', array('courseid' => $courseid)),
        get_string('jobs_queued', 'local_aimissions', $created),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_aimissions'));
echo html_writer::div(get_string('form_intro', 'local_aimissions'), 'lead mb-3');
$mform->display();
echo $OUTPUT->footer();
