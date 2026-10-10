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
$string['error_writesdisabled'] = 'L\'écriture par le pont est désactivée (réglage « Autoriser l\'écriture » du pont).';
$string['error_notsandbox'] = 'La catégorie {$a} n\'est pas un bac à sable du pont : écriture refusée.';
$string['error_coursenames'] = 'Le nom complet et le nom abrégé du cours sont obligatoires.';
$string['error_sectionnumber'] = 'Numéro de section invalide : {$a}.';
$string['error_modname'] = 'Type d\'activité non pris en charge par le pont : {$a}.';
$string['error_settings'] = 'Les réglages doivent être un objet JSON (champs nommés du formulaire de l\'activité).';
$string['error_date'] = 'Date invalide (format attendu AAAA-MM-JJTHH:MM) : {$a}.';
$string['error_scale'] = 'Barème introuvable : {$a}.';
$string['error_efenocompetence'] = 'Report EFE activé sans compétence : au moins un code est obligatoire.';
$string['error_efecode'] = 'Code EFE refusé : {$a} (niveau N2 au minimum, par exemple C04.3 ou C04.3.D4.T4).';
$string['error_drafttype'] = 'Type d\'objet inconnu : {$a} (cm, section ou course).';
$string['error_notcreated'] = 'Cet objet n\'a pas été créé par le pont : suppression refusée ({$a}).';
$string['error_draftgone'] = 'Objet introuvable, retiré du registre du pont ({$a}).';
$string['error_draftvisible'] = 'Objet visible : un enseignant l\'a publié, le pont ne le supprime pas ({$a}).';
$string['error_sectionzero'] = 'La section 0 d\'un cours ne se supprime pas.';
$string['error_sectionnotempty'] = 'La section {$a} contient encore des activités : supprimez-les d\'abord.';
$string['error_coursenotdraft'] = 'Le cours contient une activité publiée ou qui ne vient pas du pont (activité {$a}) : suppression refusée.';

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
