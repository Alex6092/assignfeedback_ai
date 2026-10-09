<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Avancement attendu (block_avancementlapro) : rang de l'activité dans la
 * progression, échéance, affichage de la correction.
 */
class avancement implements extension {

    public function key(): string {
        return 'avancement';
    }

    public function component(): string {
        return 'block_avancementlapro';
    }

    public function is_available(): bool {
        return util::table_exists('local_avancementlapro_item');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;
        $row = $DB->get_record('local_avancementlapro_item', array('cmid' => $cm->id),
            'sortorder, duedate, correctionvisible, correctionauto, nativesync');
        if (!$row) {
            return null;
        }
        $data = (array)$row;
        $data['duedate'] = util::date($row->duedate);
        return $data;
    }

    public function summary(array $data): ?string {
        return 'Avancement #' . $data['sortorder'] . ($data['duedate'] ? ' (échéance ' . substr($data['duedate'], 0, 10) . ')' : '');
    }
}
