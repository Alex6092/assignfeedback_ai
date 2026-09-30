<?php
namespace local_classhours;

defined('MOODLE_INTERNAL') || die();

/**
 * Affichage des activités surveillées, partagé par la page de pilotage
 * (supervised.php), le bloc block_supervised et le service web qui les
 * rafraîchit.
 *
 * Élève : une FRISE par cours, chronologique, façon carrousel des QCM vidéo :
 * activités faites (grisées), à rattraper (rouge, avec « Demander un
 * rattrapage »), ouverte (verte, « Commencer » ou « Saisir le code »), à
 * venir (date prévue, la prochaine en jaune).
 *
 * Enseignant : ouvrir / fermer en un clic sur la page du cours ; sur le
 * tableau de bord, ce qui est prévu aujourd'hui, ce qui est resté ouvert et
 * les demandes de rattrapage.
 *
 * Les boutons sont de simples formulaires POST vers supervised.php (sesskey,
 * retour à la page d'origine) : ils marchent aussi dans du HTML remplacé par
 * le rafraîchissement.
 */
class supervised_view {

    /** Rafraîchissement du bloc (ms). */
    const BLOCK_REFRESH = 30000;

    /** Rafraîchissement de la page de pilotage (ms). */
    const PAGE_REFRESH = 20000;

    // États d'une carte de la frise.
    const DONE     = 'done';
    const DUE      = 'due';
    const PAST     = 'past';
    const OPEN     = 'open';
    const UPCOMING = 'upcoming';

    // -------------------------------------------------------------------------
    //  Données
    // -------------------------------------------------------------------------

    /**
     * Activités surveillées d'un cours, dans l'ordre du cours.
     *
     * @return \cm_info[] cmid => cm
     */
    public static function course_cms(int $courseid, int $userid = 0): array {
        $out = array();
        foreach (get_fast_modinfo($courseid, $userid)->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            if (gate::is_supervised_json($cm->availability)) {
                $out[(int)$cm->id] = $cm;
            }
        }
        return $out;
    }

    /**
     * Activités surveillées d'un cours, par date prévue (pour tout le cours),
     * celles sans date ensuite, dans l'ordre du cours.
     *
     * @return \cm_info[] cmid => cm
     */
    public static function planned_cms(int $courseid): array {
        $cms = self::course_cms($courseid);
        $dates = plan::dates_for_course($courseid);
        $order = array_flip(array_keys($cms));
        uksort($cms, function($a, $b) use ($dates, $order) {
            $da = self::first_date($dates[$a] ?? array());
            $db = self::first_date($dates[$b] ?? array());
            if ($da !== $db) {
                return ($da ?: PHP_INT_MAX) <=> ($db ?: PHP_INT_MAX);
            }
            return $order[$a] <=> $order[$b];
        });
        return $cms;
    }

