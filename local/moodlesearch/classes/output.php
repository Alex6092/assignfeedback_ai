<?php
namespace local_moodlesearch;

defined('MOODLE_INTERNAL') || die();

use html_writer;
use moodle_url;

/**
 * Rendu de la page de recherche : formulaire, onglets, résultats, messages.
 * Tout est échappé ici : les titres et extraits viennent de pages Web.
 */
class output {

    /** Longueur maximale d'un extrait affiché. */
    const SNIPPET = 320;

    /** Formulaire de recherche (GET : URL partageable, bouton « Retour » fonctionnel). */
    public static function search_form(string $q, string $tab, string $period): string {
        $url = new moodle_url('/local/moodlesearch/index.php');
        $html  = html_writer::start_tag('form', array('method' => 'get', 'action' => $url->out(false),
            'class' => 'moodlesearch-form', 'role' => 'search'));
        $html .= html_writer::empty_tag('input', array('type' => 'search', 'name' => 'q', 'value' => $q,
            'class' => 'form-control moodlesearch-input', 'autofocus' => 'autofocus', 'maxlength' => searcher::MAXQUERY,
            'aria-label' => get_string('search_placeholder', 'local_moodlesearch'),
            'placeholder' => get_string('search_placeholder', 'local_moodlesearch')));
        $html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'tab', 'value' => $tab));
        $options = array();
        foreach (searcher::PERIODS as $p) {
            $options[$p] = get_string('period_' . $p, 'local_moodlesearch');
        }
        $html .= html_writer::select($options, 'period', $period, false,
            array('class' => 'custom-select form-select moodlesearch-period',
                  'aria-label' => get_string('period', 'local_moodlesearch')));
        $html .= html_writer::tag('button', get_string('search_button', 'local_moodlesearch'),
            array('type' => 'submit', 'class' => 'btn btn-primary'));
        $html .= html_writer::end_tag('form');
        return $html;
    }

    /** Onglets Web / Actualités (même requête, même période). */
    public static function tabs(string $q, string $tab, string $period): string {
        $items = '';
        foreach (searcher::TABS as $t) {
            $url = new moodle_url('/local/moodlesearch/index.php', array('q' => $q, 'tab' => $t, 'period' => $period));
            $attrs = array('class' => 'nav-link' . ($t === $tab ? ' active' : ''), 'href' => $url->out(false));
            if ($t === $tab) {
                $attrs['aria-current'] = 'page';
            }
            $items .= html_writer::tag('li', html_writer::tag('a', get_string('tab_' . $t, 'local_moodlesearch'), $attrs),
                array('class' => 'nav-item'));
        }
        return html_writer::tag('ul', $items, array('class' => 'nav nav-tabs moodlesearch-tabs'));
    }

    /**
     * Liste des résultats d'une recherche.
     *
     * @param \stdClass $res     sortie de searcher::search()
     * @param array     $blocked url => bool (liste noire de la classe)
     */
    public static function results(\stdClass $res, array $blocked, string $q, string $tab, string $period,
            bool $more): string {
        if (!$res->ok) {
            return html_writer::div(self::reason_message($res->reason), 'alert alert-warning moodlesearch-message');
        }
        if (empty($res->items)) {
            return html_writer::div(get_string('noresults', 'local_moodlesearch', s($q)),
                'moodlesearch-message moodlesearch-empty');
        }

        $html = '';
        foreach (array_values($res->items) as $rank => $item) {
            $url = (string)$item['url'];
            $go = new moodle_url('/local/moodlesearch/go.php',
                array('search' => $res->searchid, 'rank' => $rank, 'sesskey' => sesskey()));

            $title = trim((string)($item['title'] ?? ''));
            $title = ($title !== '') ? $title : searcher::domain($url);
            $heading = html_writer::link($go, s($title), array('class' => 'moodlesearch-link'));
            if (!empty($blocked[$url])) {
                $heading .= ' ' . html_writer::span(get_string('badge_blocked', 'local_moodlesearch'),
                    'badge bg-danger text-white moodlesearch-blocked',
                    array('title' => get_string('badge_blocked_help', 'local_moodlesearch')));
            }

            $source = html_writer::span(s(searcher::domain($url)), 'moodlesearch-domain');
            $path = self::display_path($url);
            if ($path !== '') {
                $source .= html_writer::span(' › ' . s($path), 'moodlesearch-path');
            }

            $body = html_writer::div($source, 'moodlesearch-source')
                . html_writer::tag('h3', $heading, array('class' => 'moodlesearch-title'));
            $date = self::display_date((string)($item['age'] ?? ''));
            if ($date !== '') {
                $body .= html_writer::div(s($date), 'moodlesearch-date');
            }
            $snippet = self::snippet((string)($item['snippet'] ?? ''));
            if ($snippet !== '') {
                $body .= html_writer::tag('p', s($snippet), array('class' => 'moodlesearch-snippet'));
            }
            $html .= html_writer::tag('li', $body, array('class' => 'moodlesearch-result'));
        }
        $html = html_writer::tag('ol', $html, array('class' => 'moodlesearch-results'));

        if (!$more && count($res->items) >= $res->count && $res->count < searcher::MORE_COUNT) {
            $moreurl = new moodle_url('/local/moodlesearch/index.php',
                array('q' => $q, 'tab' => $tab, 'period' => $period, 'more' => 1));
            $html .= html_writer::div(html_writer::link($moreurl, get_string('moreresults', 'local_moodlesearch'),
                array('class' => 'btn btn-outline-secondary')), 'moodlesearch-more');
        }
        return $html;
    }

    /** Pied de résultats : source, consommation de la clé. */
    public static function footer(?array $usage): string {
        $text = get_string('poweredby', 'local_moodlesearch');
        if ($usage !== null) {
            $text .= ' · ' . get_string('keyusage', 'local_moodlesearch', (object)array(
                'used' => $usage['used'],
                'cap'  => ($usage['cap'] >= PHP_INT_MAX) ? '∞' : $usage['cap'],
            ));
        }
        return html_writer::div($text, 'moodlesearch-footer');
    }

    /** Encadré « Mes dernières recherches ». */
    public static function recent(array $searches): string {
        if (empty($searches)) {
            return '';
        }
        $items = '';
        foreach ($searches as $s) {
            $url = new moodle_url('/local/moodlesearch/index.php',
                array('q' => $s->query, 'tab' => $s->tab, 'period' => $s->period));
            $items .= html_writer::tag('li', html_writer::link($url, s($s->query)));
        }
        return html_writer::div(
            html_writer::tag('h4', get_string('recent', 'local_moodlesearch'))
            . html_writer::tag('ul', $items), 'moodlesearch-recent');
    }

    /** Message d'un refus ou d'une erreur de recherche. */
    public static function reason_message(string $reason): string {
        $key = 'reason_' . $reason;
        if (!get_string_manager()->string_exists($key, 'local_moodlesearch')) {
            $key = 'reason_other';
        }
        $a = null;
        if ($reason === access::NOKEY) {
            $a = (new moodle_url('/local/aichat/mykeys.php'))->out(false);
        } else if ($reason === 'ratelimit') {
            $a = searcher::maxperhour();
        }
        return get_string($key, 'local_moodlesearch', $a);
    }

    /**
     * Suite donnée à un clic, pour la page d'attente.
     *
     * @param string $status statut OPNsense
     * @return array{go:bool, message:string, level:string, showlink:bool, macurl:string}
     */
    public static function click_outcome(string $status): array {
        $out = array('go' => false, 'message' => '', 'level' => 'warning', 'showlink' => false, 'macurl' => '');
        switch ($status) {
            case 'opened':
            case 'already':
            case opnsense_bridge::NONE:
            case 'nocohort':
            case 'notconfigured':
                $out['go'] = true;
                return $out;
            case 'pending':
                $out['level'] = 'info';
                $out['showlink'] = true;
                break;
            case 'blocked':
            case 'exam':
            case 'invalid':
            case 'wildcard':
                $out['level'] = 'danger';
                break;
            case 'nomac':
                $out['showlink'] = true;
                $out['macurl'] = (new moodle_url('/blocks/opnsenseaccess/student.php'))->out(false);
                break;
            default:
                $status = 'error';
                $out['showlink'] = true;
        }
        $out['message'] = get_string('open_' . $status, 'local_moodlesearch');
        return $out;
    }

    /** Chemin affiché sous le domaine (« a › b »), sans requête ni fragment. */
    public static function display_path(string $url): string {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return '';
        }
        $parts = array_slice(array_filter(explode('/', rawurldecode($path)), 'strlen'), 0, 3);
        $out = implode(' › ', $parts);
        return (\core_text::strlen($out) > 80) ? \core_text::substr($out, 0, 79) . '…' : $out;
    }

    /** Date de publication (actualités) en toutes lettres, '' si absente ou illisible. */
    public static function display_date(string $published): string {
        $published = trim($published);
        if ($published === '') {
            return '';
        }
        $time = strtotime($published);
        return ($time === false) ? '' : userdate($time, get_string('strftimedate', 'langconfig'));
    }

    /** Extrait d'un résultat : texte seul, d'une longueur raisonnable. */
    public static function snippet(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (\core_text::strlen($text) > self::SNIPPET) {
            $cut = \core_text::substr($text, 0, self::SNIPPET);
            $space = \core_text::strrpos($cut, ' ');
            $text = (($space !== false && $space > self::SNIPPET * 0.6) ? \core_text::substr($cut, 0, $space) : $cut) . '…';
        }
        return $text;
    }
}
