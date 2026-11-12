<?php
require_once(__DIR__ . '/../../config.php');
$runid = required_param('runid', PARAM_INT);
$run = $DB->get_record('local_notebook_run', ['id' => $runid], '*', MUST_EXIST);
$assign = \local_notebook\manager::assignment($run->cmid);
require_login($assign->get_course(), false, $assign->get_course_module());
if (!$assign->can_view_submission($run->userid)) {
    throw new moodle_exception('accessdenied', 'local_notebook');
}
$PAGE->set_context($assign->get_context());
$PAGE->set_url(new moodle_url('/local/notebook/view.php', ['runid' => $runid]));
$PAGE->set_title(get_string('viewcopy', 'local_notebook'));
$file = get_file_storage()->get_file($assign->get_context()->id, 'assignsubmission_notebook',
    'submission', $runid, '/', 'assignment.ipynb');
if (!$file) { throw new moodle_exception('notready', 'local_notebook'); }
$notebook = json_decode($file->get_content(), true, 512, JSON_THROW_ON_ERROR);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewcopy', 'local_notebook'));
echo html_writer::div(s(userdate($run->timecreated) . ' — SHA256 ' . $run->fingerprint));
echo $OUTPUT->notification(get_string('storedoutputs', 'local_notebook'), 'info');
foreach (array_slice($notebook['cells'] ?? [], 0, 300) as $cell) {
    $source = $cell['source'] ?? '';
    $source = is_array($source) ? implode('', $source) : (string)$source;
    // Stored text is untrusted. No JavaScript, HTML output, SVG or remote image is rendered.
    echo html_writer::tag('pre', s($source), ['class' => 'border rounded p-3']);
    foreach (array_slice($cell['outputs'] ?? [], 0, 100) as $output) {
        $text = $output['text'] ?? ($output['data']['text/plain'] ?? ($output['evalue'] ?? ''));
        $text = is_array($text) ? implode('', $text) : (string)$text;
        if ($text !== '') { echo html_writer::tag('pre', s($text)); }
        $png = $output['data']['image/png'] ?? '';
        $png = is_array($png) ? implode('', $png) : $png;
        if (is_string($png) && strlen($png) < 2 * 1024 * 1024 && ($raw = base64_decode($png, true)) !== false) {
            $info = @getimagesizefromstring($raw);
            if ($info && $info[2] === IMAGETYPE_PNG && $info[0] * $info[1] <= 16000000) {
                echo html_writer::empty_tag('img', ['src' => 'data:image/png;base64,' . base64_encode($raw),
                    'alt' => get_string('storedplot', 'local_notebook'), 'class' => 'img-fluid']);
            }
        }
    }
}
echo $OUTPUT->footer();
