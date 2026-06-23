<?php
namespace local_aimissions;

defined('MOODLE_INTERNAL') || die();

/**
 * Réinjecte les PRÉCISIONS données via le fil client (réponses de tickets +
 * événements publiés) dans la configuration de correction IA du devoir, pour
 * que la correction du livrable en tienne compte (ex. « le client voulait du
 * bleu » → on vérifie que c'est respecté).
 *
 * Découplé de assignfeedback_ai : on se contente de recomposer son champ
 * `expectedanswer` à partir de la grille de base (mission.rubric, jamais
 * modifiée) + un bloc de précisions, à chaque évolution du fil.
 */
class correction_sync {

    /**
     * Resynchronise le devoir d'une mission avec les précisions client.
     */
    public static function sync_for_mission(int $missionid): void {
        global $DB;

        if ($missionid <= 0) {
            return;
        }
        $mission = $DB->get_record(job_handler::TABLE_MISSION, array('id' => $missionid));
        if (!$mission || (int)$mission->assigncmid <= 0) {
            return;
        }
        if (!$DB->get_manager()->table_exists('assignfeedback_ai')) {
            return; // plugin de correction absent
        }
        $cm = $DB->get_record('course_modules', array('id' => (int)$mission->assigncmid), 'id, instance');
        if (!$cm) {
            return;
        }
        $cfg = $DB->get_record('assignfeedback_ai', array('assignment' => (int)$cm->instance));
        if (!$cfg) {
            return;
        }

        // expectedanswer = grille de base (pristine) + précisions courantes.
        $expected = (string)$mission->rubric;
        $block = self::build_clarifications_block((int)$mission->projectid, $missionid);
        if ($block !== '') {
            $expected .= "\n\n" . $block;
        }

        $DB->set_field('assignfeedback_ai', 'expectedanswer', $expected, array('id' => $cfg->id));
        $DB->set_field('assignfeedback_ai', 'timemodified', time(), array('id' => $cfg->id));
    }

    /**
     * Construit le bloc « précisions » à partir des tickets répondus et des
     * événements publiés rattachés à cette mission.
     */
    private static function build_clarifications_block(int $projectid, int $missionid): string {
        global $DB;

        $lines = array();

        $tickets = $DB->get_records_select('local_aimissions_ticket',
            'projectid = ? AND missionid = ? AND status = ?',
            array($projectid, $missionid, 'answered'), 'timecreated ASC');
        foreach ($tickets as $t) {
            $q = trim((string)$t->question);
            $a = trim((string)$t->answer);
            if ($q !== '' && $a !== '') {
                $lines[] = '- Question des étudiants : ' . $q . "\n  Réponse du client : " . $a;
            }
        }

        $events = $DB->get_records_select('local_aimissions_event',
            'projectid = ? AND missionid = ? AND applied = 1',
            array($projectid, $missionid), 'timecreated ASC');
        foreach ($events as $ev) {
            $b = trim((string)$ev->body);
            if ($b !== '') {
                $lines[] = '- Nouvelle consigne du client : ' . $b;
            }
        }

        if (empty($lines)) {
            return '';
        }
        return "PRÉCISIONS COMMUNIQUÉES AU CLIENT EN COURS DE PROJET "
             . "(à prendre en compte dans l'évaluation du livrable) :\n"
             . implode("\n", $lines);
    }
}
