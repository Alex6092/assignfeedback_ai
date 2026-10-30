<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Correction IA (bibliothèque partagée)';
// Confidentialité
$string['privacy:metadata:qgrading']             = 'Corrections IA des questions de quiz.';
$string['privacy:metadata:qgrading:userid']      = 'L\'utilisateur dont la réponse est corrigée.';
$string['privacy:metadata:qgrading:status']      = 'État de la correction (en attente, générée, échec).';
$string['privacy:metadata:qgrading:aifeedback']  = 'Le feedback produit par l\'IA pour cette réponse.';
$string['privacy:metadata:qgrading:mark']        = 'La note attribuée par l\'IA.';
$string['privacy:metadata:qgrading:timecreated'] = 'Date de création de la correction.';
$string['privacy:metadata:job']                  = 'Suivi des générations confiées au load balancer d\'inférence.';
$string['privacy:metadata:job:userid']           = 'L\'utilisateur pour qui la génération est faite.';
$string['privacy:metadata:job:component']        = 'Le plugin à l\'origine de la demande.';
$string['privacy:metadata:job:model']            = 'Le modèle utilisé.';
$string['privacy:metadata:job:status']           = 'État du job (envoyé, reçu, terminé, échec).';
$string['privacy:metadata:job:result']           = 'La réponse du modèle, conservée seulement le temps de l\'appliquer.';
$string['privacy:metadata:job:timecreated']      = 'Date de la demande.';
$string['privacy:metadata:llm']                  = 'Les contenus transmis pour analyse au load balancer d\'inférence du lycée (ou à une API externe imposée par une activité).';
$string['privacy:metadata:llm:content']          = 'Le contenu envoyé au modèle (réponse d\'élève, question, message de conversation).';
$string['privacy:path:qgrading']                 = 'Corrections IA';
$string['privacy:path:jobs']                     = 'Générations IA';

$string['taskname'] = 'Génération IA (file partagée)';
// Serveur IA (load balancer d'inférence)
$string['lb_heading']               = 'Serveur IA (load balancer)';
$string['lb_heading_desc']          = 'Toutes les générations IA (corrections, tuteur, génération de quiz, missions…) sont confiées au load balancer d\'inférence, qui les met en file et les répartit entre les serveurs IA. Seules les activités configurées avec leur propre URL (API externe) l\'appellent directement. Dans l\'administration du load balancer, créez une application pour ce Moodle avec l\'URL de base de callback <code>{$a->base}</code>, puis une clé d\'API autorisée en modes async, stream et sync. Les résultats arrivent sur <code>{$a->callback}</code> : le load balancer doit pouvoir joindre cette adresse.';
$string['lb_url']                   = 'URL du load balancer';
$string['lb_url_help']              = 'Adresse de base du service, sans « /v1 », ex. https://ia.mon-lycee.fr.';
$string['lb_apikey']                = 'Clé d\'API de l\'application';
$string['lb_apikey_help']           = 'Clé créée dans l\'administration du load balancer pour l\'application Moodle (affichée une seule fois). Envoyée dans l\'en-tête « Authorization: Bearer … ». La valeur est chiffrée en base.';
$string['lb_callbacksecret']        = 'Secret de signature des callbacks';
$string['lb_callbacksecret_help']   = 'Secret de callback de l\'application, fourni par le load balancer à la création de l\'application (ou après « rotation »). Il sert à vérifier que les résultats reçus viennent bien du load balancer. La valeur est chiffrée en base.';
$string['lb_callbackurl']           = 'URL de callback (facultatif)';
$string['lb_callbackurl_help']      = 'Adresse à laquelle le load balancer envoie les résultats. Laisser vide pour utiliser {$a}. À renseigner seulement si le load balancer joint Moodle par une autre adresse (réseau interne, par exemple).';
$string['model_feedback']           = 'Modèle des corrections IA';
$string['model_feedback_help']      = 'Modèle du catalogue du load balancer utilisé pour corriger les devoirs et les questions IA des quiz. C\'est aussi le modèle utilisé par les autres plugins IA (génération de quiz, missions, modération du tuteur…) qui n\'ont pas de modèle propre.';
$string['defaultsystemprompt']      = 'Prompt système par défaut';
$string['defaultsystemprompt_help'] = 'Utilisé quand aucun prompt n\'est défini au niveau du devoir ou de la question.';
$string['lb_notconfigured']         = 'Le load balancer IA n\'est pas configuré (URL et clé d\'API, dans les réglages de local_aifeedback).';
$string['lb_nomodel']               = 'Aucun modèle n\'est configuré pour cette génération (réglage « Modèle des corrections IA » de local_aifeedback).';
$string['lb_error']                 = 'Serveur IA : {$a}';
$string['lb_jobfailed']             = 'Génération {$a->status} sur le load balancer : {$a->message}';
$string['lbmodel_choose']           = 'Choisir un modèle…';
$string['lbmodel_missing']          = '{$a} (absent du catalogue du load balancer)';
$string['lbmodel_unavailable']      = 'Catalogue des modèles indisponible ({$a}) : saisissez l\'identifiant exact du modèle.';
$string['lbmodel_label']            = '{$a->id}{$a->vision} · contexte {$a->context} · sortie max {$a->output}';
$string['lbmodel_vision']           = 'vision';
$string['lbmodel_default']          = 'Modèle des corrections IA (par défaut)';
$string['task_process']             = 'Application d\'un résultat IA reçu';
$string['task_reconcile']           = 'Réconciliation des jobs IA avec le load balancer';

