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
$string['error_drafttype'] = 'Type d\'objet inconnu : {$a} (cm, section, course ou questioncategory).';
$string['error_categoryforeign'] = 'La catégorie « {$a} » contient une question créée ou modifiée par un autre compte que le pont : suppression refusée.';
$string['error_categoryinuse'] = 'Des questions de la catégorie « {$a} » servent encore (test, tentatives) : supprimez d\'abord le test qui les utilise.';
$string['error_notcreated'] = 'Cet objet n\'a pas été créé par le pont : suppression refusée ({$a}).';
$string['error_draftgone'] = 'Objet introuvable, retiré du registre du pont ({$a}).';
$string['error_draftvisible'] = 'Objet visible : un enseignant l\'a publié, le pont ne le supprime pas ({$a}).';
$string['error_sectionzero'] = 'La section 0 d\'un cours ne se supprime pas.';
$string['error_sectionnotempty'] = 'La section {$a} contient encore des activités : supprimez-les d\'abord.';
$string['error_beforecmid'] = 'Position impossible : l\'activité {$a} n\'est pas dans cette section.';
$string['error_lessonpages'] = 'Pages de la leçon attendues : une liste JSON de {title, contents}, non vide.';
$string['error_lessonpage'] = 'Page {$a} de la leçon sans titre ou sans contenu.';
$string['error_lessonimages'] = 'Images de la leçon attendues : une liste JSON de {filename, content}, contenu en base64.';
$string['error_lessonimagecount'] = 'Trop d\'images pour une leçon : {$a} au plus par appel.';
$string['error_lessonimagename'] = 'Nom d\'image refusé : « {$a} » (lettres, chiffres, point, tiret et tiret bas ; extension png, jpg, jpeg, gif ou svg).';
$string['error_lessonimagetwice'] = 'Image « {$a} » envoyée deux fois : dans une leçon, chaque image a un nom unique.';
$string['error_lessonimagecontent'] = 'Image « {$a} » illisible : base64 invalide, ou contenu qui ne correspond pas à son extension.';
$string['error_lessonimagesvg'] = 'Image SVG « {$a} » refusée : elle contient du script, un gestionnaire d\'événement, un objet incorporé ou une entité XML.';
$string['error_lessonimagesize'] = 'Image « {$a->name} » trop lourde : {$a->size}, pour {$a->max} au plus.';
$string['error_lessonimagestotal'] = 'Images de la leçon trop lourdes : {$a->size} au total, pour {$a->max} au plus par appel.';
$string['error_categoryname'] = 'Nom de catégorie de questions obligatoire.';
$string['error_noquestions'] = 'Aucune question dans le fichier Moodle XML.';
$string['error_categorynotempty'] = 'La catégorie « {$a} » contient déjà des questions : choisissez une autre catégorie, ou videz celle-ci dans Moodle.';
$string['error_import'] = 'Import Moodle XML refusé : {$a}';
$string['error_quiznotempty'] = 'Ce test contient déjà des questions : le pont ne remplit qu\'un test vide.';
$string['error_quizattempts'] = 'Ce test a déjà des tentatives : sa structure ne peut plus être modifiée.';
$string['error_randomcategory'] = 'Catégorie de tirage aléatoire introuvable dans les banques du cours : {$a}.';
$string['error_randomcount'] = 'Pas assez de questions pour le tirage aléatoire ({$a}).';
$string['error_questionidnumber'] = 'Question introuvable dans les banques du cours : idnumber « {$a} ».';
$string['error_quizlayout'] = 'Structure du test attendue : {"slots": [...]} avec au moins un emplacement.';
$string['error_extras'] = 'Réglages attendus : un objet JSON (supervised, requires, minduration, avancement).';
$string['error_requires'] = 'Activité à achever introuvable dans ce cours (ou activité elle-même) : {$a}.';
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
