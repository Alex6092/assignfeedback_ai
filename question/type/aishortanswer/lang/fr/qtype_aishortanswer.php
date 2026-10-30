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
$string['apiurl']              = 'URL de l\'API externe';
$string['model']               = 'Modèle';
$string['apikey']              = 'Clé de l\'API externe';
$string['apiurl_override']     = 'Corriger avec une API externe (hors load balancer)';
$string['model_override']      = 'Imposer un modèle';
$string['apikey_override']     = 'Renseigner la clé de l\'API externe';
$string['apiurl_help'] = 'Par défaut, les réponses sont corrigées par le load balancer IA du lycée. Si la case est cochée et l\'URL renseignée, cette question est corrigée directement par cette API compatible OpenAI Chat Completions (ex. https://api.openai.com/v1/chat/completions), sans passer par le load balancer. Renseignez alors aussi le modèle et la clé de cette API.';
$string['model_help'] = 'Sans API externe : identifiant d\'un autre modèle du catalogue du load balancer (il doit y être déclaré). Avec une API externe : modèle de cette API (ex. gpt-4o-mini). Sans surcharge : le modèle des corrections IA du site.';
$string['apikey_help'] = 'Clé secrète de l\'API externe. Ignorée sans API externe : le load balancer utilise la clé du site. La valeur est chiffrée en base.';

// Saisie
$string['pleaseenteranswer']   = 'Veuillez saisir une réponse.';