// Usages des modèles (page d'état)
$string['usage_feedback']           = 'corrections';
$string['usage_tutor']              = 'tuteur';
$string['usage_moderation']         = 'modération / brief';
$string['usage_quizgen']            = 'génération de quiz';
$string['usage_missions']           = 'missions';
$string['usage_assignoverride']     = '{$a} devoir(s)';
$string['usage_questionoverride']   = '{$a} question(s)';

// Statuts des jobs
$string['jobstatus_submitting']     = 'Envoi en cours';
$string['jobstatus_submitted']      = 'Au load balancer';
$string['jobstatus_received']       = 'Reçu';
$string['jobstatus_processing']     = 'Application';
$string['jobstatus_done']           = 'Terminé';
$string['jobstatus_failed']         = 'Échec';
$string['jobstatus_cancelled']      = 'Annulé';

// Page d'état du serveur IA
$string['servers_link']             = 'Voir l\'état du serveur IA.';
$string['servers_page']             = 'État du serveur IA';
$string['servers_intro']            = 'Ce que le load balancer d\'inférence indique par son API, et les jobs Moodle en cours. La page s\'actualise toutes les 15 secondes.';
$string['servers_notconfigured']    = 'Le load balancer n\'est pas configuré. Renseignez son URL, la clé d\'API et le secret de callback dans les <a href="{$a}">réglages</a>.';
$string['servers_health']           = 'Connexion';
$string['servers_reachable']        = 'Service joignable';
$string['servers_unreachable']      = 'Service injoignable';
$string['servers_release']          = 'version {$a}';
$string['servers_authok']           = 'Clé d\'API acceptée';
$string['servers_autherror']        = 'Clé d\'API refusée';
$string['servers_auth401']          = 'clé absente, inconnue ou désactivée.';
$string['servers_auth403']          = 'droit insuffisant pour cette clé.';
$string['servers_auth403ip']        = 'l\'adresse IP de ce serveur Moodle n\'est pas autorisée pour cette clé.';
$string['servers_lbadmin']          = 'Ouvrir l\'administration du load balancer (détail par serveur IA, jobs, quotas par élève)';
$string['servers_capacity']         = 'Capacité et occupation par modèle';
$string['servers_capacity_note']    = 'Chiffres agrégés par modèle sur les serveurs IA actifs. Une place occupée est une génération en cours, ou une génération interrompue dont l\'arrêt n\'est pas encore confirmé.';
$string['servers_noworker']         = 'aucun serveur disponible';
$string['servers_workers_value']    = '{$a->available} / {$a->total} disponible(s)';
$string['servers_busy_value']       = '{$a->occupied} / {$a->capacity}';
$string['servers_catalog']          = 'Catalogue des modèles';
$string['servers_modelmissing']     = 'Le modèle « {$a->model} » est configuré dans Moodle ({$a->usages}) mais absent du catalogue du load balancer : les générations correspondantes seront refusées.';
$string['servers_usage']            = 'Consommation de l\'application';
$string['servers_nousage']          = 'Aucune consommation sur les 7 derniers jours.';
$string['servers_tokens_value']     = '{$a->total} ({$a->prompt} en entrée, {$a->completion} en sortie)';
$string['servers_quotas']           = 'Budgets restants :';
$string['servers_noquota']          = 'Aucun quota de tokens actif pour l\'application ni pour la clé.';
$string['servers_quota_credit']     = '{$a->scope} : {$a->available} / {$a->limit} tokens de crédit';
$string['servers_quota_window']     = '{$a->scope} : {$a->available} / {$a->limit} tokens sur {$a->hours} h';
$string['servers_scope_application'] = 'application';
$string['servers_scope_key']        = 'clé';
$string['servers_moodle']           = 'Jobs Moodle (24 dernières heures)';
$string['servers_nojobs']           = 'Aucun job soumis au load balancer depuis 24 heures.';
$string['servers_moodletokens']     = 'Tokens consommés par ces jobs : {$a->prompt} en entrée, {$a->completion} en sortie.';
$string['servers_active']           = 'Jobs en cours (les plus anciens)';
$string['servers_errors']           = 'Dernières erreurs';
$string['servers_attempt']          = 'envoi n° {$a}';
$string['servers_reconcile']        = 'Relancer la réconciliation';
$string['servers_reconcile_help']   = 'Interroge le load balancer pour les jobs sans nouvelles depuis plus de 10 minutes, applique les résultats en attente et remet en file les travaux orphelins. La tâche planifiée le fait automatiquement toutes les 5 minutes.';
$string['servers_reconciled']       = 'Réconciliation effectuée.';
$string['servers_col_model']        = 'Modèle';
$string['servers_col_usages']       = 'Usages dans Moodle';
$string['servers_col_workers']      = 'Serveurs IA';
$string['servers_col_busy']         = 'Places occupées';
$string['servers_col_vision']       = 'Vision';
$string['servers_col_context']      = 'Contexte (tokens)';
$string['servers_col_output']       = 'Sortie max (tokens)';
$string['servers_col_tokens24h']    = 'Tokens 24 h';
$string['servers_col_tokens7d']     = 'Tokens 7 jours';
$string['servers_col_attempts7d']   = 'Générations 7 jours';
$string['servers_col_component']    = 'Plugin';
$string['servers_col_state']        = 'État';
$string['servers_col_lbjob']        = 'Job load balancer';
$string['servers_col_since']        = 'Depuis';
$string['servers_col_when']         = 'Date';
$string['servers_col_error']        = 'Erreur';

