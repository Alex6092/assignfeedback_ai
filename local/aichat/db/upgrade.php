<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mises à jour de schéma pour local_aichat.
 */
function xmldb_local_aichat_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // 2026091803 : modération des messages des élèves (signalement à
    // l'enseignant par une analyse LLM séparée, hors du flux du tuteur).
    if ($oldversion < 2026091803) {
        $table = new xmldb_table('local_aichat_message');

        $fields = array(
            new xmldb_field('flagstatus', XMLDB_TYPE_CHAR, '16', null,
                XMLDB_NOTNULL, null, 'none', 'error'),
            new xmldb_field('flagcategory', XMLDB_TYPE_CHAR, '32', null,
                XMLDB_NOTNULL, null, '', 'flagstatus'),
            new xmldb_field('flagreason', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'flagcategory'),
            new xmldb_field('flagattempts', XMLDB_TYPE_INTEGER, '4', null,
                XMLDB_NOTNULL, null, '0', 'flagreason'),
            new xmldb_field('flagtime', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'flagattempts'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $index = new xmldb_index('flagstatus', XMLDB_INDEX_NOTUNIQUE, array('flagstatus'));
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026091803, 'local', 'aichat');
    }

    return true;
}
