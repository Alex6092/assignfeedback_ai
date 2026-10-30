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

    // 2026091802 : la file de jobs passe sur le pool (basculement entre
    // serveurs) — chaque ticket retient les serveurs déjà essayés.
    if ($oldversion < 2026091802) {
        $table = new xmldb_table('local_aifeedback_slot');
        $field = new xmldb_field('excluded', XMLDB_TYPE_CHAR, '32', null,
            XMLDB_NOTNULL, null, '', 'reference');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026091802, 'local', 'aifeedback');
    }

    // 2026103000 : la répartition de charge quitte Moodle pour le load
    // balancer d'inférence. Les générations deviennent des jobs asynchrones
    // suivis dans {local_aifeedback_job} ; le pool (tickets, état des
    // serveurs, réglages server1..3) disparaît.
    if ($oldversion < 2026103000) {
        $table = new xmldb_table('local_aifeedback_job');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('payloadkey', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('model', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('lbjobid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('lbstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('eventid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'submitting');
            $table->add_field('attempt', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('expectjson', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('result', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timesubmitted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
            $table->add_index('lbjobid', XMLDB_INDEX_NOTUNIQUE, array('lbjobid'));
            $table->add_index('comp_key_status', XMLDB_INDEX_NOTUNIQUE, array('component', 'payloadkey', 'status'));
            $table->add_index('status_submitted', XMLDB_INDEX_NOTUNIQUE, array('status', 'timesubmitted'));
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, array('userid'));
            $dbman->create_table($table);
        }

        foreach (array('local_aifeedback_slot', 'local_aifeedback_server') as $name) {
            $old = new xmldb_table($name);
            if ($dbman->table_exists($old)) {
                $dbman->drop_table($old);
            }
        }

        // Le modèle global devient le « modèle des corrections » : même
        // valeur au départ, à vérifier dans le catalogue du load balancer.
        $model = trim((string)get_config('local_aifeedback', 'model'));
        if ($model !== '' && get_config('local_aifeedback', 'model_feedback') === false) {
            set_config('model_feedback', $model, 'local_aifeedback');
        }
        $obsolete = array('apiurl', 'model', 'apikey', 'pool_feedback', 'pool_acquire_wait',
            'pool_heartbeat_ttl', 'pool_reserve_ttl', 'pool_queue_ttl', 'pool_failcooldown',
            'pool_lastpurge', 'stream_usage');
        for ($i = 1; $i <= 3; $i++) {
            foreach (array('apiurl', 'model', 'apikey', 'maxconcurrency', 'use_feedback', 'use_tutor') as $suffix) {
                $obsolete[] = 'server' . $i . '_' . $suffix;
            }
        }
        foreach ($obsolete as $name) {
            unset_config($name, 'local_aifeedback');
        }

        upgrade_plugin_savepoint(true, 2026103000, 'local', 'aifeedback');
    }

    return true;
}
