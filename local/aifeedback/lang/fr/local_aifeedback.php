<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Correction IA (bibliothèque partagée)';
$string['privacy:metadata'] = 'Le plugin local_aifeedback ne stocke aucune donnée personnelle directement. Il transmet à un LLM externe les contenus que lui passent les plugins consommateurs (assignfeedback_ai, qtype_aiessay, ...).';

$string['taskname'] = 'Génération IA (file partagée)';

// API
$string['api_heading']              = 'API LLM';
$string['apiurl']                   = 'URL de l\'API';
$string['apiurl_help']              = 'Point d\'accès compatible OpenAI Chat Completions. Défaut : http://localhost:1234/v1/chat/completions';
$string['model']                    = 'Nom du modèle';
$string['model_help']               = 'Identifiant du modèle utilisé dans les appels API, ex. qwen3.5-9b-instruct.';
$string['apikey']                   = 'Clé API';
$string['apikey_help']              = 'Clé secrète envoyée dans l\'en-tête HTTP "Authorization: Bearer ...". Laisser vide si le serveur n\'en demande pas (cas par défaut de LM Studio). La valeur est chiffrée en base.';
$string['defaultsystemprompt']      = 'Prompt système par défaut';
$string['defaultsystemprompt_help'] = 'Utilisé quand aucun prompt n\'est défini au niveau du devoir ou de la question.';

// Accessibilité
$string['accessibility_heading']    = 'Accessibilité';
$string['spelling_tolerance']       = 'Tolérance orthographique (élèves dys)';
$string['spelling_tolerance_help']  = 'Si activé, l\'IA reçoit la consigne de ne jamais évaluer l\'orthographe, la grammaire ni la syntaxe, et de noter uniquement le fond (concepts, raisonnement, connaissances). Recommandé pour ne pas pénaliser les élèves présentant des troubles dys. S\'applique à toutes les corrections IA (devoirs et questions de quiz). Activé par défaut.';

// Vision
$string['vision_heading']               = 'Vision (lecture d\'images)';
$string['vision_enabled']               = 'Activer l\'envoi d\'images au modèle';
$string['vision_enabled_help']          = 'Si activé, les images contenues dans les soumissions et les réponses (PDF avec illustrations, images embarquées, fichiers PNG/JPEG joints, etc.) sont envoyées au LLM avec le texte. Nécessite un modèle multimodal.';
$string['maximagespersubmission']       = 'Nombre maximum d\'images par soumission/réponse';
$string['maximagespersubmission_help']  = 'Plafond global d\'images envoyées au LLM. Permet de contrôler la consommation de contexte et le temps de génération. Défaut : 5.';
$string['imagemindimension']            = 'Taille minimale d\'image (pixels)';
$string['imagemindimension_help']       = 'Les images extraites d\'un PDF dont la largeur OU la hauteur est inférieure à cette valeur sont ignorées (filtre les vignettes décoratives). Défaut : 200.';

// Binaires
$string['binaries_heading']    = 'Binaires externes (poppler-utils)';
$string['pdftotextpath']       = 'Chemin du binaire pdftotext';
$string['pdftotextpath_help']  = 'Chemin absolu vers l\'exécutable pdftotext (paquet poppler-utils). Laisser vide pour une détection automatique.';
$string['pdftoppmpath']        = 'Chemin du binaire pdftoppm';
$string['pdftoppmpath_help']   = 'Chemin absolu vers l\'exécutable pdftoppm (poppler-utils), utilisé pour rasteriser les pages PDF en images. Laisser vide pour détection auto.';
$string['pdfimagespath']       = 'Chemin du binaire pdfimages';
$string['pdfimagespath_help']  = 'Chemin absolu vers l\'exécutable pdfimages (poppler-utils), utilisé pour détecter les pages contenant des images. Laisser vide pour détection auto.';
$string['pdftotextmissing']    = 'Le binaire pdftotext est introuvable sur le serveur. Installez le paquet poppler-utils ou renseignez le chemin dans les réglages du plugin.';
$string['pdftotexterror']      = 'Erreur d\'extraction du texte PDF.';

// Quiz / questions
$string['quiz_heading']                  = 'Questions IA (quiz)';
$string['max_attempts_to_grade']         = 'Tentatives max corrigées par l\'IA';
$string['max_attempts_to_grade_help']    = 'Nombre maximum de corrections IA par étudiant et par question, pour un même quiz. Au-delà, la question reste en attente d\'une correction manuelle. <strong>0 = illimité (recommandé)</strong> : dans ce cas, utilisez plutôt le réglage natif « Délai imposé entre les tentatives » du quiz pour limiter la sollicitation du LLM. Défaut : 0.';

// Relance d'une correction
$string['retry_button']         = 'Relancer la correction IA';
$string['retry_queued']         = 'Correction IA relancée — elle sera traitée sous peu (à la prochaine exécution du cron).';
$string['retry_notfound']       = 'Ligne de correction introuvable.';
$string['retry_pagetitle']      = 'Relancer une correction IA';
$string['retry_confirm_body']   = 'Confirmer la relance de la correction IA pour cette copie. Elle sera remise en file d\'attente et traitée à la prochaine exécution du cron.';
$string['retry_confirm_button'] = 'Confirmer la relance';
$string['retry_lasterror']      = 'Dernière erreur :';

// Correction des questions (base partagée quiz_grader / feedback_card)
$string['emptyresponse']          = '(Aucune réponse fournie)';
$string['questionattemptmissing'] = 'La tentative de question liée à ce job IA est introuvable.';

// Carte de feedback
$string['feedback_pending']  = 'Le feedback IA est en cours de génération. Reviens consulter cette tentative dans quelques instants.';
$string['feedback_failed']   = 'La génération du feedback IA a échoué. Un enseignant doit examiner cette copie manuellement.';
$string['feedback_error']    = 'Erreur de lecture du feedback IA.';
$string['strengths']         = 'Points forts';
$string['improvements']      = 'Points à améliorer';
$string['detailedfeedback']  = 'Feedback détaillé';
$string['competency_scores'] = 'Scores par compétence';
$string['competency']        = 'Compétence';
$string['mastery_level']     = 'Niveau de maîtrise';
$string['commentary']        = 'Commentaire';

// Erreurs
$string['apicallfailed'] = 'L\'appel à l\'API IA a échoué.';
