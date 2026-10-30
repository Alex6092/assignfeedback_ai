<?php
namespace local_aibridge\reader;

use local_aibridge\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Le site vu par le pont : version de Moodle, plugins du projet, réglages
 * utiles à la conception d'un parcours, thème et ses classes CSS, droits du
 * compte appelant.
 */
class site_reader {

    /** Plugins dont l'agent doit connaître la présence et la version. */
    const COMPONENTS = array(
        'local_aibridge', 'local_lessonexport',
        'local_aifeedback', 'assignfeedback_ai', 'qtype_aiessay', 'qtype_aishortanswer',
        'local_aiquizgen', 'local_aichat', 'local_aimissions', 'local_moodlesearch',
        'local_classhours', 'availability_classhours', 'availability_supervised',
        'quizaccess_classhours', 'block_supervised', 'local_attemptcheck',
        'local_efenotes', 'block_avancementlapro', 'block_opnsenseaccess', 'local_cross_grader',
        'workshopform_competence', 'mod_videoqcm', 'block_videoqcm',
        'mod_lesson', 'mod_quiz', 'mod_assign', 'mod_page', 'mod_qbank', 'mod_subsection',
        'qtype_coderunner', 'qtype_gapselect', 'qtype_ordering', 'qtype_answersselect', 'qtype_multianswer',
    );

    /**
     * Réglages de site utiles à la conception (liste blanche : aucun secret,
     * aucune adresse de serveur).
     */
    const SETTINGS = array(
        'local_aifeedback'      => array('model_feedback', 'vision_enabled', 'defaultsystemprompt', 'max_attempts_to_grade'),
        'assignfeedback_ai'     => array('default', 'showscore'),
        'local_aichat'          => array('model_tutor', 'includeintrodefault', 'websearch_enabled', 'quota_messages',
            'quota_tokens', 'quota_window_hours', 'moderation_enabled'),
        'local_efenotes'        => array('seuil_jaune_default', 'seuil_bleu_default', 'seuil_vert_default', 'showinstructions'),
        'local_classhours'      => array('graceminutes', 'efeauto_default', 'useglobal_default', 'extratimedefault'),
        'local_aimissions'      => array('scalename', 'maxsprints', 'communication_competency'),
        'block_avancementlapro' => array('efe_enable', 'nativesync_default'),
    );

    /** Classes CSS du thème attendues par les contenus des parcours. */
    const CIEL_CLASSES = array('objectif', 'astuce', 'danger', 'suite', 'formule', 'comp', 'carte',
        'carte-item', 'cdc', 'rendre', 'phase', 'install');

    public static function read(): array {
        global $CFG, $USER;

        $pluginman = \core_plugin_manager::instance();
        $plugins = array();
        foreach (self::COMPONENTS as $component) {
            $info = $pluginman->get_plugin_info($component);
            $plugins[$component] = $info && !empty($info->versiondb)
                ? array('installed' => true, 'version' => (string)$info->versiondb, 'release' => (string)$info->release)
                : array('installed' => false);
        }

        $settings = array();
        foreach (self::SETTINGS as $component => $names) {
            if (empty($plugins[$component]['installed'])) {
                continue;
            }
            foreach ($names as $name) {
                $value = get_config($component, $name);
                if ($value !== false) {
                    $settings[$component][$name] = $value;
                }
            }
        }

        return array(
            'moodle'   => array(
                'release'            => $CFG->release,
                'version'            => (string)$CFG->version,
                'branch'             => $CFG->branch ?? null,
                'wwwroot'            => $CFG->wwwroot,
                'lang'               => $CFG->lang,
                'timezone'           => \core_date::get_server_timezone(),
                'enableavailability' => (int)!empty($CFG->enableavailability),
                'enablecompletion'   => (int)!empty($CFG->enablecompletion),
            ),
            'plugins'  => $plugins,
            'settings' => $settings,
            'theme'    => self::theme(),
            'bridge'   => self::bridge(),
            'caller'   => array(
                'userid'   => (int)$USER->id,
                'username' => $USER->username,
                'fullname' => fullname($USER),
            ),
        );
    }

    /** Thème actif, « Code SCSS initial » et « SCSS brut », classes CIEL trouvées. */
    private static function theme(): array {
        global $CFG;
        $name = $CFG->theme;
        $scsspre = '';
        $scss = '';
        try {
            $theme = \theme_config::load($name);
            $scsspre = (string)($theme->settings->scsspre ?? '');
            $scss = (string)($theme->settings->scss ?? '');
        } catch (\Throwable $e) {
            // Thème introuvable : on renvoie ce qu'on sait.
            debugging('local_aibridge : thème ' . $name . ' : ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $all = $scsspre . "\n" . $scss;
        $found = array();
        $missing = array();
        foreach (self::CIEL_CLASSES as $class) {
            if (preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $all)) {
                $found[] = $class;
            } else {
                $missing[] = $class;
            }
        }
        return array(
            'name'           => $name,
            'cssurl'         => self::css_url($name),
            'scsspre'        => $scsspre,
            'scss'           => $scss,
            'cielclasses'    => $found,
            'missingclasses' => $missing,
        );
    }

    /**
     * Feuille de style compilée du thème (publique, sans connexion), comme la
     * construit theme_config::css_urls() : sert à reproduire hors de Moodle la
     * mise en page exacte des contenus (export PDF des leçons).
     */
    private static function css_url(string $name): ?string {
        global $CFG;
        $rev = theme_get_revision();
        if ($rev <= -1) {
            return null; // Thème en mode développeur : pas d'adresse stable.
        }
        $sub = theme_get_sub_revision_for_theme($name);
        if ($sub && $sub > 0) {
            $rev .= '_' . $sub;
        }
        $url = new \moodle_url('/theme/styles.php');
        if (!empty($CFG->slasharguments)) {
            $url->set_slashargument('/' . $name . '/' . $rev . '/all', 'noparam', true);
        } else {
            $url->params(array('theme' => $name, 'rev' => $rev, 'type' => 'all'));
        }
        return $url->out(false);
    }

    /** État du pont : écriture, bac à sable, droits d'écriture du compte. */
    private static function bridge(): array {
        $sandbox = array();
        $ids = array_filter(array_map('intval', explode(',', (string)get_config('local_aibridge', 'sandboxcategories'))));
        foreach ($ids as $id) {
            $category = \core_course_category::get($id, IGNORE_MISSING, true);
            $sandbox[] = array(
                'id'       => $id,
                'name'     => $category ? $category->get_nested_name(false) : null,
                'canwrite' => $category ? has_capability('local/aibridge:write', \context_coursecat::instance($id)) : false,
            );
        }
        return array(
            'enablewrites'      => (int)get_config('local_aibridge', 'enablewrites'),
            'sandboxcategories' => $sandbox,
            'logretentiondays'  => (int)get_config('local_aibridge', 'logretentiondays'),
        );
    }
}
