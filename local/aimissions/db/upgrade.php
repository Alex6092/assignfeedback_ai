<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Migrations de schéma pour local_aimissions.
 *
 * @param int $oldversion version actuellement installée
 * @return bool
 */
function xmldb_local_aimissions_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026060505) {
        // Table d'évaluation de la communication client (compétence EFE).
        $table = new xmldb_table('local_aimissions_commeval');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('projectid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('efecode', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('colour', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('score', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('project_user', XMLDB_INDEX_UNIQUE, array('projectid', 'userid'));
        $table->add_index('projectid', XMLDB_INDEX_NOTUNIQUE, array('projectid'));
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026060505, 'local', 'aimissions');
    }

    if ($oldversion < 2026060506) {
        // Communication asynchrone + dynamique de relation client.

        // ticket.reaction : null|warning|ended (recadrage / rupture).
        $table = new xmldb_table('local_aimissions_ticket');
        $field = new xmldb_field('reaction', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // project.clientstatus + clientwarnings.
        $table = new xmldb_table('local_aimissions_project');
        $field = new xmldb_field('clientstatus', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active', 'currentsprint');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('clientwarnings', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'clientstatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // job.notbefore : exécution différée (réponses client).
        $table = new xmldb_table('local_aimissions_job');
        $field = new xmldb_field('notbefore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026060506, 'local', 'aimissions');
    }

    return true;
}
