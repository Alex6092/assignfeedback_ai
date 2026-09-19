<?php
defined('MOODLE_INTERNAL') || die();

use local_aichat\activity;
use local_aichat\brief;

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

    // Recherche Web : proposée seulement si l'administrateur l'a activée
    // pour le site. Décochée par défaut : c'est un choix de l'enseignant.
    if (\local_aichat\websearch\manager::site_enabled()) {
        $mform->addElement('advcheckbox', 'aichatwebsearch',
            get_string('form_websearch', 'local_aichat'));
        $mform->addHelpButton('aichatwebsearch', 'form_websearch', 'local_aichat');
        $mform->setDefault('aichatwebsearch', ($config !== null && !empty($config->websearch)) ? 1 : 0);
        $mform->hideIf('aichatwebsearch', 'aichatenabled', 'notchecked');
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
    // Case absente (recherche désactivée pour le site) : valeur conservée.
    if (isset($moduleinfo->aichatwebsearch)) {
        $data['websearch'] = !empty($moduleinfo->aichatwebsearch) ? 1 : 0;
    }
    activity::save($cmid, (int)$course->id, $data);

    if ($enabled) {
        // Génère (ou régénère) le brief si l'énoncé ou le corrigé ont changé.
        brief::schedule_if_stale($cmid);
    }

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
