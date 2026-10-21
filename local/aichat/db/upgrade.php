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

    // 2026091900 : recherche Web optionnelle du tuteur (outil web_search).
    if ($oldversion < 2026091900) {
        $table = new xmldb_table('local_aichat_activity');
        $field = new xmldb_field('websearch', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'quiztag');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table  = new xmldb_table('local_aichat_message');
        $fields = array(
            new xmldb_field('websearches', XMLDB_TYPE_INTEGER, '4', null,
                XMLDB_NOTNULL, null, '0', 'tokens'),
            new xmldb_field('toolcalls', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'websearches'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Registre du plafond du site (aucune donnée personnelle).
        $table = new xmldb_table('local_aichat_wsledger');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'reserved');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, array('timecreated'));
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091900, 'local', 'aichat');
    }

    // 2026091904 : mode « recherche de matériel » (lecture de pages, plafond de
    // recherches par activité, sites de référence).
    if ($oldversion < 2026091904) {
        $table  = new xmldb_table('local_aichat_activity');
        $fields = array(
            new xmldb_field('websearchcap', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'websearch'),
            new xmldb_field('websearchsites', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'websearchcap'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('local_aichat_message');
        $field = new xmldb_field('pagereads', XMLDB_TYPE_INTEGER, '4', null,
            XMLDB_NOTNULL, null, '0', 'websearches');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_aichat_wsledger');
        $field = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('cmid_time', XMLDB_INDEX_NOTUNIQUE, array('cmid', 'timecreated'));
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026091904, 'local', 'aichat');
    }

    // 2026091905 : clés de recherche personnelles des élèves (Tavily, puis
    // Brave). Plafond PAR CLÉ : le plafond par activité disparaît ; le registre
    // retient le moteur et une empreinte de la clé.
    if ($oldversion < 2026091905) {
        $table = new xmldb_table('local_aichat_activity');
        $field = new xmldb_field('websearchcap');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $table  = new xmldb_table('local_aichat_wsledger');
        $fields = array(
            new xmldb_field('provider', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'brave', 'status'),
            new xmldb_field('keyhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '', 'provider'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('keyhash_time', XMLDB_INDEX_NOTUNIQUE, array('keyhash', 'timecreated'));
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Anciens réglages : suspension globale (désormais par moteur) et menu
        // du moteur (l'ordre Tavily → Brave est fixe).
        foreach (array('ws_blockeduntil', 'ws_blockedreason', 'ws_blockeddetail', 'websearch_provider') as $name) {
            unset_config($name, 'local_aichat');
        }

        upgrade_plugin_savepoint(true, 2026091905, 'local', 'aichat');
    }

    // 2026093002 : la consigne n'est plus transmise au tuteur par défaut. Avec
    // elle, le modèle peut juger la réponse de l'élève et la lui confirmer.
    // La case est décochée sur les activités existantes, SAUF les devoirs créés
    // par Missions IA : leur consigne est la demande du client, sans laquelle
    // le tuteur ne sait pas ce que l'équipe doit livrer. L'enseignant peut
    // ensuite recocher ou décocher activité par activité.
    if ($oldversion < 2026093002) {
        $table = new xmldb_table('local_aichat_activity');
        $field = new xmldb_field('includeintro', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }
        if ($dbman->table_exists('local_aimissions_mission')) {
            $DB->execute('UPDATE {local_aichat_activity} SET includeintro = 0
                           WHERE cmid NOT IN (SELECT assigncmid FROM {local_aimissions_mission} WHERE assigncmid > 0)');
        } else {
            $DB->set_field('local_aichat_activity', 'includeintro', 0);
        }
        set_config('includeintrodefault', 0, 'local_aichat');
        upgrade_plugin_savepoint(true, 2026093002, 'local', 'aichat');
    }

    // 2026102100 : quota de l'élève revu.
    //   - Ne compte plus que la question de l'élève et la réponse affichée,
    //     et non toute la requête (prompt système, contexte, historique
    //     renvoyé, résultats de recherche) : les échanges passés sont recomptés
    //     selon la nouvelle règle, pour que les statistiques restent comparables.
    //   - Fenêtre fixe ouverte au premier message (heure de réinitialisation
    //     connue), réinitialisable par l'enseignant, avec bonus. La table
    //     arrive vide : chaque élève repart sur une fenêtre neuve.
    if ($oldversion < 2026102100) {
        $table = new xmldb_table('local_aichat_quota');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bonustokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bonusmessages', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('grantedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('granttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('userid', XMLDB_INDEX_UNIQUE, array('userid'));
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Recomptage : la question précède toujours sa réponse dans la
        // conversation. Les réponses en échec ou annulées restent à 0.
        $conversationid = 0;
        $question = '';
        $rs = $DB->get_recordset('local_aichat_message', null, 'conversationid ASC, id ASC',
            'id, conversationid, role, status, content');
        foreach ($rs as $row) {
            if ((int)$row->conversationid !== $conversationid) {
                $conversationid = (int)$row->conversationid;
                $question = '';
            }
            if ($row->role === 'user') {
                $question = (string)$row->content;
                continue;
            }
            if ($row->status === 'done') {
                $tokens = (int)ceil(core_text::strlen($question) / 4)
                    + (int)ceil(core_text::strlen((string)$row->content) / 4);
                $DB->set_field('local_aichat_message', 'tokens', $tokens, array('id' => (int)$row->id));
            }
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026102100, 'local', 'aichat');
    }

    // 2026102101 : fichiers joints par les élèves (captures, code, PDF).
    if ($oldversion < 2026102101) {
        $table = new xmldb_table('local_aichat_attachment');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('messageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('kind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'text');
        $table->add_field('mimetype', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('chars', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('messageid', XMLDB_INDEX_NOTUNIQUE, array('messageid'));
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026102101, 'local', 'aichat');
    }

    return true;
}
