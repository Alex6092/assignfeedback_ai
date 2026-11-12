<?php
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
$assign = \local_notebook\manager::assignment($cmid);
require_login($assign->get_course(), false, $assign->get_course_module());
require_capability('local/notebook:manage', $assign->get_context());
$PAGE->set_context($assign->get_context());
$PAGE->set_url(new moodle_url('/local/notebook/manage.php', ['cmid' => $cmid]));
$PAGE->set_title(get_string('manage', 'local_notebook'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    $version = optional_param('version', 0, PARAM_INT);
    if ($version && !$DB->record_exists('local_notebook_version', ['id' => $version, 'cmid' => $cmid])) {
        throw new moodle_exception('accessdenied', 'local_notebook');
    }
    if ($action === 'import') {
        \local_notebook\manager::put_version($cmid, json_decode(required_param('bundle', PARAM_RAW), true, 512, JSON_THROW_ON_ERROR));
    } else if ($action === 'validate') {
        \local_notebook\manager::validate($version);
    } else if ($action === 'publish') {
        \local_notebook\manager::publish($version);
    } else if ($action === 'generate') {
        $id = $DB->insert_record('local_notebook_gen', (object)['cmid' => $cmid, 'userid' => $USER->id,
            'prompt' => required_param('prompt', PARAM_TEXT), 'status' => 'pending', 'timecreated' => time()]);
        \local_aifeedback\jobs::enqueue('local_notebook', (object)['generationid' => $id]);
    } else if ($action === 'weight') {
        $v = $DB->get_record('local_notebook_version', ['id' => $version, 'cmid' => $cmid], '*', MUST_EXIST);
        $bundle = json_decode($v->bundle, true);
        $bundle['tests_weight'] = required_param('weight', PARAM_INT);
        \local_notebook\manager::put_version($cmid, $bundle);
    }
    redirect($PAGE->url);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage', 'local_notebook'));
$table = new html_table();
$table->head = ['Version', get_string('status', 'local_notebook'), 'Actions'];
foreach ($DB->get_records('local_notebook_version', ['cmid' => $cmid], 'id DESC') as $v) {
    $actions = html_writer::link(new moodle_url('/local/notebook/launch.php', ['cmid' => $cmid,
        'teacher' => 1, 'version' => $v->id]), get_string('open', 'local_notebook'), ['target' => '_blank', 'rel' => 'noopener']);
    foreach (['validate', 'publish'] as $action) {
        if ($action === 'publish' && $v->status !== 'valid') {
            continue;
        }
        $actions .= $OUTPUT->single_button(new moodle_url($PAGE->url, ['action' => $action, 'version' => $v->id]),
            get_string($action, 'local_notebook'), 'post');
    }
    $weight = json_decode($v->bundle, true)['tests_weight'];
    $actions .= html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
    foreach (['sesskey' => sesskey(), 'action' => 'weight', 'version' => $v->id] as $name => $value) {
        $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    $actions .= html_writer::tag('label', get_string('testsweight', 'local_notebook'), ['for' => 'weight-' . $v->id]);
    $actions .= html_writer::empty_tag('input', ['id' => 'weight-' . $v->id, 'name' => 'weight',
        'type' => 'number', 'min' => 0, 'max' => 100, 'value' => $weight]);
    $actions .= html_writer::tag('button', get_string('newversion', 'local_notebook'), ['type' => 'submit']);
    $actions .= html_writer::end_tag('form');
    $table->data[] = [$v->id, s($v->status) . ($v->report ? html_writer::tag('pre', s($v->report)) : ''), $actions];
}
echo html_writer::table($table);
foreach (['import' => 'bundle', 'generate' => 'prompt'] as $action => $field) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
    foreach (['sesskey' => sesskey(), 'action' => $action] as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::tag('label', get_string($action === 'import' ? 'prepare' : 'prompt', 'local_notebook'), ['for' => $field]);
    echo html_writer::tag('textarea', '', ['name' => $field, 'id' => $field, 'rows' => 8, 'class' => 'form-control', 'required' => true]);
    echo html_writer::tag('button', get_string($action === 'import' ? 'prepare' : 'generate', 'local_notebook'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}
$jobs = new html_table();
$jobs->head = ['Copie', 'Élève', 'État', 'Tests / IA / Total', 'Erreur'];
foreach ($DB->get_records('local_notebook_run', ['cmid' => $cmid], 'id DESC', '*', 0, 100) as $run) {
    $result = $run->result ? json_decode($run->result, true) : [];
    $detail = isset($result['notebook']) ? format_float($result['notebook']['tests_score'], 2)
        . ' / ' . format_float($result['notebook']['ai_score'], 2) . ' / ' . format_float($result['score'], 2) : '—';
    $jobs->data[] = [$run->id, $run->userid, s($run->status), s($detail), s($run->error ?? '')];
}
echo html_writer::table($jobs);
$generations = new html_table();
$generations->head = ['Génération', 'État', 'Version', 'Erreur'];
foreach ($DB->get_records('local_notebook_gen', ['cmid' => $cmid], 'id DESC', '*', 0, 50) as $job) {
    $generations->data[] = [$job->id, s($job->status), $job->versionid, s($job->error ?? '')];
}
echo html_writer::table($generations);
if (has_capability('moodle/site:config', \context_system::instance())) {
    foreach (['workspace', 'grader'] as $server) {
        try {
            echo html_writer::tag('pre', s($server . ': ' . json_encode(\local_notebook\client::call($server, '/v1/health'))));
        } catch (\Throwable $e) {
            echo $OUTPUT->notification(s($server . ': ' . $e->getMessage()), 'warning');
        }
    }
}
echo $OUTPUT->footer();
