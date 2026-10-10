<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Contrôle des tentatives (local_attemptcheck) : durée minimale attendue de
 * l'activité. Les tentatives examinées ne sont pas lues.
 */
class attemptcheck implements extension {

    public function key(): string {
        return 'attemptcheck';
    }

    public function component(): string {
        return 'local_attemptcheck';
    }

    public function is_available(): bool {
        return util::table_exists('local_attemptcheck_activity');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;
        $minduration = $DB->get_field('local_attemptcheck_activity', 'minduration', array('cmid' => $cm->id));
        if ($minduration === false) {
            return null;
        }
        return array('minduration' => (int)$minduration, 'minduration_minutes' => round($minduration / 60, 1));
    }

    public function summary(array $data): ?string {
        if (empty($data['minduration'])) {
            return null;
        }
        return 'Durée min. ' . $data['minduration_minutes'] . ' min';
    }
}
