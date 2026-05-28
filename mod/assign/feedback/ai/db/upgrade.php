<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_assignfeedback_ai_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026050900) {
        $table = new xmldb_table('assignfeedback_ai');

        $fields = array(
            new xmldb_field('apiurl_override', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'apiurl'),
            new xmldb_field('model_override',  XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'model'),
            new xmldb_field('apikey',          XMLDB_TYPE_TEXT,    null, null,
                null,          null, null, 'model_override'),
            new xmldb_field('apikey_override', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'apikey'),
        );

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026050900, 'assignfeedback', 'ai');
    }

    if ($oldversion < 2026051000) {
        $table = new xmldb_table('assignfeedback_ai');

        $fields = array(
            new xmldb_field('vision_enabled',          XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'apikey_override'),
            new xmldb_field('vision_enabled_override', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'vision_enabled'),
        );

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026051000, 'assignfeedback', 'ai');
    }

    if ($oldversion < 2026051100) {
        // Phase 3.A — migration des réglages globaux vers local_aifeedback.
        // L'install de local_aifeedback est garantie par $plugin->dependencies
        // dans version.php, donc on peut écrire dans son namespace de config.
        $tomigrate = array(
            'apiurl', 'model', 'apikey', 'defaultsystemprompt',
            'vision_enabled', 'maximagespersubmission', 'imagemindimension',
            'pdftotextpath', 'pdftoppmpath', 'pdfimagespath',
        );
        foreach ($tomigrate as $key) {
            $oldvalue = get_config('assignfeedback_ai', $key);
            if ($oldvalue === false || $oldvalue === '' || $oldvalue === null) {
                continue; // rien à migrer
            }
            // On ne réécrit pas si une valeur a déjà été posée côté local.
            $existing = get_config('local_aifeedback', $key);
            if ($existing === false || $existing === '' || $existing === null) {
                set_config($key, $oldvalue, 'local_aifeedback');
            }
            // On laisse les anciennes valeurs en base pour permettre un rollback
            // (Moodle se chargera de les ignorer puisque le code ne les lit plus).
        }

        upgrade_plugin_savepoint(true, 2026051100, 'assignfeedback', 'ai');
    }

    return true;
}