$string['reasoning_budget']          = 'Budget de réflexion (tokens)';
$string['reasoning_budget_help']     = 'Pour les modèles à raisonnement (Qwen3, gpt-oss, DeepSeek-R1, Magistral…). Leur réflexion est décomptée du même nombre maximal de tokens que la réponse, calibré pour la réponse seule (700 pour le tuteur, 2048 pour la correction) : la réflexion l\'épuise et la réponse revient vide. Ce nombre est ajouté au maximum de chaque appel (correction, tuteur, génération de questions, Missions…) ; avec une valeur supérieure à 0, le délai des appels non diffusés passe aussi de 3 à 10 minutes. 0 = aucun supplément (modèles sans raisonnement). Conseillé : 4096 à 8192. La réflexion n\'est jamais montrée ni enregistrée. Avec vLLM, le contexte du modèle doit pouvoir contenir le prompt plus ce maximum.';

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
$string['imagemaxdimension']            = 'Taille maximale d\'image envoyée (pixels)';
$string['imagemaxdimension_help']       = 'Les images envoyées au LLM (fichiers joints, images de l\'éditeur, images dans un ZIP, pages PDF rasterisées, images de leçon) dont le plus grand côté dépasse cette valeur sont réduites et ré-encodées en JPEG avant envoi. Réduit les tokens consommés et évite le plantage de certains backends (Gemma sous llama.cpp exige que l\'image entière tienne dans un lot d\'évaluation). 0 = envoyer les images en taille originale. Défaut : 1024.';

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
$string['retry_queued']         = 'Correction IA relancée — elle sera traitée sous peu.';
$string['retry_notfound']       = 'Ligne de correction introuvable.';
$string['retry_pagetitle']      = 'Relancer une correction IA';
$string['retry_confirm_body']   = 'Confirmer la relance de la correction IA pour cette copie. Elle sera remise en file d\'attente sur le serveur IA et traitée sous peu.';
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
