<?php
namespace local_aibridge\extension;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Correction IA d'un devoir (assignfeedback_ai) : activation dans
 * assign_plugin_config, réglages dans la table assignfeedback_ai. La clé
 * d'API est masquée par le pont.
 */
class aifeedback implements extension {

    public function key(): string {
        return 'aifeedback';
    }

    public function component(): string {
        return 'assignfeedback_ai';
    }

    public function is_available(): bool {
        return util::table_exists('assignfeedback_ai');
    }

    public function read(\cm_info $cm): ?array {
        global $DB;
        if ($cm->modname !== 'assign') {
            return null;
        }
        $enabled = $DB->get_field('assign_plugin_config', 'value', array(
            'assignment' => $cm->instance, 'subtype' => 'assignfeedback', 'plugin' => 'ai', 'name' => 'enabled'));
        $row = $DB->get_record('assignfeedback_ai', array('assignment' => $cm->instance));
        if ($enabled === false && !$row) {
            return null;
        }
        $config = util::row($row, array('id', 'assignment'));
        return array(
            'enabled' => (int)$enabled,
            // Devoir activé sans ligne de réglages : cas d'un devoir créé hors formulaire.
            'config'  => $config,
        );
    }

    public function summary(array $data): ?string {
        if (empty($data['enabled'])) {
            return null;
        }
        return empty($data['config']) ? 'Correction IA (sans réglages !)' : 'Correction IA';
    }
}
