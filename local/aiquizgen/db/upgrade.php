<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mises à jour du schéma de local_aiquizgen.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_aiquizgen_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // 2026103000 : la génération devient une machine à états (une étape par
    // type de question, chacune confiée au load balancer) : étape en cours,
    // tentatives de l'étape, questions déjà obtenues.
    if ($oldversion < 2026103000) {
        $table  = new xmldb_table('local_aiquizgen_jobs');
        $fields = array(
            new xmldb_field('step', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '', 'sourcetext'),
            new xmldb_field('stepattempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'step'),
            new xmldb_field('generated', XMLDB_TYPE_TEXT, null, null, null, null, null, 'stepattempts'),
        );
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Générations interrompues par la mise à jour : elles reprennent du
        // début (la file précédente n'existe plus).
        $DB->set_field('local_aiquizgen_jobs', 'status', 'pending', array('status' => 'running'));

        upgrade_plugin_savepoint(true, 2026103000, 'local', 'aiquizgen');
    }

    return true;
}
