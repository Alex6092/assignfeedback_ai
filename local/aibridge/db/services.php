<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Fonctions du pont. Les données détaillées sont renvoyées dans un champ
 * « json » (chaîne JSON) : la structure varie selon le type d'activité et les
 * plugins installés, et son seul client est le serveur MCP tools/moodle-mcp.
 */
$functions = array(
    'local_aibridge_get_site_info' => array(
        'classname'    => 'local_aibridge\external\get_site_info',
        'description'  => 'Version de Moodle, plugins du projet, réglages utiles, thème, droits du jeton.',
        'type'         => 'read',
        'capabilities' => '',
    ),
    'local_aibridge_list_courses' => array(
        'classname'    => 'local_aibridge\external\list_courses',
        'description'  => 'Catégories et cours lisibles par le pont, avec filtre texte.',
        'type'         => 'read',
        'capabilities' => 'local/aibridge:read',
    ),
    'local_aibridge_get_course' => array(
        'classname'    => 'local_aibridge\external\get_course',
        'description'  => 'Réglages du cours, heures de cours, sections et activités avec leurs extensions.',
        'type'         => 'read',
        'capabilities' => 'local/aibridge:read',
    ),
    'local_aibridge_get_module' => array(
        'classname'    => 'local_aibridge\external\get_module',
        'description'  => 'Tout le détail d\'une activité : réglages, contenu, note, extensions des plugins.',
        'type'         => 'read',
        'capabilities' => 'local/aibridge:read',
    ),
    'local_aibridge_get_questions' => array(
        'classname'    => 'local_aibridge\external\get_questions',
        'description'  => 'Banques de questions du cours, catégories, questions, export Moodle XML.',
        'type'         => 'read',
        'capabilities' => 'local/aibridge:read',
    ),
    'local_aibridge_get_efe_competences' => array(
        'classname'    => 'local_aibridge\external\get_efe_competences',
        'description'  => 'Référentiel EFE (N1, N2, N3) quand local_efenotes est installé et configuré.',
        'type'         => 'read',
        'capabilities' => '',
    ),
);

// Service prêt à l'emploi : l'administrateur l'active, y autorise le compte
// de service et crée un jeton. Il reprend aussi quelques fonctions du cœur.
// Les fonctions d'autres plugins (local_efenotes...) passent par nos propres
// fonctions : un service ne doit lister que des fonctions qui existent.
$services = array(
    'Pont IA (Claude Code, Codex)' => array(
        'shortname'       => 'local_aibridge',
        'functions'       => array(
            'local_aibridge_get_site_info',
            'local_aibridge_list_courses',
            'local_aibridge_get_course',
            'local_aibridge_get_module',
            'local_aibridge_get_questions',
            'local_aibridge_get_efe_competences',
            'core_webservice_get_site_info',
            'core_course_get_categories',
            'core_course_search_courses',
        ),
        'restrictedusers' => 1,
        'enabled'         => 0,
        'downloadfiles'   => 1,
        'uploadfiles'     => 0,
    ),
);
