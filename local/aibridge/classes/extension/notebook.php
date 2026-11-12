<?php
namespace local_aibridge\extension;
defined('MOODLE_INTERNAL') || die();

class notebook implements extension {
    public function key(): string { return 'notebook'; }
    public function component(): string { return 'local_notebook'; }
    public function is_available(): bool { return \local_aibridge\util::table_exists('local_notebook_version'); }
    public function read(\cm_info $cm): ?array {
        global $DB;
        if ($cm->modname !== 'assign') { return null; }
        $versions = $DB->get_records('local_notebook_version', ['cmid' => $cm->id], 'id DESC',
            'id, fingerprint, status, timecreated');
        if (!$versions) { return null; }
        $current = \local_notebook\manager::current($cm->id);
        return ['active_version' => $current ? (int)$current->id : null, 'versions' => array_values($versions)];
    }
    public function summary(array $data): ?string { return 'Notebook Python'; }
}
