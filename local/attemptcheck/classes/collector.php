<?php
namespace local_attemptcheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Collecte, pour une activité, les « items » analysés : tentatives de test
 * (hors aperçu) et remises de devoir (dernière remise « remise » de chaque
 * élève ; les remises d'équipe ne sont pas prises en charge).
 *
 * Item : type, id, key, cmid, courseid, userid, attempt, state, timestart,
 * timeend, duration, isref, questions, bursts, restricted.
 *
 * Mesures :
 *   - test : durée = fin − début. Pour chaque question rédigée, temps de
 *     réponse et « sauts » de texte d'après les enregistrements de la réponse
 *     (question_attempt_steps). Moodle ne garde qu'un enregistrement
 *     automatique à la fois : la chronologie est celle des enregistrements de
 *     page. L'arrivée sur la question est affinée par le journal (affichage de
 *     la page) quand il existe.
 *   - devoir : durée = premier accès à l'activité → remise (journal ; repli
 *     sur la création de la remise). Texte en ligne : nombre de mots à chaque
 *     enregistrement (journal), sinon mots totaux sur la durée d'édition.
 */
class collector {

    /** Types de question rédigés par défaut. */
    const DEFAULT_TEXTQTYPES = 'aiessay,aishortanswer,essay,shortanswer';

    /**
     * Tests et devoirs du cours, dans l'ordre du cours.
     *
     * @return \cm_info[]
     */
    public static function activities(\stdClass $course): array {
        $cms = array();
        foreach (get_fast_modinfo($course)->get_cms() as $cm) {
            if (in_array($cm->modname, array('quiz', 'assign'), true) && empty($cm->deletioninprogress)) {
                $cms[(int)$cm->id] = $cm;
            }
        }
        return $cms;
    }

    /**
     * Items d'une activité.
     *
     * @return \stdClass[]
     */
    public static function collect(\cm_info $cm): array {
        if ($cm->modname === 'quiz') {
            return self::collect_quiz($cm);
        }
        if ($cm->modname === 'assign') {
            return self::collect_assign($cm);
        }
        return array();
    }

