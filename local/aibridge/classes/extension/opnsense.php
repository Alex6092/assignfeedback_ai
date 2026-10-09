<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Accès Internet ouvert par une activité (block_opnsenseaccess) : domaines
 * autorisés et crédit de minutes. Les accès accordés aux élèves ne sont pas
 * lus.
 */
class opnsense implements extension {

    public function key(): string {
        return 'opnsense';
    }

    public function component(): string {
        return 'block_opnsenseaccess';
    }

    public function is_available(): bool {
        return util::table_exists('block_opnsenseaccess_actgate');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;
        $row = $DB->get_record('block_opnsenseaccess_actgate', array('cmid' => $cm->id));
        if (!$row) {
            return null;
        }
        // Domaines : un par ligne (activity_gate::set_gate()).
        $domains = preg_split('/\R/', (string)($row->domains ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        return array(
            'domains'       => array_values(array_map('trim', $domains)),
            'creditminutes' => (int)($row->creditminutes ?? 0),
        );
    }

    public function summary(array $data): ?string {
        return 'Internet ' . ((int)($data['creditminutes'] ?? 0)) . ' min';
    }
}
