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

    // 2026091800 : pool de serveurs LLM partagé (tickets de file + santé des
    // serveurs), utilisé par le tuteur interactif local_aichat et, à terme,
    // par la file de corrections.
    if ($oldversion < 2026091800) {

        $table = new xmldb_table('local_aifeedback_slot');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('purpose', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'tutor');
            $table->add_field('serverid', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'queued');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('reference', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timereserved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeheartbeat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

            $table->add_index('status_purpose', XMLDB_INDEX_NOTUNIQUE, array('status', 'purpose'));
            $table->add_index('status_server', XMLDB_INDEX_NOTUNIQUE, array('status', 'serverid'));
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, array('userid'));
            $table->add_index('timefinished', XMLDB_INDEX_NOTUNIQUE, array('timefinished'));

            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_aifeedback_server');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('serverid', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('failinguntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('failures', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $table->add_key('serverid', XMLDB_KEY_UNIQUE, array('serverid'));

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091800, 'local', 'aifeedback');
    }

    // 2026091801 : les réinitialisations de cours passées (« Supprimer toutes
    // les tentatives ») laissaient les corrections IA de quiz en base, feedback
    // compris. Nettoyage unique des lignes dont la tentative n'existe plus.
    if ($oldversion < 2026091801) {
        $DB->delete_records_select('local_aifeedback_qgrading',
            'questionattemptid NOT IN (SELECT id FROM {question_attempts})');
        upgrade_plugin_savepoint(true, 2026091801, 'local', 'aifeedback');
    }

    return true;
}
