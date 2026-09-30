<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mises à jour de la base de local_classhours.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_classhours_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026092800) {
        // Périodes fermées globales : case « utiliser les périodes globales »
        // par cours (les périodes elles-mêmes vont dans local_classhours_period
        // avec courseid = 0, sans changement de structure).
        $table = new xmldb_table('local_classhours_course');
        $field = new xmldb_field('useglobal', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'efeauto');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026092800, 'local', 'classhours');
    }

    if ($oldversion < 2026093000) {
        // Accès ponctuel d'un élève à une activité fermée.
        $table = new xmldb_table('local_classhours_grant');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('requestedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('decidedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('decidedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('decisioncomment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('cm_user_status', XMLDB_INDEX_NOTUNIQUE, array('cmid', 'userid', 'status'));
        $table->add_index('course_status', XMLDB_INDEX_NOTUNIQUE, array('courseid', 'status'));
        $table->add_index('course_user', XMLDB_INDEX_NOTUNIQUE, array('courseid', 'userid'));
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026093000, 'local', 'classhours');
    }

    if ($oldversion < 2026093001) {
        // Activités surveillées : ouvertures décidées par l'enseignant.
        $table = new xmldb_table('local_classhours_gate');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('openedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeopened', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('closeat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('closedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeclosed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_index('course_closed', XMLDB_INDEX_NOTUNIQUE, array('courseid', 'timeclosed'));
        $table->add_index('cm_closed', XMLDB_INDEX_NOTUNIQUE, array('cmid', 'timeclosed'));
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026093001, 'local', 'classhours');
    }

    if ($oldversion < 2026100100) {
        // Activités surveillées v2 : code de séance, ramassage en deux temps
        // (tiers-temps), options, dates prévues, rattrapage, tiers-temps, présence.
        $table = new xmldb_table('local_classhours_gate');
        $fields = array(
            new xmldb_field('code', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, '', 'timeclosed'),
            new xmldb_field('collected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'code'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        foreach (array('local_classhours_supervised', 'local_classhours_plan', 'local_classhours_catchup',
                'local_classhours_extratime', 'local_classhours_present') as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026100100, 'local', 'classhours');
    }

    return true;
}
