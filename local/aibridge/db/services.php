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

    // Écriture : seulement si l'administrateur l'a activée, dans les catégories
    // bac à sable, et tout est créé masqué (voir classes/writer/guard.php).
    'local_aibridge_create_course' => array(
        'classname'    => 'local_aibridge\external\create_course',
        'description'  => 'Crée un cours masqué dans une catégorie bac à sable.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, moodle/course:create',
    ),
    'local_aibridge_upsert_section' => array(
        'classname'    => 'local_aibridge\external\upsert_section',
        'description'  => 'Crée une section si elle manque (masquée), règle son nom et son résumé.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, moodle/course:update',
    ),
    'local_aibridge_create_module' => array(
        'classname'    => 'local_aibridge\external\create_module',
        'description'  => 'Crée une activité masquée (page, étiquette, URL, leçon, test, devoir) avec ses réglages.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, moodle/course:manageactivities',
    ),
    'local_aibridge_set_lesson_pages' => array(
        'classname'    => 'local_aibridge\external\set_lesson_pages',
        'description'  => 'Remplace les pages de contenu d\'une leçon créée par le pont (encore masquée).',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, moodle/course:manageactivities',
    ),
    'local_aibridge_import_questions' => array(
        'classname'    => 'local_aibridge\external\import_questions',
        'description'  => 'Importe des questions Moodle XML dans une catégorie de la banque du cours.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, moodle/course:manageactivities',
    ),
    'local_aibridge_set_quiz_layout' => array(
        'classname'    => 'local_aibridge\external\set_quiz_layout',
        'description'  => 'Remplit un test vide créé par le pont : questions fixes et tirages aléatoires.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, mod/quiz:manage',
    ),
    'local_aibridge_set_module_extras' => array(
        'classname'    => 'local_aibridge\external\set_module_extras',
        'description'  => 'Activité surveillée, durée minimale et Avancement d\'une activité créée par le pont.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write, moodle/course:manageactivities',
    ),
    'local_aibridge_delete_draft' => array(
        'classname'    => 'local_aibridge\external\delete_draft',
        'description'  => 'Supprime un objet créé par le pont et encore masqué.',
        'type'         => 'write',
        'capabilities' => 'local/aibridge:write',
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
            'local_aibridge_create_course',
            'local_aibridge_upsert_section',
            'local_aibridge_create_module',
            'local_aibridge_set_lesson_pages',
            'local_aibridge_import_questions',
            'local_aibridge_set_quiz_layout',
            'local_aibridge_set_module_extras',
            'local_aibridge_delete_draft',
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
