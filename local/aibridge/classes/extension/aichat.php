<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Tuteur IA (local_aichat) : réglages de l'activité et brief pédagogique,
 * fourni pour relecture. Les conversations des élèves ne sont jamais lues.
 */
class aichat implements extension {

    public function key(): string {
        return 'aichat';
    }

    public function component(): string {
        return 'local_aichat';
    }

    public function is_available(): bool {
        return util::table_exists('local_aichat_activity');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;
        $row = $DB->get_record('local_aichat_activity', array('cmid' => $cm->id),
            'enabled, includeintro, includebrief, customprompt, websearch, websearchsites, '
            . 'brief, briefstatus, brieferror, timemodified');
        if (!$row) {
            return null;
        }
        $data = (array)$row;
        $data['timemodified'] = util::date($row->timemodified);
        return $data;
    }

    public function summary(array $data): ?string {
        if (empty($data['enabled'])) {
            return null;
        }
        $parts = array();
        if (!empty($data['includeintro'])) {
            $parts[] = 'consigne';
        }
        if (!empty($data['includebrief'])) {
            $parts[] = 'brief ' . $data['briefstatus'];
        }
        return 'Tuteur IA' . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    }
}
