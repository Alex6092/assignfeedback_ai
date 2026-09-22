<?php
defined('MOODLE_INTERNAL') || die();

use local_aichat\activity;
use local_aichat\websearch\manager as websearch;

/**
 * Ajoute la section « Tuteur IA » au formulaire de réglages des activités
 * prises en charge.
 *
 * Les formulaires d'activité n'ont pas (encore) de hook dédié dans Moodle : ces
 * callbacks lib.php restent le mécanisme officiel et non déprécié.
 *
 * @param moodleform_mod    $formwrapper
 * @param MoodleQuickForm   $mform
 */
function local_aichat_coursemodule_standard_elements($formwrapper, $mform) {
    $current = $formwrapper->get_current();
    $modname = isset($current->modulename) ? $current->modulename : '';
    if (!in_array($modname, activity::SUPPORTED_MODS, true)) {
        return;
    }
    if (!has_capability('local/aichat:configure', $formwrapper->get_context())) {
        return;
    }

    $cm     = $formwrapper->get_coursemodule();
    $config = ($cm) ? activity::get((int)$cm->id) : null;

    $mform->addElement('header', 'local_aichat_header',
        get_string('formheader', 'local_aichat'));

    $mform->addElement('advcheckbox', 'aichatenabled',
        get_string('form_enabled', 'local_aichat'));
    $mform->addHelpButton('aichatenabled', 'form_enabled', 'local_aichat');
    $mform->setDefault('aichatenabled', ($config && $config->enabled) ? 1 : 0);

    $mform->addElement('advcheckbox', 'aichatincludeintro',
        get_string('form_includeintro', 'local_aichat'));
    $mform->addHelpButton('aichatincludeintro', 'form_includeintro', 'local_aichat');
    $mform->setDefault('aichatincludeintro', ($config === null || $config->includeintro) ? 1 : 0);
    $mform->hideIf('aichatincludeintro', 'aichatenabled', 'notchecked');

    // Le brief n'a de sens que si la correction IA fournit un corrigé.
    $mform->addElement('advcheckbox', 'aichatincludebrief',
        get_string('form_includebrief', 'local_aichat'));
    $mform->addHelpButton('aichatincludebrief', 'form_includebrief', 'local_aichat');
    $mform->setDefault('aichatincludebrief', ($config === null || $config->includebrief) ? 1 : 0);
    $mform->hideIf('aichatincludebrief', 'aichatenabled', 'notchecked');

    // État du brief + lien de relecture (uniquement sur une activité existante).
    if ($config !== null && $config->briefstatus !== 'none') {
        $manageurl = new moodle_url('/local/aichat/manage.php', array('id' => (int)$cm->id));
        $status    = get_string('briefstatus_' . $config->briefstatus, 'local_aichat');
        $mform->addElement('static', 'aichatbriefstatus',
            get_string('form_briefstatus', 'local_aichat'),
            html_writer::span(s($status), 'badge badge-info mr-2')
            . html_writer::link($manageurl, get_string('form_briefreview', 'local_aichat')));
    }

    $mform->addElement('textarea', 'aichatcustomprompt',
        get_string('form_customprompt', 'local_aichat'),
        array('rows' => 4, 'cols' => 60));
    // PARAM_RAW : PARAM_TEXT passerait par strip_tags() et détruirait toute
    // consigne contenant « < » (extraits de code, comparaisons…).
    $mform->setType('aichatcustomprompt', PARAM_RAW);
    $mform->addHelpButton('aichatcustomprompt', 'form_customprompt', 'local_aichat');
    $mform->setDefault('aichatcustomprompt', ($config !== null) ? (string)$config->customprompt : '');
    $mform->hideIf('aichatcustomprompt', 'aichatenabled', 'notchecked');

    // Recherches du tuteur : proposées seulement si l'administrateur les a
    // activées pour le site. « Aucune » par défaut : c'est un choix de
    // l'enseignant.
    if (websearch::site_enabled()) {
        $mform->addElement('select', 'aichatwebsearch', get_string('form_websearch', 'local_aichat'), array(
            websearch::MODE_NONE     => get_string('form_websearch_none', 'local_aichat'),
            websearch::MODE_WEB      => get_string('form_websearch_web', 'local_aichat'),
            websearch::MODE_MATERIAL => get_string('form_websearch_material', 'local_aichat'),
        ));
        $mform->addHelpButton('aichatwebsearch', 'form_websearch', 'local_aichat');
        $mform->setDefault('aichatwebsearch', ($config !== null) ? (int)$config->websearch : 0);
        $mform->hideIf('aichatwebsearch', 'aichatenabled', 'notchecked');

        $mform->addElement('textarea', 'aichatwebsearchsites', get_string('form_websearchsites', 'local_aichat'),
            array('rows' => 4, 'cols' => 40));
        $mform->setType('aichatwebsearchsites', PARAM_TEXT);
        $mform->addHelpButton('aichatwebsearchsites', 'form_websearchsites', 'local_aichat');
        $mform->setDefault('aichatwebsearchsites', ($config !== null) ? (string)$config->websearchsites : '');
        $mform->hideIf('aichatwebsearchsites', 'aichatenabled', 'notchecked');
        $mform->hideIf('aichatwebsearchsites', 'aichatwebsearch', 'neq', websearch::MODE_MATERIAL);
    }
}

