<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mises à jour de schéma pour local_aifeedback.
 */
function xmldb_local_aifeedback_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // 2026052900 : table partagée de suivi des corrections IA des questions de
    // quiz (consommée par qtype_aiessay, qtype_aishortanswer, …).
    if ($oldversion < 2026052900) {
        $table = new xmldb_table('local_aifeedback_qgrading');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('questionattemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('aifeedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('mark', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $table->add_key('questionattempt', XMLDB_KEY_UNIQUE, array('questionattemptid'));

            $table->add_index('component_status', XMLDB_INDEX_NOTUNIQUE, array('component', 'status'));
            $table->add_index('comp_question_user', XMLDB_INDEX_NOTUNIQUE, array('component', 'questionid', 'userid'));
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, array('userid'));

            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026052900, 'local', 'aifeedback');
    }

    return true;
}
