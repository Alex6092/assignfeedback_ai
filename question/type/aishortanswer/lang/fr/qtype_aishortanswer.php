<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']        = 'Réponse courte (correction IA)';
$string['pluginname_help']   = 'L\'étudiant saisit une réponse brève (un mot, une définition, une phrase). La note et un feedback court sont produits automatiquement par un LLM local via la bibliothèque partagée Correction IA.';
$string['pluginnameadding']  = 'Ajout d\'une question Réponse courte IA';
$string['pluginnameediting'] = 'Modification d\'une question Réponse courte IA';
$string['pluginnamesummary'] = 'Question à réponse courte, corrigée automatiquement par un LLM avec un feedback bref.';
$string['privacy:metadata']  = 'Les questions Réponse courte IA transmettent le texte de la réponse de l\'étudiant à un LLM externe via le plugin local_aifeedback, et stockent le feedback retourné en base.';

// Formulaire
$string['responseoptions']      = 'Options de réponse';
$string['responsefieldlines']   = 'Taille de la zone de saisie';
$string['responsefieldlines_help'] = 'Nombre de lignes de la zone de réponse. Choisir 1 affiche un champ texte sur une seule ligne (idéal pour un mot ou une formule) ; une valeur supérieure affiche une zone de texte de plusieurs lignes.';

$string['aiconfig_heading']    = 'Configuration de la correction IA';
$string['aioverrides_heading'] = 'Remplacements de la config globale';
$string['systemprompt']        = 'Prompt système';
$string['systemprompt_help']   = 'Instructions envoyées au LLM en rôle « system ». Laisser vide pour utiliser le prompt par défaut (qui impose déjà un feedback court).';
$string['expectedanswer']      = 'Corrigé attendu';
$string['expectedanswer_help'] = 'Réponse de référence à laquelle l\'IA comparera la réponse de l\'étudiant.';
$string['apiurl']              = 'URL de l\'API';
$string['model']               = 'Nom du modèle';
$string['apikey']              = 'Clé API';
$string['apiurl_override']     = 'Remplacer l\'URL de l\'API du site';
$string['model_override']      = 'Remplacer le nom du modèle du site';
$string['apikey_override']     = 'Remplacer la clé API du site';

// Saisie
$string['pleaseenteranswer']   = 'Veuillez saisir une réponse.';
