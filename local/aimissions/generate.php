<?php
/**
 * Formulaire de génération d'un sprint : l'enseignant décrit le contexte
 * pédagogique, choisit les compétences, le niveau, la complexité, le tuteur et
 * les groupes cibles (qu'il peut créer ici), puis enfile un job de génération
 * par groupe (traités en différé par le cron / dispatcher LLM).
 */

require_once(__DIR__ . '/../../config.php');

use local_aimissions\aichat_bridge;
use local_aimissions\efe_bridge;
use local_aimissions\form\generate_form;
use local_aimissions\group_helper;
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

$canmanagegroups = has_capability('moodle/course:managegroups', $context);

// Référentiel de compétences EFE (vide si plugin absent / non configuré).
$efeloaderror = null;
$competences  = efe_bridge::competence_options($efeloaderror);

/**
 * Contexte pédagogique du dernier sprint du cours : on le ressaisit rarement.
 */
$defaultcontext = '';
$recent = $DB->get_records_sql(
    'SELECT m.id, m.pedagogicalcontext
       FROM {local_aimissions_mission} m
       JOIN {local_aimissions_project} p ON p.id = m.projectid
      WHERE p.courseid = ? AND m.pedagogicalcontext IS NOT NULL
   ORDER BY m.timecreated DESC, m.id DESC',
    array($courseid), 0, 5);
foreach ($recent as $row) {
    if (trim((string)$row->pedagogicalcontext) !== '') {
        $defaultcontext = (string)$row->pedagogicalcontext;
        break;
    }
}

$build_form = function() use ($courseid, $competences, $efeloaderror, $canmanagegroups, $defaultcontext) {
    $groups = array();
    foreach (groups_get_all_groups($courseid) as $g) {
        $groups[(int)$g->id] = format_string($g->name);
    }
    $checked = array_filter(array_map('intval',
        explode(',', optional_param('checked', '', PARAM_SEQUENCE))));
    $form = new generate_form(null, array(
        'courseid'        => $courseid,
        'groups'          => $groups,
        'competences'     => $competences,
        'efeconfigured'   => efe_bridge::is_configured(),
        'efeloaderror'    => $efeloaderror,
        'canmanagegroups' => $canmanagegroups,
        'aichat'          => aichat_bridge::is_available(),
        'checked'         => $checked,
        'defaultcontext'  => $defaultcontext,
    ));
    return array($form, $groups);
};

list($mform, $groups) = $build_form();

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', array('id' => $courseid)));
}

// --- « Créer ces groupes » : sans soumettre le reste du formulaire -----------
$notice = '';
if ($mform->no_submit_button_pressed() && optional_param('creategroups', '', PARAM_RAW) !== '') {
    require_sesskey();
    require_capability('moodle/course:managegroups', $context);
    $result = group_helper::create_from_text($courseid, optional_param('newgroups', '', PARAM_TEXT));
    // Le formulaire est réaffiché avec ce qui avait déjà été saisi (moodleform
    // relit $_POST) : on coche les groupes créés et on vide la zone de saisie.
    foreach ($result['ids'] as $gid) {
        $_POST['group_' . $gid] = 1;
    }
    $_POST['newgroups'] = '';
    list($mform, $groups) = $build_form();
    $notice = get_string('groups_created', 'local_aimissions', $result['created']);
}

if ($data = $mform->get_data()) {

    // --- Groupes saisis mais pas encore créés ----------------------------
    $targets = array();
    if ($canmanagegroups && trim((string)($data->newgroups ?? '')) !== '') {
        $result = group_helper::create_from_text($courseid, (string)$data->newgroups);
        $targets = $result['ids'];
    }
    foreach ($data as $key => $value) {
        if (strpos($key, 'group_') === 0 && !empty($value)) {
            $gid = (int)substr($key, strlen('group_'));
            if ($gid > 0 && isset($groups[$gid])) {
                $targets[] = $gid;
            }
        }
    }
    $targets = array_values(array_unique($targets));

    // --- Compétences : codes EFE, ou libellé libre -----------------------
    $codes = \local_aimissions\assign_factory::clean_codes($data->competencies ?? array());
    $codes = array_values(array_filter($codes, function($code) use ($competences) {
        return isset($competences[$code]);
    }));
    $complabel = trim((string)($data->competencylabel ?? ''));
    if (!empty($codes)) {
        $complabel = implode(' ; ', efe_bridge::competence_labels($codes, $competences));
    }

    // --- Un job par groupe ciblé -----------------------------------------
    $now = time();
    $created = 0;
    foreach ($targets as $gid) {
        $params = array(
            'groupid'            => $gid,
            'pedagogicalcontext' => (string)($data->pedagogicalcontext ?? ''),
            'level'              => (string)$data->level,
            'complexity'         => (string)$data->complexity,
            'constraints'        => (int)$data->constraints,
            'personaprofile'     => (string)$data->personaprofile,
            'competencylabel'    => $complabel,
            'efe_codes'          => $codes,
            'aichat'             => !empty($data->aichat) ? 1 : 0,
            'aichatsearch'       => (int)($data->aichatsearch ?? 0),
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
if ($notice !== '') {
    echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_SUCCESS);
}
echo html_writer::div(get_string('form_intro', 'local_aimissions'), 'lead mb-3');
$mform->display();
echo $OUTPUT->footer();
