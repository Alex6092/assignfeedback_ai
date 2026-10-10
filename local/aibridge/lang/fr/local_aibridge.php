<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Pont IA (Claude Code, Codex)';

// Capacités.
$string['aibridge:read'] = 'Lire un cours par le pont IA (réglages, contenus, corrigés)';
$string['aibridge:write'] = 'Créer et modifier des cours et des activités par le pont IA (bac à sable)';

// Réglages.
$string['settings_intro'] = 'Le pont permet à un agent IA (Claude Code, Codex), via le serveur MCP local <code>tools/moodle-mcp</code>, de lire un cours dans le détail, réglages des plugins compris. Il ne renvoie jamais de donnée nominative d\'élève ni de secret. Pour l\'utiliser : activez les services web et le protocole REST, activez le service « Pont IA (Claude Code, Codex) », autorisez-y un compte de service et créez-lui un jeton. Chaque appel est inscrit au <a href="{$a}">journal du pont</a>.';
$string['setting_enablewrites'] = 'Autoriser l\'écriture';
$string['setting_enablewrites_desc'] = 'Création de cours et d\'activités par le pont, uniquement dans les catégories bac à sable ci-dessous. Tout ce qui est créé reste masqué jusqu\'à ce qu\'un enseignant le publie.';
$string['setting_sandboxcategories'] = 'Catégories bac à sable';
$string['setting_sandboxcategories_desc'] = 'Les seules catégories (et leurs sous-catégories) où le pont peut créer ou modifier quelque chose.';
$string['setting_logretentiondays'] = 'Conservation du journal (jours)';
$string['setting_logretentiondays_desc'] = 'Les appels plus anciens sont supprimés chaque nuit. 0 : conservation illimitée.';

// Journal.
$string['log_title'] = 'Journal du pont IA';
$string['log_time'] = 'Date';
$string['log_user'] = 'Compte';
$string['log_function'] = 'Fonction';
$string['log_target'] = 'Cible';
$string['log_status'] = 'Résultat';
$string['log_duration'] = 'Durée';
$string['log_params'] = 'Paramètres';
$string['log_course'] = 'cours {$a}';
$string['log_all'] = 'Tous les cours';
$string['log_empty'] = 'Aucun appel enregistré.';

// Tâches.
$string['task_purge_log'] = 'Nettoyage du journal du pont IA';

// Erreurs.
$string['error_json'] = 'Réponse impossible à encoder en JSON : {$a}';
$string['error_export'] = 'Export Moodle XML impossible.';

// Vie privée.
$string['privacy:metadata:log'] = 'Journal des appels au pont IA.';
$string['privacy:metadata:log:userid'] = 'Compte qui a appelé le pont.';
$string['privacy:metadata:log:function'] = 'Fonction appelée.';
$string['privacy:metadata:log:courseid'] = 'Cours visé.';
$string['privacy:metadata:log:params'] = 'Paramètres de l\'appel.';
$string['privacy:metadata:log:timecreated'] = 'Date de l\'appel.';
$string['privacy:metadata:created'] = 'Objets créés par le pont IA.';
$string['privacy:metadata:created:userid'] = 'Compte qui a créé l\'objet.';
$string['privacy:metadata:created:objecttype'] = 'Type d\'objet créé.';
$string['privacy:metadata:created:timecreated'] = 'Date de création.';