    /** @return string[] types de question dont on analyse la rédaction */
    public static function textqtypes(): array {
        $value = get_config('local_attemptcheck', 'textqtypes');
        if ($value === false) {
            $value = self::DEFAULT_TEXTQTYPES;
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
    }

    /** Nombre de mots d'une réponse (texte brut ou HTML de l'éditeur). */
    public static function words(?string $text): int {
        if ($text === null || trim($text) === '') {
            return 0;
        }
        $html = (bool)preg_match('~<\s*/?\s*(p|br|div|span|ol|ul|li|strong|em|b|i|h[1-6]|pre|code|table)\b~i', $text);
        return (int)count_words($text, $html ? FORMAT_HTML : null);
    }

    /**
     * Page (à partir de 0) d'un emplacement de question, d'après la mise en
     * page de la tentative (« 1,2,0,3,0 » : 0 = saut de page).
     */
    public static function page_of_slot(string $layout, int $slot): ?int {
        $page = 0;
        foreach (explode(',', $layout) as $entry) {
            $entry = (int)$entry;
            if ($entry === 0) {
                $page++;
            } else if ($entry === $slot) {
                return $page;
            }
        }
        return null;
    }

    private static function new_item(string $type, int $id, \cm_info $cm, int $userid): \stdClass {
        return (object)array(
            'type'       => $type,
            'id'         => $id,
            'key'        => $type . ':' . $id,
            'cmid'       => (int)$cm->id,
            'courseid'   => (int)$cm->course,
            'userid'     => $userid,
            'attempt'    => 1,
            'state'      => '',
            'timestart'  => 0,
            'timeend'    => 0,
            'duration'   => null,
            'isref'      => false,
            'questions'  => array(),
            'bursts'     => array(),
            'restricted' => !empty($cm->availability) && strpos($cm->availability, '"classhours"') !== false,
        );
    }

    // -------------------------------------------------------------------------
    //  Tests
    // -------------------------------------------------------------------------

    private static function collect_quiz(\cm_info $cm): array {
        global $DB;

        $attempts = $DB->get_records('quiz_attempts', array('quiz' => $cm->instance, 'preview' => 0),
            'userid, attempt', 'id, uniqueid, userid, attempt, state, timestart, timefinish, layout');
        if (!$attempts) {
            return array();
        }

        $items = array();          // uniqueid => item
        $byattempt = array();      // attemptid => attempt
        $refdone = array();
        foreach ($attempts as $a) {
            $item = self::new_item('quiz', (int)$a->id, $cm, (int)$a->userid);
            $item->attempt = (int)$a->attempt;
            $item->state = (string)$a->state;
            $item->timestart = (int)$a->timestart;
            $item->timeend = (int)$a->timefinish;
            if ($a->state === 'finished' && $item->timeend > $item->timestart && $item->timestart > 0) {
                $item->duration = $item->timeend - $item->timestart;
                if (!isset($refdone[$item->userid])) {
                    $refdone[$item->userid] = true;
                    $item->isref = true;
                }
            }
            $items[(int)$a->uniqueid] = $item;
            $byattempt[(int)$a->id] = $a;
        }

        $qtypes = self::textqtypes();
        if (!$qtypes) {
            return array_values($items);
        }
        list($usql, $uparams) = $DB->get_in_or_equal(array_keys($items), SQL_PARAMS_NAMED, 'u');
        list($tsql, $tparams) = $DB->get_in_or_equal($qtypes, SQL_PARAMS_NAMED, 't');
        $qas = $DB->get_records_sql("SELECT qa.id, qa.questionusageid, qa.slot, qa.questionid
                                       FROM {question_attempts} qa
                                       JOIN {question} q ON q.id = qa.questionid
                                      WHERE qa.questionusageid $usql AND q.qtype $tsql",
            $uparams + $tparams);
        if (!$qas) {
            return array_values($items);
        }

        // Tous les enregistrements de chaque tentative : l'élève arrive sur une
        // question après l'enregistrement de la page précédente.
        $moments = array();
        $rs = $DB->get_recordset_sql("SELECT DISTINCT qa.questionusageid, s.timecreated
                                        FROM {question_attempt_steps} s
                                        JOIN {question_attempts} qa ON qa.id = s.questionattemptid
                                       WHERE qa.questionusageid $usql", $uparams);
        foreach ($rs as $row) {
            $moments[(int)$row->questionusageid][] = (int)$row->timecreated;
        }
        $rs->close();

        // Réponses successives des questions rédigées.
        $points = array();
        list($qsql, $qparams) = $DB->get_in_or_equal(array_keys($qas), SQL_PARAMS_NAMED, 'qa');
        $rs = $DB->get_recordset_sql("SELECT s.id, s.questionattemptid, s.timecreated, d.value AS answer
                                        FROM {question_attempt_steps} s
                                        JOIN {question_attempt_step_data} d ON d.attemptstepid = s.id AND d.name = :name
                                       WHERE s.questionattemptid $qsql
                                    ORDER BY s.questionattemptid, s.sequencenumber", $qparams + array('name' => 'answer'));
        foreach ($rs as $row) {
            $points[(int)$row->questionattemptid][] = array(
                (int)$row->timecreated, self::words($row->answer), sha1((string)$row->answer));
        }
        $rs->close();

        // Affichages des pages (journal) : arrivée précise sur une page.
        $views = array();
        foreach (logs::events((int)$cm->id, array('\mod_quiz\event\attempt_viewed'), array_keys($byattempt)) as $event) {
            if (isset($event->other['page'])) {
                $views[(int)$event->objectid][(int)$event->other['page']][] = (int)$event->timecreated;
            }
        }

        foreach ($qas as $qa) {
            $usage = (int)$qa->questionusageid;
            $item = $items[$usage];
            $pts = $points[(int)$qa->id] ?? array();
            $first = null;
            foreach ($pts as $p) {
                if ($p[1] > 0) {
                    $first = $p[0];
                    break;
                }
            }
            if ($first === null) {
                continue; // question laissée sans réponse
            }
            $page = self::page_of_slot((string)$byattempt[$item->id]->layout, (int)$qa->slot);
            $candidates = $moments[$usage] ?? array();
            if ($page !== null && !empty($views[$item->id][$page])) {
                $candidates = array_merge($candidates, $views[$item->id][$page]);
            }
            $arrival = analyser::arrival($first, $candidates, $item->timestart);
            $metrics = analyser::text_metrics($arrival, $pts);
            $item->questions[] = (object)array(
                'questionid'   => (int)$qa->questionid,
                'number'       => (int)$qa->slot,
                'responsetime' => $metrics['responsetime'],
                'words'        => $metrics['words'],
            );
            foreach ($metrics['bursts'] as $burst) {
                $item->bursts[] = (object)($burst + array('number' => (int)$qa->slot));
            }
        }
        return array_values($items);
    }

    // -------------------------------------------------------------------------
    //  Devoirs
    // -------------------------------------------------------------------------

    private static function collect_assign(\cm_info $cm): array {
        global $DB;

        $submissions = $DB->get_records_select('assign_submission',
            'assignment = :assignment AND latest = 1 AND status = :status AND userid > 0',
            array('assignment' => $cm->instance, 'status' => 'submitted'), 'userid',
            'id, userid, attemptnumber, timecreated, timemodified');
        if (!$submissions) {
            return array();
        }

        $cmid = (int)$cm->id;
        $firstview = logs::per_user($cmid, '\mod_assign\event\course_module_viewed', 'MIN');
        $submitted = logs::per_user($cmid, '\mod_assign\event\assessable_submitted', 'MAX');

        // Ouvertures du formulaire de remise et enregistrements du texte en ligne.
        $formopens = array();
        $saves = array();
        foreach (logs::events($cmid, array(
                '\mod_assign\event\submission_form_viewed',
                '\assignsubmission_onlinetext\event\submission_created',
                '\assignsubmission_onlinetext\event\submission_updated')) as $event) {
            if ($event->eventname === '\mod_assign\event\submission_form_viewed') {
                $formopens[(int)$event->userid][] = (int)$event->timecreated;
            } else if (isset($event->other['onlinetextwordcount'])) {
                $words = (int)$event->other['onlinetextwordcount'];
                $saves[(int)$event->userid][] = array((int)$event->timecreated, $words, (string)$words);
            }
        }

        // Repli sans journal : texte final.
        $onlinetext = array();
        if (logs::table() === null && $DB->get_manager()->table_exists('assignsubmission_onlinetext')) {
            list($ssql, $sparams) = $DB->get_in_or_equal(array_keys($submissions), SQL_PARAMS_NAMED, 's');
            $onlinetext = $DB->get_records_sql_menu("SELECT submission, onlinetext
                                                       FROM {assignsubmission_onlinetext}
                                                      WHERE submission $ssql", $sparams);
        }

        $items = array();
        foreach ($submissions as $s) {
            $userid = (int)$s->userid;
            $created = (int)$s->timecreated;
            $item = self::new_item('assign', (int)$s->id, $cm, $userid);
            $item->attempt = (int)$s->attemptnumber + 1;
            $item->state = 'submitted';

            // Premier accès : première consultation de l'activité (1re tentative
            // seulement ; une tentative rouverte commence à sa réouverture).
            $start = $created;
            if ((int)$s->attemptnumber === 0 && !empty($firstview[$userid])) {
                $start = min($start, $firstview[$userid]);
            }
            $end = (int)($submitted[$userid] ?? 0);
            if ($end < $created) {
                $end = (int)$s->timemodified;
            }
            $item->timestart = $start;
            $item->timeend = $end;
            if ($end > $start && $start > 0) {
                $item->duration = $end - $start;
                $item->isref = true;
            }

            // Rédaction : à partir de la première ouverture du formulaire.
            $open = $created;
            foreach ($formopens[$userid] ?? array() as $t) {
                if ($t >= $created - MINSECS) {
                    $open = min($open, $t);
                    break;
                }
            }
            $pts = array_values(array_filter($saves[$userid] ?? array(), function($p) use ($created) {
                return $p[0] >= $created - MINSECS;
            }));
            if ($pts) {
                $item->bursts = array_map(function($b) {
                    return (object)($b + array('number' => null));
                }, analyser::text_metrics($open, $pts)['bursts']);
            } else if (isset($onlinetext[$s->id])) {
                $item->bursts = array((object)array(
                    'words'   => self::words($onlinetext[$s->id]),
                    'seconds' => max(0, $end - $open),
                    'number'  => null,
                ));
            }
            $items[] = $item;
        }
        return $items;
    }
}
