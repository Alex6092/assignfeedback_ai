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

    return true;
}
