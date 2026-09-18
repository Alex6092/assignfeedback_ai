<?php
namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Injection du widget de chat en pied de page.
 *
 * Passer par le hook de pied de page plutôt que par un bloc rend le tuteur
 * indépendant du thème et des régions disponibles : il apparaît au même endroit
 * sur toutes les pages d'activité concernées.
 */
class hook_callbacks {

    /** Types de page où le widget est proposé (mod_assign réécrit le pagetype). */
    const PAGETYPES = array('mod-assign-view', 'mod-assign-editsubmission');

    /** Mises en page où l'on n'injecte jamais rien. */
    const SKIP_LAYOUTS = array('maintenance', 'print', 'redirect', 'embedded', 'popup');

    public static function before_footer_html_generation(
            \core\hook\output\before_footer_html_generation $hook): void {
        $html = self::widget_html();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }

    /**
     * Rend le widget si toutes les conditions sont réunies, sinon ''.
     */
    private static function widget_html() {
        global $PAGE, $CFG, $USER;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return '';
        }
        if (in_array($PAGE->pagelayout, self::SKIP_LAYOUTS, true)) {
            return '';
        }
        if (!in_array($PAGE->pagetype, self::PAGETYPES, true)) {
            return '';
        }
        if (empty($PAGE->cm) || !in_array($PAGE->cm->modname, activity::SUPPORTED_MODS, true)) {
            return '';
        }
        $context = $PAGE->context;
        if (!($context instanceof \context_module)) {
            return '';
        }
        if (!activity::is_enabled((int)$PAGE->cm->id)) {
            return '';
        }
        if (!has_capability('local/aichat:use', $context)) {
            return '';
        }

        $cmid = (int)$PAGE->cm->id;
        $config = array(
            'cmid'         => $cmid,
            'sesskey'      => sesskey(),
            'ajaxurl'      => (new \moodle_url('/local/aichat/ajax.php'))->out(false),
            'streamurl'    => (new \moodle_url('/local/aichat/stream.php'))->out(false),
            'pollinterval' => self::poll_interval(),
            'maxchars'     => self::setting('maxmessagechars', 2000),
            'notice'       => self::student_notice(),
            'strings'      => self::strings(),
        );

        // La feuille de style ne peut plus être ajoutée par $PAGE->requires->css()
        // à ce stade (le <head> est déjà écrit) : on la référence ici.
        $version = (int)get_config('local_aichat', 'version');
        $cssurl  = new \moodle_url('/local/aichat/styles/chat.css', array('v' => $version));
        $jsurl   = new \moodle_url('/local/aichat/js/chat.js', array('v' => $version));

        $PAGE->requires->js($jsurl, false);

        return \html_writer::empty_tag('link', array(
                'rel'  => 'stylesheet',
                'type' => 'text/css',
                'href' => $cssurl->out(false),
            ))
            . \html_writer::div('', '', array(
                'id'          => 'local-aichat-root',
                'data-config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            ));
    }

    /** Intervalle de scrutation de la file, en millisecondes. */
    private static function poll_interval() {
        $value = (int)get_config('local_aichat', 'pollintervalms');
        if ($value < 500) {
            $value = 1500;
        }
        return $value;
    }

    /** Avertissement affiché à l'élève (conversations lues par l'enseignant). */
    private static function student_notice() {
        $notice = (string)get_config('local_aichat', 'studentnotice');
        if (trim($notice) === '') {
            $notice = get_string('studentnotice_default', 'local_aichat');
        }
        return $notice;
    }

    private static function setting($name, $default) {
        $value = (int)get_config('local_aichat', $name);
        return ($value > 0) ? $value : (int)$default;
    }

    /**
     * Libellés de l'interface, résolus côté serveur : le widget n'a ainsi
     * aucune dépendance au chargeur de chaînes JavaScript.
     */
    private static function strings() {
        $keys = array(
            'widget_title', 'widget_open', 'widget_close', 'widget_new',
            'widget_placeholder', 'widget_send', 'widget_stop', 'widget_retry',
            'widget_welcome', 'widget_connecting', 'widget_generating',
            'widget_queued_next', 'widget_queued_n', 'widget_interrupted',
            'widget_quota', 'widget_networkerror', 'widget_sendhint',
        );
        $out = array();
        foreach ($keys as $key) {
            $out[$key] = get_string($key, 'local_aichat');
        }
        return $out;
    }
}
