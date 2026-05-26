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

    return true;
}
