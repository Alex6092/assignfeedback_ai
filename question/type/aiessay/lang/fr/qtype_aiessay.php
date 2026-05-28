<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']          = 'Composition (correction IA)';
$string['pluginname_help']     = 'En réponse à une question (qui peut inclure une image ou un fichier), l\'étudiant rédige une réponse longue. La note et le feedback sont produits automatiquement par un LLM local via le plugin partagé Correction IA.';
$string['pluginnameadding']    = 'Ajout d\'une question Composition IA';
$string['pluginnameediting']   = 'Modification d\'une question Composition IA';
$string['pluginnamesummary']   = 'Question de type composition longue, corrigée automatiquement par un LLM (texte uniquement pour l\'instant, vision en option).';
$string['privacy:metadata']    = 'Les questions du type Composition IA transmettent le texte de la réponse de l\'étudiant à un LLM externe via le plugin local_aifeedback, et stockent le feedback retourné en base.';

// Form
$string['responseoptions']         = 'Options de réponse';
$string['responseformat']          = 'Format de la réponse';
$string['responserequired']        = 'Texte de réponse';
$string['responseisrequired']      = 'Le texte de réponse est requis';
$string['responsenotrequired']     = 'Le texte de réponse est optionnel';
$string['responsefieldlines']      = 'Taille de la zone de saisie';
$string['minwordlimit']            = 'Nombre minimum de mots';
$string['maxwordlimit']            = 'Nombre maximum de mots';
$string['maxlessthanmin']          = 'Le maximum doit être supérieur ou égal au minimum.';
$string['allowattachments']        = 'Pièces jointes autorisées';
$string['attachmentsrequired']     = 'Pièces jointes requises';
$string['attachmentsoptional']     = 'Aucune (optionnelles)';
$string['unlimited']               = 'Illimitées';
$string['formateditor']            = 'Éditeur HTML';
$string['formateditorfilepicker']  = 'Éditeur HTML avec gestionnaire de fichiers';
$string['formatplain']             = 'Texte brut';
$string['formatmonospaced']        = 'Texte brut, police à chasse fixe';
$string['formatnoinline']          = 'Aucune saisie inline (que des pièces jointes)';

$string['aiconfig_heading']        = 'Configuration de la correction IA';
$string['aioverrides_heading']     = 'Remplacements de la config globale';
$string['systemprompt']            = 'Prompt système';
$string['systemprompt_help']       = 'Instructions envoyées au LLM en rôle « system ». Laisser vide pour utiliser le prompt par défaut du site.';
$string['expectedanswer']          = 'Corrigé attendu / barème';
$string['expectedanswer_help']     = 'Réponse de référence ou éléments-clés que l\'IA utilisera pour évaluer la copie.';
$string['competencies']            = 'Compétences évaluées';
$string['competencies_help']       = 'Compétences ou critères que l\'IA doit évaluer, une par ligne.';
$string['apiurl']                  = 'URL de l\'API';
$string['model']                   = 'Nom du modèle';
$string['apikey']                  = 'Clé API';
$string['vision_enabled']          = 'Activer l\'envoi d\'images au modèle';
$string['apiurl_override']         = 'Remplacer l\'URL de l\'API du site';
$string['model_override']          = 'Remplacer le nom du modèle du site';
$string['apikey_override']         = 'Remplacer la clé API du site';
$string['vision_enabled_override'] = 'Remplacer le réglage Vision du site';

// Renderer
$string['attachments']             = 'Pièces jointes';
$string['pleaseinputatleastsomething'] = 'Saisissez du texte ou ajoutez au moins une pièce jointe.';
$string['emptyresponse']           = '(Aucune réponse fournie)';
$string['feedback_pending']        = 'Le feedback IA est en cours de génération. Reviens consulter cette tentative dans quelques instants.';
$string['feedback_failed']         = 'La génération du feedback IA a échoué. Un enseignant doit examiner cette copie manuellement.';
$string['feedback_error']          = 'Erreur de lecture du feedback IA.';
$string['strengths']               = 'Points forts';
$string['improvements']            = 'Points à améliorer';
$string['detailedfeedback']        = 'Feedback détaillé';
$string['competency_scores']       = 'Scores par compétence';
$string['competency']              = 'Compétence';
$string['mastery_level']           = 'Niveau de maîtrise';
$string['commentary']              = 'Commentaire';

// Erreurs internes
$string['questionattemptmissing']  = 'La tentative de question liée à ce job IA est introuvable.';
