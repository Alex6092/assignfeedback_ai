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

    return true;
}
