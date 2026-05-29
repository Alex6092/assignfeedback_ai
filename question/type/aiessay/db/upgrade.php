<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mises à jour de schéma pour qtype_aiessay.
 */
function xmldb_qtype_aiessay_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // 2026052901 : migration vers l'infrastructure partagée local_aifeedback.
    // Les lignes de suivi qui vivaient dans qtype_aiessay_grading sont déplacées
    // vers la table commune local_aifeedback_qgrading (colonne component), puis
    // l'ancienne table est supprimée.
    if ($oldversion < 2026052901) {
        $oldtable    = new xmldb_table('qtype_aiessay_grading');
        $sharedtable = new xmldb_table('local_aifeedback_qgrading');

        // La table partagée est créée par l'upgrade de local_aifeedback, qui
        // tourne avant celui-ci (dépendance déclarée). On migre seulement si
        // les deux conditions sont réunies, pour ne jamais perdre de données.
        if ($dbman->table_exists($oldtable) && $dbman->table_exists($sharedtable)) {
            $rs = $DB->get_recordset('qtype_aiessay_grading');
            foreach ($rs as $old) {
                if ($DB->record_exists('local_aifeedback_qgrading',
                        array('questionattemptid' => $old->questionattemptid))) {
                    continue; // déjà migré
                }
                $new = new \stdClass();
                $new->component         = 'qtype_aiessay';
                $new->questionattemptid = $old->questionattemptid;
                $new->questionid        = $old->questionid;
                $new->userid            = $old->userid;
                $new->status            = $old->status;
                $new->attempts          = $old->attempts;
                $new->aifeedback        = $old->aifeedback;
                $new->error_message     = $old->error_message;
                $new->mark              = $old->mark;
                $new->timecreated       = $old->timecreated;
                $new->timemodified      = $old->timemodified;
                $DB->insert_record('local_aifeedback_qgrading', $new);
            }
            $rs->close();

            $dbman->drop_table($oldtable);
        }

        upgrade_plugin_savepoint(true, 2026052901, 'qtype', 'aiessay');
    }

    return true;
}