    /** Date prévue la plus proche d'une activité, tous groupes confondus (0 : aucune). */
    private static function first_date(array $dates): int {
        $dates = array_filter(array_map('intval', $dates));
        return $dates ? min($dates) : 0;
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
     * Frise d'un élève dans un cours : chaque activité surveillée qu'il voit,
     * avec son état et sa date, dans l'ordre chronologique.
     *
     * @return \stdClass[] {cm, state, time (date de la carte, 0 : à venir sans date), end, needscode,
     *                     requested, isnext, current}
     */
    public static function timeline(int $courseid, int $userid): array {
        $now = schedule::now();
        $held = catchup::sessions_held($courseid, $userid, $now);
        $pending = catchup::pending_for($courseid, $userid);
        $groupids = gate::user_groupids($courseid, $userid);
        $items = array();
        $position = 0;
        foreach (self::course_cms($courseid, $userid) as $cmid => $cm) {
            $position++;
            if (!$cm->visible || !$cm->is_visible_on_course_page()) {
                continue;
            }
            $item = (object)array('cm' => $cm, 'state' => self::UPCOMING, 'time' => 0, 'end' => null,
                'needscode' => false, 'requested' => isset($pending[$cmid]), 'isnext' => false, 'current' => false,
                'position' => $position);
            if (gate::in_window($courseid, $cmid, $userid, $now)) {
                $item->state = self::OPEN;
                $item->time = $now;
                $item->end = gate::end_for($courseid, $cmid, $userid, $now);
                $item->needscode = gate::needs_code($courseid, $cmid, $userid, $now);
            } else if (catchup::is_done($courseid, $cm, $userid) === true) {
                $item->state = self::DONE;
                $item->time = $held[$cmid] ?? plan::date_for($courseid, $cmid, $groupids);
            } else if (isset($held[$cmid])) {
                $item->state = catchup::is_done($courseid, $cm, $userid) === false ? self::DUE : self::PAST;
                $item->time = $held[$cmid];
            } else {
                $item->time = plan::date_for($courseid, $cmid, $groupids);
            }
            $items[] = $item;
        }
        // Chronologique ; les activités à venir sans date à la fin, dans l'ordre du cours.
        usort($items, function($a, $b) {
            $ta = $a->time ?: PHP_INT_MAX;
            $tb = $b->time ?: PHP_INT_MAX;
            return $ta <=> $tb ?: $a->position <=> $b->position;
        });
        $current = null;
        foreach ($items as $item) {
            if ($item->state === self::OPEN) {
                $current = $current ?? $item;
            }
        }
        foreach ($items as $item) {
            if ($item->state === self::UPCOMING) {
                $item->isnext = true;
                $current = $current ?? $item;
                break;
            }
        }
        if ($current) {
            $current->current = true;
        }
        return $items;
    }

    /**
     * Cours de l'utilisateur qui ont des activités surveillées : une requête
     * pour tous ses cours.
     *
     * @return \stdClass[] courseid => cours
     */
    public static function supervised_courses(int $userid): array {
        global $DB;
        $courses = enrol_get_all_users_courses($userid, true, 'id, shortname, fullname');
        if (!$courses) {
            return array();
        }
        list($insql, $params) = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        $params['pattern'] = '%"' . gate::TYPE . '"%';
        $ids = $DB->get_fieldset_sql('SELECT DISTINCT course FROM {course_modules}
                                       WHERE course ' . $insql . ' AND deletioninprogress = 0
                                         AND ' . $DB->sql_like('availability', ':pattern'), $params);
        $out = array();
        foreach ($courses as $id => $course) {
            if (in_array($id, array_map('intval', $ids), true)) {
                $out[(int)$id] = $course;
            }
        }
        return $out;
    }

    /** L'utilisateur pilote-t-il les activités surveillées de ce cours ? */
    public static function is_teacher(int $courseid, int $userid): bool {
        return has_capability('local/classhours:supervise', \context_course::instance($courseid), $userid);
    }

    /**
     * Enseignant, tableau de bord : pour chacun de ses cours, les activités
     * ouvertes, celles prévues aujourd'hui et les demandes de rattrapage.
     *
     * @return \stdClass[] {course, open: [cm => openings], today: cm_info[], requests: int}
     */
    public static function teacher_overview(array $courses): array {
        $now = schedule::now();
        $out = array();
        foreach ($courses as $course) {
            $courseid = (int)$course->id;
            $cms = self::course_cms($courseid);
            $open = array();
            foreach (gate::active_for_course($courseid, $now) as $o) {
                if (isset($cms[(int)$o->cmid])) {
                    $open[(int)$o->cmid][] = $o;
                }
            }
            $today = array();
            foreach (plan::dates_for_course($courseid) as $cmid => $dates) {
                if (!isset($cms[$cmid]) || isset($open[$cmid])) {
                    continue;
                }
                foreach ($dates as $time) {
                    if (self::same_day((int)$time, $now)) {
                        $today[$cmid] = $cms[$cmid];
                        break;
                    }
                }
            }
            $requests = 0;
            foreach (catchup::pending_for_course($courseid) as $cmid => $users) {
                if (isset($cms[$cmid])) {
                    $requests += count($users);
                }
            }
            if ($open || $today || $requests) {
                $out[] = (object)array('course' => $course, 'cms' => $cms, 'open' => $open, 'today' => $today,
                    'requests' => $requests);
            }
        }
        return $out;
    }

    private static function same_day(int $a, int $b): bool {
        return $a > 0 && userdate($a, '%Y%m%d') === userdate($b, '%Y%m%d');
    }

    /** Date d'une carte : « Aujourd'hui, 10:00 » ou date courte. */
    public static function format_date(int $t): string {
        if (self::same_day($t, schedule::now())) {
            return get_string('frise_today', 'local_classhours', userdate($t, get_string('strftimetime', 'langconfig')));
        }
        return userdate($t, get_string('strftimedatetimeshort', 'langconfig'));
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
        return self::post_form($url, array('courseid' => $courseid) + $params, $label, $btnclass, $returnurl, $confirm);
    }

    /** Formulaire POST d'un seul bouton (sesskey, page de retour). */
    private static function post_form(\moodle_url $url, array $params, string $label, string $btnclass,
            \moodle_url $returnurl, ?string $confirm = null): string {
        $attrs = array('method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline-block m-0');
        if ($confirm !== null) {
            $attrs['data-supervised-confirm'] = $confirm;
        }
        $html = \html_writer::start_tag('form', $attrs);
        $hidden = array('sesskey' => sesskey(), 'returnurl' => $returnurl->out_as_local_url(false)) + $params;
        foreach ($hidden as $name => $value) {
            $html .= \html_writer::empty_tag('input', array('type' => 'hidden', 'name' => $name, 'value' => $value));
        }
        $html .= \html_writer::tag('button', $label, array('type' => 'submit', 'class' => 'btn ' . $btnclass));
        return $html . \html_writer::end_tag('form');
    }

    /**
     * État d'une activité : pastille Ouverte / Fermée, pour qui, jusqu'à quand,
     * tiers-temps en cours, code de séance, et les boutons Fermer.
     *
     * @param int         $courseid
     * @param int         $cmid
     * @param \moodle_url $returnurl
     * @param bool        $canact    boutons Fermer
     */
    public static function state_html(int $courseid, int $cmid, \moodle_url $returnurl, bool $canact): string {
        $now = schedule::now();
        $openings = gate::active_for_cm($courseid, $cmid, $now);
        if (!$openings) {
            return \html_writer::span(get_string('supervised_closed', 'local_classhours'),
                'badge bg-secondary text-white fs-6');
        }
        list($groupnames, $usernames) = self::scope_names($courseid, $openings);
        $confirm = get_string('supervised_confirm_close', 'local_classhours');
        $allextra = true;
        $anyextra = false;
        foreach ($openings as $o) {
            $extra = gate::in_extratime($o, $now);
            $allextra = $allextra && $extra;
            $anyextra = $anyextra || $extra;
        }
        $html = $allextra
            ? \html_writer::span(get_string('supervised_extratime', 'local_classhours'), 'badge bg-warning text-dark fs-6')
            : \html_writer::span(get_string('supervised_open', 'local_classhours'), 'badge bg-success text-white fs-6');
        $html .= \html_writer::start_tag('ul', array('class' => 'list-unstyled mb-0 mt-2'));
        foreach ($openings as $o) {
            $line = s(gate::scope_label($o, $groupnames, $usernames)) . ' — ';
            if (gate::in_extratime($o, $now)) {
                $line .= get_string('supervised_extratime_until', 'local_classhours', gate::format_end(gate::max_end($o)));
            } else if ((int)$o->closeat > 0) {
                $line .= get_string('supervised_until', 'local_classhours', gate::format_end((int)$o->closeat));
            } else {
                $line .= get_string('supervised_untilclosed', 'local_classhours');
            }
            if ((string)$o->code !== '') {
                $line .= ' ' . \html_writer::span(get_string('supervised_code', 'local_classhours') . ' '
                    . \html_writer::tag('strong', s($o->code), array('class' => 'font-monospace fs-4 ms-1')),
                    'badge bg-light text-dark border ms-2');
            }
            if ($canact && count($openings) > 1 && !gate::in_extratime($o, $now)) {
                $line .= ' ' . self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid,
                    'openingid' => (int)$o->id), get_string('supervised_close_one', 'local_classhours'),
                    'btn-sm btn-outline-danger ms-2', $returnurl, $confirm);
            }
            $html .= \html_writer::tag('li', $line, array('class' => 'mb-1'));
        }
        $html .= \html_writer::end_tag('ul');
        if ($canact) {
            if (!$allextra) {
                $html .= self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid),
                    get_string('supervised_close', 'local_classhours'), 'btn-danger mt-1', $returnurl, $confirm) . ' ';
            }
            if ($anyextra) {
                $html .= self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid, 'force' => 1),
                    get_string('supervised_close_force', 'local_classhours'), 'btn-outline-danger mt-1', $returnurl,
                    get_string('supervised_confirm_force', 'local_classhours'));
            }
        }
        return $html;
    }

    /** Compteurs d'une activité, en clair (présents quand un code de séance est demandé). */
    public static function counters_html(\cm_info $cm, int $courseid): string {
        $counters = self::counters($cm, self::session_start($courseid, (int)$cm->id));
        $parts = array();
        foreach (gate::active_for_cm($courseid, (int)$cm->id) as $o) {
            if ((string)$o->code !== '') {
                $parts[] = get_string('supervised_present', 'local_classhours', gate::present_count($courseid, (int)$cm->id));
                break;
            }
        }
        if ($counters !== null) {
            $key = $cm->modname === 'quiz' ? 'supervised_counters_quiz' : 'supervised_counters_assign';
            $parts[] = get_string($key, 'local_classhours', (object)$counters);
        }
        return $parts ? \html_writer::span(implode(' — ', $parts), 'text-muted') : '';
    }

    /** Nom de l'activité avec son icône, en lien. */
    public static function cm_link(\cm_info $cm): string {
        $name = \html_writer::img($cm->get_icon_url(), '', array('class' => 'icon')) . ' ' . $cm->get_formatted_name();
        return $cm->url ? \html_writer::link($cm->url, $name) : $name;
    }

    // -------------------------------------------------------------------------
    //  Frise de l'élève
    // -------------------------------------------------------------------------

    /**
     * Frise d'un cours pour un élève : cartes horizontales, défilantes ; la
     * carte ouverte (sinon la prochaine) est amenée à l'écran par le JS.
     */
    public static function frise_html(int $courseid, int $userid, \moodle_url $returnurl): string {
        $items = self::timeline($courseid, $userid);
        if (!$items) {
            return '';
        }
        $cards = '';
        foreach ($items as $item) {
            $cards .= self::card_html($courseid, $item, $returnurl);
        }
        return \html_writer::div($cards, 'supervised-frise', array('data-supervised-frise' => $courseid));
    }

    /** Une carte de la frise. */
    private static function card_html(int $courseid, \stdClass $item, \moodle_url $returnurl): string {
        $cm = $item->cm;
        $classes = 'supervised-card is-' . $item->state . ($item->isnext ? ' is-next' : '');
        $date = $item->time > 0 ? self::format_date($item->time) : get_string('frise_nodate', 'local_classhours');
        $title = $cm->url && $cm->uservisible
            ? \html_writer::link($cm->url, $cm->get_formatted_name(), array('class' => 'supervised-card-title'))
            : \html_writer::span($cm->get_formatted_name(), 'supervised-card-title');
        $body = \html_writer::div(s($date), 'supervised-card-date') . $title;

        switch ($item->state) {
            case self::OPEN:
                $status = $item->end ? get_string('supervised_until', 'local_classhours', gate::format_end($item->end))
                    : get_string('block_opennow', 'local_classhours');
                $body .= \html_writer::div('● ' . $status, 'supervised-card-status');
                if ($item->needscode) {
                    $body .= \html_writer::link(new \moodle_url('/local/classhours/code.php', array('cmid' => $cm->id)),
                        get_string('frise_entercode', 'local_classhours'), array('class' => 'btn btn-primary btn-sm'));
                } else if ($cm->url) {
                    $body .= \html_writer::link($cm->url, get_string('block_start', 'local_classhours'),
                        array('class' => 'btn btn-success btn-sm'));
                }
                break;
            case self::DUE:
                $body .= \html_writer::div(get_string('frise_due', 'local_classhours'), 'supervised-card-status');
                if ($item->requested) {
                    $body .= \html_writer::span(get_string('frise_requested', 'local_classhours'),
                        'badge bg-info text-white');
                } else {
                    $body .= self::post_form(new \moodle_url('/local/classhours/catchup.php'),
                        array('cmid' => (int)$cm->id), get_string('frise_request', 'local_classhours'),
                        'btn-outline-danger btn-sm', $returnurl);
                }
                break;
            case self::DONE:
                $body .= \html_writer::div('✔ ' . get_string('frise_done', 'local_classhours'), 'supervised-card-status');
                break;
            case self::PAST:
                $body .= \html_writer::div(get_string('frise_past', 'local_classhours'), 'supervised-card-status');
                break;
            default:
                $body .= \html_writer::div($item->isnext ? get_string('frise_next', 'local_classhours')
                    : get_string('frise_upcoming', 'local_classhours'), 'supervised-card-status');
        }
        $attrs = array('data-supervised-item' => (int)$cm->id, 'data-state' => $item->state);
        if ($item->current) {
            $attrs['data-current'] = 1;
        }
        return \html_writer::div($body, $classes, $attrs);
    }

    // -------------------------------------------------------------------------
    //  Bloc
    // -------------------------------------------------------------------------

    /**
     * Contenu du bloc.
     *
     * Page du cours : l'enseignant y ouvre et ferme les activités du cours en
     * un clic ; l'élève y voit la frise du cours.
     * Tableau de bord : l'élève voit une frise par cours ; l'enseignant, pour
     * ses cours, les activités ouvertes, prévues aujourd'hui, et les demandes
     * de rattrapage.
     *
     * @param int $courseid cours de la page, 0 ou SITEID sur le tableau de bord
     * @param int $userid
     */
    public static function block_html(int $courseid, int $userid): string {
        if ($courseid > SITEID) {
            if (self::is_teacher($courseid, $userid)) {
                return self::block_course_teacher($courseid);
            }
            $html = self::frise_html($courseid, $userid, new \moodle_url('/course/view.php', array('id' => $courseid)));
            return $html !== '' ? $html : \html_writer::tag('p', get_string('block_nosupervised', 'local_classhours'),
                array('class' => 'text-muted mb-0'));
        }

        $teachercourses = array();
        $html = '';
        $returnurl = new \moodle_url('/my/');
        foreach (self::supervised_courses($userid) as $id => $course) {
            if (self::is_teacher($id, $userid)) {
                $teachercourses[$id] = $course;
                continue;
            }
            $frise = self::frise_html($id, $userid, $returnurl);
            if ($frise !== '') {
                $html .= \html_writer::tag('h6', format_string($course->fullname), array('class' => 'supervised-course-title'))
                    . $frise;
            }
        }
        if ($teachercourses) {
            $html = self::block_teacher_overview(self::teacher_overview($teachercourses)) . $html;
        }
        return $html !== '' ? $html : \html_writer::tag('p', get_string('block_none', 'local_classhours'),
            array('class' => 'text-muted mb-0'));
    }

    /** Enseignant, tableau de bord : ouvertes, prévues aujourd'hui, demandes de rattrapage. */
    private static function block_teacher_overview(array $overview): string {
        if (!$overview) {
            return '';
        }
        $returnurl = new \moodle_url('/my/');
        $confirm = get_string('supervised_confirm_close', 'local_classhours');
        $html = '';
        foreach ($overview as $entry) {
            $courseid = (int)$entry->course->id;
            $manageurl = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
            $rows = '';
            foreach ($entry->open as $cmid => $openings) {
                $ends = array_map(function($o) {
                    return (int)$o->closeat;
                }, $openings);
                $rows .= \html_writer::div(
                    \html_writer::span(get_string('supervised_open', 'local_classhours'), 'badge bg-success text-white me-1')
                    . self::cm_link($entry->cms[$cmid]) . ' '
                    . \html_writer::span(in_array(0, $ends, true) ? get_string('supervised_untilclosed', 'local_classhours')
                        : get_string('supervised_until', 'local_classhours', gate::format_end(max($ends))), 'small text-muted')
                    . ' ' . self::action_form($courseid, array('action' => 'close', 'cmid' => $cmid),
                        get_string('supervised_close', 'local_classhours'), 'btn-sm btn-danger ms-1', $returnurl, $confirm),
                    'mb-1');
            }
            foreach ($entry->today as $cmid => $cm) {
                $rows .= \html_writer::div(
                    \html_writer::span(get_string('block_today', 'local_classhours'), 'badge bg-warning text-dark me-1')
                    . self::cm_link($cm) . ' '
                    . self::action_form($courseid, array('action' => 'open', 'cmid' => $cmid, 'scope' => gate::SCOPE_COURSE,
                        'duration' => gate::MANUAL), get_string('supervised_open_button', 'local_classhours'),
                        'btn-sm btn-success ms-1', $returnurl),
                    'mb-1');
            }
            if ($entry->requests) {
                $rows .= \html_writer::div(\html_writer::link(new \moodle_url($manageurl, array(), 'catchup'),
                    get_string('block_requests', 'local_classhours', $entry->requests)), 'mb-1');
            }
            $html .= \html_writer::div(
                \html_writer::tag('h6', \html_writer::link($manageurl, format_string($entry->course->fullname)))
                . $rows, 'border border-warning rounded p-2 mb-2');
        }
        return $html;
    }

    /** Enseignant, page du cours : chaque activité surveillée, Ouvrir / Fermer en un clic. */
    private static function block_course_teacher(int $courseid): string {
        $cms = self::planned_cms($courseid);
        $manageurl = new \moodle_url('/local/classhours/supervised.php', array('courseid' => $courseid));
        if (!$cms) {
            return \html_writer::tag('p', get_string('block_nosupervised', 'local_classhours'), array('class' => 'text-muted'))
                . \html_writer::link($manageurl, get_string('block_manage', 'local_classhours'));
        }
        $returnurl = new \moodle_url('/course/view.php', array('id' => $courseid));
        $confirm = get_string('supervised_confirm_close', 'local_classhours');
        $dates = plan::dates_for_course($courseid);
        $html = '';
        foreach ($cms as $cmid => $cm) {
            $openings = gate::active_for_cm($courseid, $cmid);
            $body = \html_writer::div(self::cm_link($cm), 'fw-bold');
            $planned = self::first_date($dates[$cmid] ?? array());
            if ($planned) {
                $body .= \html_writer::div(get_string('frise_planned', 'local_classhours', self::format_date($planned)),
                    'small text-muted');
            }
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
     * Rafraîchit le contenu du bloc toutes les 30 s par le service web. Une
     * activité qui vient d'ouvrir est mise en évidence ; dans chaque frise, la
     * carte ouverte (sinon la prochaine) est amenée à l'écran.
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
                var focus = function() {
                    root.querySelectorAll('[data-supervised-frise]').forEach(function(frise) {
                        var card = frise.querySelector('[data-current]');
                        if (card) {
                            frise.scrollLeft = Math.max(0, card.offsetLeft - frise.offsetLeft - 16);
                        }
                    });
                };
                focus();
                var tick = function() {
                    if (document.hidden) { return; }
                    Ajax.call([{methodname: 'local_classhours_supervised_refresh',
                        args: {courseid: " . (int)$courseid . ", view: 'block'}}])[0].then(function(r) {
                        if (r.signature === root.getAttribute('data-signature')) { return; }
                        var before = {};
                        root.querySelectorAll('[data-supervised-item]').forEach(function(n) {
                            before[n.getAttribute('data-supervised-item')] = n.getAttribute('data-state');
                        });
                        root.innerHTML = r.html;
                        root.setAttribute('data-signature', r.signature);
                        root.querySelectorAll('[data-supervised-item]').forEach(function(n) {
                            if (n.getAttribute('data-state') === 'open'
                                    && before[n.getAttribute('data-supervised-item')] !== 'open') {
                                n.classList.add('is-new');
                            }
                        });
                        focus();
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

    /**
     * Page de pilotage : « Ouvrir pour lui » d'un élève à rattraper choisit
     * l'élève dans le menu « Pour » de la carte et y amène l'écran.
     */
    public static function js_prefill(): void {
        global $PAGE;
        $PAGE->requires->js_amd_inline("
            document.addEventListener('click', function(e) {
                var button = e.target.closest ? e.target.closest('[data-supervised-prefill]') : null;
                if (!button) { return; }
                var select = document.getElementById('supervised-scope-' + button.getAttribute('data-supervised-prefill'));
                if (!select) { return; }
                select.value = button.getAttribute('data-scope');
                select.scrollIntoView({block: 'center'});
                select.focus();
            });");
    }
}
