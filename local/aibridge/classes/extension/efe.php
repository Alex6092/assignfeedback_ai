<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Report EFE (local_efenotes) : compétences rapportées par l'activité,
 * ponctualité, seuils, libellé. Les reports eux-mêmes (notes des élèves)
 * ne sont jamais lus.
 */
class efe implements extension {

    public function key(): string {
        return 'efe';
    }

    public function component(): string {
        return 'local_efenotes';
    }

    public function is_available(): bool {
        return util::table_exists('local_efenotes_activity');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;
        $row = $DB->get_record('local_efenotes_activity', array('cmid' => $cm->id));
        if (!$row) {
            return null;
        }
        $data = util::row($row, array('id', 'cmid', 'courseid', 'timecreated'));
        $data['timemodified'] = util::date($row->timemodified);
        if (!empty($row->competences_json)) {
            $decoded = json_decode($row->competences_json, true);
            if (is_array($decoded)) {
                $data['competences_json'] = $decoded;
            }
        }
        return $data;
    }

    public function summary(array $data): ?string {
        if (empty($data['enabled'])) {
            return null;
        }
        $codes = array();
        if (isset($data['competences_json']['quality']) && is_array($data['competences_json']['quality'])) {
            $codes = $data['competences_json']['quality'];
        }
        if (!$codes && !empty($data['competence_n1_code'])) {
            $codes = array($data['competence_n1_code']);
        }
        return 'EFE ' . ($codes ? implode(', ', $codes) : '(aucune compétence !)');
    }
}