/**
 * Enregistre la configuration du tuteur après l'enregistrement de l'activité.
 *
 * @param stdClass $moduleinfo
 * @param stdClass $course
 * @return stdClass toujours $moduleinfo (contrat du callback)
 */
function local_aichat_coursemodule_edit_post_actions($moduleinfo, $course) {
    if (!in_array($moduleinfo->modulename, activity::SUPPORTED_MODS, true)) {
        return $moduleinfo;
    }
    // Champs absents : l'enseignant n'avait pas la capacité, ou la sauvegarde
    // vient d'un import/restauration. On ne touche à rien.
    if (!isset($moduleinfo->aichatenabled)) {
        return $moduleinfo;
    }

    $cmid    = (int)$moduleinfo->coursemodule;
    $enabled = !empty($moduleinfo->aichatenabled) ? 1 : 0;

    $data = array(
        'enabled'      => $enabled,
        'includeintro' => !empty($moduleinfo->aichatincludeintro) ? 1 : 0,
        'includebrief' => !empty($moduleinfo->aichatincludebrief) ? 1 : 0,
        'customprompt' => isset($moduleinfo->aichatcustomprompt)
            ? (string)$moduleinfo->aichatcustomprompt : null,
    );
    // Champs absents (recherche désactivée pour le site) : valeurs conservées.
    if (isset($moduleinfo->aichatwebsearch)) {
        $data['websearch'] = max(0, min(2, (int)$moduleinfo->aichatwebsearch));
        if (isset($moduleinfo->aichatwebsearchsites)) {
            $data['websearchsites'] = trim((string)$moduleinfo->aichatwebsearchsites);
        }
    }
    activity::save($cmid, (int)$course->id, $data);
    // Le brief est mis en file par observer::course_module_saved(), une fois
    // le corrigé de la correction IA enregistré (voir son commentaire).

    return $moduleinfo;
}

/**
 * Lien « Tuteur IA » dans les réglages de l'activité.
 *
 * @param settings_navigation $settingsnav
 * @param context             $context
 */
function local_aichat_extend_settings_navigation(settings_navigation $settingsnav, $context) {
    if (!($context instanceof context_module)) {
        return;
    }
    if (!has_capability('local/aichat:configure', $context)
            && !has_capability('local/aichat:viewconversations', $context)) {
        return;
    }
    $cmid = (int)$context->instanceid;
    if (activity::get($cmid) === null) {
        return; // jamais configuré ici
    }
    $node = $settingsnav->get('modulesettings');
    if (!$node) {
        return;
    }
    $node->add(
        get_string('pluginname', 'local_aichat'),
        new moodle_url('/local/aichat/manage.php', array('id' => $cmid)),
        navigation_node::TYPE_SETTING,
        null,
        'localaichat',
        new pix_icon('i/chat_topic', '')
    );
}

/**
 * Lien « Tuteur IA : mes clés de recherche » dans les Préférences de
 * l'utilisateur (section « Compte utilisateur »), comme le fait auth_oauth2
 * pour ses comptes liés. Seulement pour son propre compte, et si la recherche
 * Web est activée pour le site.
 *
 * @param navigation_node $useraccount
 * @param stdClass        $user
 * @param context_user    $context
 * @param stdClass        $course
 * @param context_course  $coursecontext
 */
function local_aichat_extend_navigation_user_settings(navigation_node $useraccount, stdClass $user,
        context_user $context, stdClass $course, context_course $coursecontext) {
    global $USER;
    if (empty(local_aichat_personal_keys_usages()) || (int)$user->id !== (int)$USER->id
            || \core\session\manager::is_loggedinas() || isguestuser()) {
        return;
    }
    $parent = $useraccount->parent ? $useraccount->parent->find('useraccount', navigation_node::TYPE_CONTAINER) : false;
    $target = $parent ? $parent : $useraccount;
    $target->add(get_string('mykeys_page', 'local_aichat'), new moodle_url('/local/aichat/mykeys.php'),
        navigation_node::TYPE_SETTING, null, 'localaichatkeys');
}

/**
 * À quoi servent les clés de recherche personnelles sur ce site : la recherche
 * Web du tuteur, et tout plugin qui déclare s'en servir par un rappel
 * <composant>_aichat_keys_usage() renvoyant son nom (ou '' s'il est désactivé).
 * MoodleSearch (local_moodlesearch) utilise ainsi la clé Tavily de l'élève.
 *
 * Vide : la page « mes clés de recherche » n'a pas lieu d'être.
 *
 * @return string[] noms des usages
 */
function local_aichat_personal_keys_usages() {
    $usages = array();
    if (websearch::site_enabled()) {
        $usages[] = get_string('pluginname', 'local_aichat');
    }
    foreach (get_plugins_with_function('aichat_keys_usage') as $plugins) {
        foreach ($plugins as $function) {
            $label = trim((string)$function());
            if ($label !== '') {
                $usages[] = $label;
            }
        }
    }
    return $usages;
}
