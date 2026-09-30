<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Affichage des activités surveillées, partagé par la page de pilotage
 * (supervised.php), le bloc block_supervised et le service web qui les
 * rafraîchit.
 *
 * Les boutons Ouvrir / Fermer sont de simples formulaires POST vers
 * supervised.php (sesskey, retour à la page d'origine) : ils marchent aussi
 * dans du HTML remplacé par le rafraîchissement.
 */
class supervised_view {

    /** Rafraîchissement du bloc (ms). */
    const BLOCK_REFRESH = 30000;

    /** Rafraîchissement de la page de pilotage (ms). */
    const PAGE_REFRESH = 20000;

    // -------------------------------------------------------------------------
    //  Données
    // -------------------------------------------------------------------------

    /**
     * Activités surveillées d'un cours, dans l'ordre du cours.
     *
     * @return \cm_info[] cmid => cm
     */
    public static function course_cms(int $courseid): array {
        $out = array();
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            if (availability_json::has_root_condition($cm->availability, null, gate::TYPE)) {
                $out[(int)$cm->id] = $cm;
            }
        }
        return $out;
    }

    /**
     * Compteurs d'une activité depuis l'ouverture : travail en cours et
     * travail rendu. Null pour un type d'activité sans ramassage.
     *
     * @return int[]|null ['active' => n, 'done' => n]
     */
    public static function counters(\cm_info $cm, int $since): ?array {
        global $DB;
        if ($cm->modname === 'quiz') {
            $base = 'quiz = :quiz AND preview = 0';
            $params = array('quiz' => (int)$cm->instance);
            return array(
                'active' => $DB->count_records_select('quiz_attempts', $base . ' AND state IN (:s1, :s2)',
                    $params + array('s1' => 'inprogress', 's2' => 'overdue')),
                'done'   => $DB->count_records_select('quiz_attempts',
                    $base . ' AND state = :finished AND timefinish >= :since',
                    $params + array('finished' => 'finished', 'since' => $since)),
            );
        }
        if ($cm->modname === 'assign') {
            $base = 'assignment = :assign AND latest = 1';
            $params = array('assign' => (int)$cm->instance);
            return array(
                'active' => $DB->count_records_select('assign_submission', $base . ' AND status = :draft',
                    $params + array('draft' => 'draft')),
                'done'   => $DB->count_records_select('assign_submission',
                    $base . ' AND status = :submitted AND timemodified >= :since',
                    $params + array('submitted' => 'submitted', 'since' => $since)),
            );
        }
        return null;
    }

    /**
     * Début de la séance en cours (ouverture active la plus ancienne), sinon
     * de la dernière séance : origine des compteurs « rendus ».
     */
    public static function session_start(int $courseid, int $cmid): int {
        global $DB;
        $active = gate::active_for_cm($courseid, $cmid);
        if ($active) {
            return min(array_map(function($o) {
                return (int)$o->timeopened;
            }, $active));
        }
        return (int)$DB->get_field_sql('SELECT MAX(timeopened) FROM {' . gate::TABLE . '} WHERE cmid = :cmid',
            array('cmid' => $cmid));
    }

    /**
     * Noms des groupes et des élèves visés par des ouvertures.
     *
     * @param int         $courseid
     * @param \stdClass[] $openings
     * @return array [groupid => nom, userid => nom complet]
     */
    public static function scope_names(int $courseid, array $openings): array {
        global $CFG;
        $groupnames = array();
        $userids = array();
        foreach ($openings as $o) {
            if ($o->scope === gate::SCOPE_USER) {
                $userids[] = (int)$o->scopeid;
            } else if ($o->scope === gate::SCOPE_GROUP && !$groupnames) {
                foreach (groups_get_all_groups($courseid) as $g) {
                    $groupnames[(int)$g->id] = format_string($g->name);
                }
            }
        }
        $usernames = array();
        if ($userids) {
            require_once($CFG->dirroot . '/user/lib.php');
            foreach (user_get_users_by_id(array_unique($userids)) as $u) {
                $usernames[(int)$u->id] = fullname($u);
            }
        }
        return array($groupnames, $usernames);
    }

    /**
     * Activités surveillées ouvertes pour un élève, dans tous ses cours (bloc
     * du tableau de bord), ou dans un cours.
     *
     * @return \stdClass[] {cm, course, end}
     */
    public static function student_items(int $userid, int $courseid = 0): array {
        $items = array();
        foreach (self::open_courses($userid, $courseid) as $course) {
            $cms = get_fast_modinfo($course, $userid)->get_cms();
            $cmids = array_unique(array_map(function($o) {
                return (int)$o->cmid;
            }, gate::active_for_course((int)$course->id)));
            foreach ($cmids as $cmid) {
                if (!isset($cms[$cmid]) || !$cms[$cmid]->uservisible
                        || !availability_json::has_root_condition($cms[$cmid]->availability, null, gate::TYPE)) {
                    continue;
                }
                $end = gate::end_for((int)$course->id, $cmid, $userid);
                if ($end === null) {
                    continue;
                }
                $items[] = (object)array('cm' => $cms[$cmid], 'course' => $course, 'end' => $end);
            }
        }
        return $items;
    }

    /**
     * Activités surveillées ouvertes dans les cours où l'utilisateur les
     * pilote (bloc du tableau de bord de l'enseignant : ne rien oublier
     * d'ouvert).
     *
     * @return \stdClass[] {cm, course, openings}
     */
    public static function teacher_open_items(int $userid): array {
        $items = array();
        foreach (self::open_courses($userid, 0) as $course) {
            if (!has_capability('local/classhours:supervise', \context_course::instance($course->id), $userid)) {
                continue;
            }
            $cms = get_fast_modinfo($course)->get_cms();
            $bycm = array();
            foreach (gate::active_for_course((int)$course->id) as $o) {
                $bycm[(int)$o->cmid][] = $o;
            }
            foreach ($bycm as $cmid => $openings) {
                if (isset($cms[$cmid])) {
                    $items[] = (object)array('cm' => $cms[$cmid], 'course' => $course, 'openings' => $openings);
                }
            }
        }
        return $items;
    }

    /** L'utilisateur pilote-t-il au moins un de ses cours ? */
    public static function is_teacher_somewhere(int $userid): bool {
        foreach (enrol_get_all_users_courses($userid, true, 'id') as $course) {
            if (has_capability('local/classhours:supervise', \context_course::instance($course->id), $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cours de l'utilisateur (ou le cours donné) qui ont au moins une
     * ouverture active : une requête pour tous ses cours.
     *
     * @return \stdClass[]
     */
    private static function open_courses(int $userid, int $courseid): array {
        global $DB;
        if ($courseid > 0) {
            $courses = array($courseid => get_course($courseid));
        } else {
            $courses = enrol_get_all_users_courses($userid, true, 'id, shortname, fullname');
        }
        if (!$courses) {
            return array();
        }
        list($insql, $params) = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        $params['now'] = schedule::now();
        $withopen = $DB->get_fieldset_select(gate::TABLE, 'DISTINCT courseid',
            "courseid $insql AND timeclosed = 0 AND (closeat = 0 OR closeat > :now)", $params);
        $out = array();
        foreach ($withopen as $id) {
            if (isset($courses[(int)$id])) {
                $out[(int)$id] = $courses[(int)$id];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    //  Morceaux de HTML
    // -------------------------------------------------------------------------

    /**
     * Petit formulaire POST vers supervised.php (sesskey, page de retour).
     *
     * @param int         $courseid
     * @param array       $params    action, cmid, ...
     * @param string      $label
     * @param string      $btnclass  classes Bootstrap du bouton
     * @param \moodle_url $returnurl
     * @param string|null $confirm   message de confirmation (voir js_confirm)
     */
    public static function action_form(int $courseid, array $params, string $label, string $btnclass,
            \moodle_url $returnurl, ?string $confirm = null): string {
        $url = new \moodle_url('/local/classhours/supervised.php');
        $attrs = array('method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline-block m-0');
        if ($confirm !== null) {
            $attrs['data-supervised-confirm'] = $confirm;
        }
        $html = \html_writer::start_tag('form', $attrs);
        $hidden = array('courseid' => $courseid, 'sesskey' => sesskey(), 'returnurl' => $returnurl->out_as_local_url(false))
            + $params;
        foreach ($hidden as $name => $value) {
            $html .= \html_writer::empty_tag('input', array('type' => 'hidden', 'name' => $name, 'value' => $value));
        }
        $html .= \html_writer::tag('button', $label, array('type' => 'submit', 'class' => 'btn ' . $btnclass));
        return $html . \html_writer::end_tag('form');
    }

    /**
     * État d'une activité : pastille Ouverte / Fermée, pour qui, jusqu'à quand,
     * et les boutons Fermer.
     *
     * @param int         $courseid
     * @param int         $cmid
     * @param \moodle_url $returnurl
     * @param bool        $canact    boutons Fermer
     */
    public static function state_html(int $courseid, int $cmid, \moodle_url $returnurl, bool $canact): string {
        $openings = gate::active_for_cm($courseid, $cmid);
        if (!$openings) {
            return \html_writer::span(get_string('supervised_closed', 'local_classhours'),
                'badge bg-secondary text-white fs-6');
        }
        list($groupnames, $usernames) = self::scope_names($courseid, $openings);
        $confirm = get_string('supervised_confirm_close', 'local_classhours');
        $html = \html_writer::span(get_string('supervised_open', 'local_classhours'), 'badge bg-success text-white fs-6');
        $html .= \html_writer::start_tag('ul', array('class' => 'list-unstyled mb-0 mt-2'));
        foreach ($openings as $o) {
            $line = s(gate::scope_label($o, $groupnames, $usernames));
            $line .= ' — ' . ((int)$o->closeat > 0
                ? get_string('supervised_until', 'local_classhours', gate::format_end((int)$o->closeat))
                : get_string('supervised_untilclosed', 'local_classhours'));
            if ($canact && count($openings) > 1) {
                $line .= ' ' . self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid,
                    'openingid' => (int)$o->id), get_string('supervised_close_one', 'local_classhours'),
                    'btn-sm btn-outline-danger ms-2', $returnurl, $confirm);
            }
            $html .= \html_writer::tag('li', $line, array('class' => 'mb-1'));
        }
        $html .= \html_writer::end_tag('ul');
        if ($canact) {
            $html .= self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid),
                get_string('supervised_close', 'local_classhours'), 'btn-danger mt-1', $returnurl, $confirm);
        }
        return $html;
    }

    /** Compteurs d'une activité, en clair. */
    public static function counters_html(\cm_info $cm, int $courseid): string {
        $counters = self::counters($cm, self::session_start($courseid, (int)$cm->id));
        if ($counters === null) {
            return '';
        }
        $key = $cm->modname === 'quiz' ? 'supervised_counters_quiz' : 'supervised_counters_assign';
        return \html_writer::span(get_string($key, 'local_classhours', (object)$counters), 'text-muted');
    }

    /** Nom de l'activité avec son icône, en lien. */
    public static function cm_link(\cm_info $cm): string {
        $name = \html_writer::img($cm->get_icon_url(), '', array('class' => 'icon')) . ' ' . $cm->get_formatted_name();
        return $cm->url ? \html_writer::link($cm->url, $name) : $name;
    }

    // -------------------------------------------------------------------------
    //  Bloc
    // -------------------------------------------------------------------------

    /**
     * Contenu du bloc.
     *
     * Page du cours : l'enseignant y ouvre et ferme les activités du cours en
     * un clic ; l'élève y voit celles qui sont ouvertes pour lui.
     * Tableau de bord : l'élève voit ses activités ouvertes dans tous ses
     * cours, l'enseignant celles qu'il a laissées ouvertes.
     *
     * @param int $courseid cours de la page, 0 ou SITEID sur le tableau de bord
     * @param int $userid
     */
    public static function block_html(int $courseid, int $userid): string {
        if ($courseid > SITEID
                && has_capability('local/classhours:supervise', \context_course::instance($courseid), $userid)) {
            return self::block_course_teacher($courseid);
        }
        $html = '';
        if ($courseid <= SITEID) {
            $teacher = self::teacher_open_items($userid);
            if ($teacher) {
                $html .= self::block_teacher_open($teacher);
            }
        }
        $html .= self::block_student($courseid > SITEID ? $courseid : 0, $userid, $html !== '');
        return $html;
    }

    /** Élève : ses activités ouvertes, avec « Commencer ». */
    private static function block_student(int $courseid, int $userid, bool $quietwhenempty): string {
        $items = self::student_items($userid, $courseid);
        if (!$items) {
            return $quietwhenempty ? '' : \html_writer::tag('p', get_string('block_none', 'local_classhours'),
                array('class' => 'text-muted mb-0'));
        }
        $html = '';
        foreach ($items as $item) {
            $body = \html_writer::div(self::cm_link($item->cm), 'fw-bold');
            if ($courseid === 0) {
                $body .= \html_writer::div(format_string($item->course->shortname), 'small text-muted');
            }
            $body .= \html_writer::div($item->end > 0
                ? get_string('supervised_until', 'local_classhours', gate::format_end($item->end))
                : get_string('block_opennow', 'local_classhours'), 'small');
            if ($item->cm->url) {
                $body .= \html_writer::link($item->cm->url, get_string('block_start', 'local_classhours'),
                    array('class' => 'btn btn-primary btn-sm mt-2'));
            }
            $html .= \html_writer::div($body, 'border border-success rounded p-2 mb-2',
                array('data-supervised-item' => (int)$item->cm->id));
        }
        return $html;
    }

    /** Enseignant, tableau de bord : les activités qu'il a laissées ouvertes. */
    private static function block_teacher_open(array $items): string {
        $html = \html_writer::tag('h6', get_string('block_teacher_open', 'local_classhours'));
        $returnurl = new \moodle_url('/my/');
        $confirm = get_string('supervised_confirm_close', 'local_classhours');
        foreach ($items as $item) {
            $courseid = (int)$item->course->id;
            $ends = array_map(function($o) {
                return (int)$o->closeat;
            }, $item->openings);
            $body = \html_writer::div(self::cm_link($item->cm), 'fw-bold')
                . \html_writer::div(format_string($item->course->shortname) . ' — '
                    . (in_array(0, $ends, true) ? get_string('supervised_untilclosed', 'local_classhours')
                        : get_string('supervised_until', 'local_classhours', gate::format_end(max($ends)))),
                    'small text-muted')
                . \html_writer::div(
                    self::action_form($courseid, array('action' => 'close', 'cmid' => (int)$item->cm->id),
                        get_string('supervised_close', 'local_classhours'), 'btn-sm btn-danger', $returnurl, $confirm)
                    . ' ' . \html_writer::link(new \moodle_url('/local/classhours/supervised.php',
                        array('courseid' => $courseid)), get_string('block_manage', 'local_classhours'),
                        array('class' => 'btn btn-sm btn-outline-secondary')),
                    'mt-1');
            $html .= \html_writer::div($body, 'border border-warning rounded p-2 mb-2');
        }
        return $html;
    }

    /** Enseignant, page du cours : chaque activité surveillée, Ouvrir / Fermer en un clic. */
    private static function block_course_teacher(int $courseid): string {
        $cms = self::course_cms($courseid);
        $manageurl = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
        if (!$cms) {
            return \html_writer::tag('p', get_string('block_nosupervised', 'local_classhours'), array('class' => 'text-muted'))
                . \html_writer::link($manageurl, get_string('block_manage', 'local_classhours'));
        }
        $returnurl = new \moodle_url('/course/view.php', array('id' => $courseid));
        $confirm = get_string('supervised_confirm_close', 'local_classhours');
        $html = '';
        foreach ($cms as $cmid => $cm) {
            $openings = gate::active_for_cm($courseid, $cmid);
            $body = \html_writer::div(self::cm_link($cm), 'fw-bold');
            if ($openings) {
                $ends = array_map(function($o) {
                    return (int)$o->closeat;
                }, $openings);
                $body .= \html_writer::div(\html_writer::span(get_string('supervised_open', 'local_classhours'),
                        'badge bg-success text-white') . ' '
                    . (in_array(0, $ends, true) ? get_string('supervised_untilclosed', 'local_classhours')
                        : get_string('supervised_until', 'local_classhours', gate::format_end(max($ends)))), 'small')
                    . \html_writer::div(self::counters_html($cm, $courseid), 'small');
                $button = self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid),
                    get_string('supervised_close', 'local_classhours'), 'btn-sm btn-danger', $returnurl, $confirm);
            } else {
                $body .= \html_writer::div(\html_writer::span(get_string('supervised_closed', 'local_classhours'),
                    'badge bg-secondary text-white'), 'small');
                $button = self::action_form($courseid, array('action' => 'open', 'cmid' => $cmid,
                    'scope' => gate::SCOPE_COURSE, 'duration' => gate::MANUAL),
                    get_string('supervised_open_button', 'local_classhours'), 'btn-sm btn-success', $returnurl);
            }
            $html .= \html_writer::div($body . \html_writer::div($button, 'mt-1'), 'border rounded p-2 mb-2');
        }
        return $html . \html_writer::link($manageurl, get_string('block_moreoptions', 'local_classhours'));
    }

    // -------------------------------------------------------------------------
    //  JavaScript (en ligne : pas de chaîne de compilation AMD dans ce dépôt)
    // -------------------------------------------------------------------------

    /**
     * Confirmation des formulaires marqués data-supervised-confirm, y compris
     * dans du HTML remplacé par le rafraîchissement (écouteur délégué).
     */
    public static function js_confirm(): void {
        global $PAGE;
        $PAGE->requires->js_amd_inline("
            if (!window.localClasshoursConfirm) {
                window.localClasshoursConfirm = true;
                document.addEventListener('submit', function(e) {
                    var form = e.target;
                    var message = form && form.getAttribute && form.getAttribute('data-supervised-confirm');
                    if (message && !window.confirm(message)) {
                        e.preventDefault();
                    }
                }, true);
            }");
    }

    /**
     * Rafraîchit le contenu du bloc toutes les 30 s par le service web ; une
     * activité qui vient d'ouvrir est mise en évidence.
     *
     * @param string $rootid id de l'élément qui contient le contenu du bloc
     * @param int    $courseid
     */
    public static function js_block_refresh(string $rootid, int $courseid): void {
        global $PAGE;
        $PAGE->requires->js_amd_inline("
            require(['core/ajax'], function(Ajax) {
                var root = document.getElementById(" . json_encode($rootid) . ");
                if (!root) { return; }
                var tick = function() {
                    if (document.hidden) { return; }
                    Ajax.call([{methodname: 'local_classhours_supervised_refresh',
                        args: {courseid: " . (int)$courseid . ", view: 'block'}}])[0].then(function(r) {
                        if (r.signature === root.getAttribute('data-signature')) { return; }
                        var before = {};
                        root.querySelectorAll('[data-supervised-item]').forEach(function(n) {
                            before[n.getAttribute('data-supervised-item')] = true;
                        });
                        root.innerHTML = r.html;
                        root.setAttribute('data-signature', r.signature);
                        root.querySelectorAll('[data-supervised-item]').forEach(function(n) {
                            if (!before[n.getAttribute('data-supervised-item')]) {
                                n.classList.add('bg-success', 'bg-opacity-10');
                            }
                        });
                    }).catch(function() {});
                };
                setInterval(tick, " . self::BLOCK_REFRESH . ");
            });");
    }

    /**
     * Page de pilotage : rafraîchit l'état et les compteurs de chaque activité
     * toutes les 20 s, sans toucher aux menus d'ouverture.
     */
    public static function js_page_refresh(int $courseid): void {
        global $PAGE;
        $PAGE->requires->js_amd_inline("
            require(['core/ajax'], function(Ajax) {
                var tick = function() {
                    if (document.hidden) { return; }
                    Ajax.call([{methodname: 'local_classhours_supervised_refresh',
                        args: {courseid: " . (int)$courseid . ", view: 'page'}}])[0].then(function(r) {
                        r.items.forEach(function(item) {
                            var state = document.querySelector('[data-supervised-state=\"' + item.cmid + '\"]');
                            if (state && state.getAttribute('data-signature') !== item.signature) {
                                state.innerHTML = item.statehtml;
                                state.setAttribute('data-signature', item.signature);
                            }
                            var counters = document.querySelector('[data-supervised-counters=\"' + item.cmid + '\"]');
                            if (counters) { counters.innerHTML = item.countershtml; }
                        });
                    }).catch(function() {});
                };
                setInterval(tick, " . self::PAGE_REFRESH . ");
            });");
    }
}
