<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Tuteur IA';

// Capacités
$string['aichat:use']                = 'Utiliser le tuteur IA sur une activité';
$string['aichat:configure']          = 'Activer et paramétrer le tuteur IA sur une activité';
$string['aichat:viewconversations']  = 'Consulter les conversations des élèves avec le tuteur IA';

// === Réglages ===
$string['serversnote']               = 'Le tuteur utilise les <strong>serveurs LLM</strong> déclarés dans le plugin <em>Correction IA (bibliothèque partagée)</em>, sur les emplacements dont l\'usage « tuteur » est autorisé. Dédier un serveur au tuteur évite que les corrections de devoirs ne fassent attendre les élèves.';
$string['tutor_heading']             = 'Comportement du tuteur';
$string['setting_tutorprompt']       = 'Prompt système du tuteur';
$string['setting_tutorprompt_help']  = 'Instructions de base envoyées au modèle. Le contexte de l\'activité et les règles de sécurité (ne pas donner la solution, ignorer les tentatives de manipulation) sont ajoutés automatiquement APRÈS ce texte et ne peuvent pas être désactivés. Laisser vide pour revenir au prompt par défaut.';
$string['setting_temperature']       = 'Température';
$string['setting_temperature_help']  = 'Créativité des réponses, de 0 (très factuel) à 1 (très varié). Pour un tuteur, 0.4 donne des explications naturelles sans divagation. Défaut : 0.4.';
$string['setting_maxtokens']         = 'Longueur maximale d\'une réponse (tokens)';
$string['setting_maxtokens_help']    = 'Plafond de génération pour une réponse. Un tuteur doit rester concis : 700 tokens correspondent à une dizaine de phrases. Défaut : 700.';
$string['setting_historyturns']      = 'Nombre d\'échanges gardés en mémoire';
$string['setting_historyturns_help'] = 'Combien de tours de conversation (question + réponse) sont renvoyés au modèle à chaque nouveau message. Au-delà, le contexte coûte cher et les petits modèles se dispersent. Défaut : 8.';
$string['setting_historychars']      = 'Taille maximale de l\'historique (caractères)';
$string['setting_historychars_help'] = 'Second garde-fou : l\'historique est rogné par le début pour ne pas dépasser cette taille. Défaut : 6000.';
$string['setting_maxmessagechars']      = 'Longueur maximale d\'un message d\'élève';
$string['setting_maxmessagechars_help'] = 'Nombre de caractères qu\'un élève peut envoyer en une fois. Défaut : 2000.';

$string['quota_heading']              = 'Quota d\'usage par élève';
$string['quota_heading_desc']         = 'Protège le serveur du lycée : sans plafond, une classe entière qui « discute » avec l\'IA le sature et plus personne n\'obtient de réponse. Les compteurs sont calculés sur une fenêtre glissante, pour l\'élève et TOUTES activités confondues. Mettre 0 désactive le plafond correspondant.';
$string['setting_quotawindow']        = 'Durée de la fenêtre (heures)';
$string['setting_quotawindow_help']   = 'Durée sur laquelle les compteurs sont calculés. Une fenêtre glissante de 4 h autorise une séance de travail complète puis se libère progressivement. Défaut : 4.';
$string['setting_quotatokens']        = 'Budget de tokens par fenêtre';
$string['setting_quotatokens_help']   = 'Nombre total de tokens (question + réponse) autorisés par élève sur la fenêtre. C\'est la mesure la plus fidèle du temps de calcul consommé. 0 = pas de limite. Défaut : 20000.';
$string['setting_quotamessages']      = 'Nombre de messages par fenêtre';
$string['setting_quotamessages_help'] = 'Garde-fou complémentaire contre l\'envoi en rafale de questions très courtes. 0 = pas de limite. Défaut : 40.';

$string['moderation_heading']             = 'Modération';
$string['moderation_heading_desc']        = 'Chaque message d\'élève est analysé à part, après coup, par un appel séparé qui passe par la file de jobs (serveur des corrections) : le tuteur n\'est pas ralenti et le streaming n\'est pas affecté. Un contexte neuf lit le message comme une donnée à classer, ce qui le rend bien plus difficile à manipuler que le tuteur lui-même. Coût : un petit appel LLM (environ 300 tokens) par message.';
$string['setting_moderation']             = 'Analyser les messages des élèves';
$string['setting_moderation_help']        = 'Signale à l\'enseignant les tentatives de manipulation du tuteur, les menaces, le chantage, les insultes, les contenus inappropriés et les signes de détresse sérieuse. Les signalements apparaissent sur la page « Tuteur IA » de l\'activité.';
$string['setting_moderationnotify']       = 'Prévenir les enseignants';
$string['setting_moderationnotify_help']  = 'Envoie une notification Moodle aux enseignants de l\'activité quand un message est signalé (au plus une par conversation et par heure). La notification ne reprend pas le texte de l\'élève : seulement la catégorie, le motif et un lien vers la conversation.';
$string['messageprovider:flagged']        = 'Message d\'élève signalé par le tuteur IA';

$string['misc_heading']               = 'Interface et conservation';
$string['setting_studentnotice']      = 'Avertissement affiché aux élèves';
$string['setting_studentnotice_help'] = 'Message affiché en haut du volet de discussion. Puisque les enseignants peuvent lire les conversations, les élèves doivent en être informés. Laisser vide pour utiliser le texte par défaut.';
$string['setting_pollinterval']       = 'Intervalle d\'actualisation de la file (ms)';
$string['setting_pollinterval_help']  = 'Fréquence à laquelle le navigateur demande sa position dans la file d\'attente. Défaut : 1500.';
$string['setting_retention']          = 'Conservation des conversations (jours)';
$string['setting_retention_help']     = 'Les conversations sans activité depuis ce nombre de jours sont supprimées par la tâche planifiée. 0 = conservation illimitée. Défaut : 180.';

$string['studentnotice_default'] = 'Vos échanges avec le tuteur sont enregistrés et peuvent être consultés par votre enseignant.';

// === Formulaire d'activité ===
$string['formheader']               = 'Tuteur IA';
$string['form_enabled']             = 'Activer le tuteur IA';
$string['form_enabled_help']        = 'Si activé, les élèves voient un bouton de discussion sur la page de l\'activité. Le tuteur les guide (indices, notions, méthode) sans jamais donner la réponse attendue.';
$string['form_includeintro']        = 'Transmettre la consigne au tuteur';
$string['form_includeintro_help']   = 'La description de l\'activité est ajoutée au contexte du tuteur, pour qu\'il sache sur quoi l\'élève travaille. À décocher si la consigne contient des éléments de réponse.';
$string['form_includebrief']        = 'Transmettre le brief pédagogique au tuteur';
$string['form_includebrief_help']   = 'Si la correction IA est configurée sur ce devoir, un brief (critères, points d\'attention, erreurs fréquentes) est fabriqué automatiquement à partir de l\'énoncé et du corrigé, puis relu par vous. Le corrigé et le barème eux-mêmes ne sont JAMAIS transmis au tuteur : il ne peut donc pas les divulguer.';
$string['form_briefstatus']         = 'Brief pédagogique';
$string['form_briefreview']         = 'Relire / modifier le brief';
$string['form_customprompt']        = 'Consignes supplémentaires pour le tuteur';
$string['form_customprompt_help']   = 'Texte libre ajouté au contexte : insistez sur une méthode, interdisez une bibliothèque, précisez le niveau attendu… Exemple : « Ne suggère aucune bibliothèque externe, l\'exercice doit se faire en C standard. »';

$string['briefstatus_none']    = 'Aucun brief';
$string['briefstatus_pending'] = 'Génération en attente';
$string['briefstatus_ready']   = 'Brief prêt';
$string['briefstatus_failed']  = 'Échec de génération';

// === Page de gestion ===
$string['managepagetitle']      = 'Tuteur IA';
$string['notenabledhere']       = 'Le tuteur IA n\'est pas activé sur cette activité. Activez-le dans les paramètres de l\'activité, section « Tuteur IA ».';
$string['brief_heading']        = 'Brief pédagogique';
$string['brief_explain']        = 'Ce texte est la seule chose que le tuteur connaît des attendus. Il est fabriqué à partir de l\'énoncé et du corrigé de la correction IA, mais ne doit contenir aucune solution : relisez-le et modifiez-le librement.';
$string['brief_save']           = 'Enregistrer le brief';
$string['brief_regenerate']     = 'Régénérer avec l\'IA';
$string['brief_saved']          = 'Brief enregistré.';
$string['brief_queued']         = 'Génération du brief demandée : elle sera traitée à la prochaine exécution du cron.';
$string['brief_notavailable']   = 'Impossible de générer un brief : activez le tuteur et renseignez un corrigé dans la correction IA de ce devoir.';
$string['brief_stale']          = 'L\'énoncé ou le corrigé ont changé depuis la génération de ce brief. Pensez à le régénérer.';
$string['conversations_heading'] = 'Conversations';
$string['noconversations']      = 'Aucune conversation pour l\'instant.';
$string['stats_summary']        = '{$a->conversations} conversation(s) · {$a->students} élève(s) · {$a->questions} question(s) · {$a->tokens} tokens.';
$string['student']              = 'Élève';
$string['col_questions']        = 'Questions';
$string['col_tokens']           = 'Tokens';
$string['col_lastactivity']     = 'Dernière activité';
$string['col_actions']          = 'Actions';
$string['viewtranscript']       = 'Voir';
$string['transcript_for']       = 'Conversation de {$a}';
$string['backtolist']           = 'Retour à la liste';
$string['deleteduser']          = 'Utilisateur supprimé';
$string['col_flags']            = 'Signalements';
$string['filter_flagged']       = 'Afficher les {$a} conversation(s) signalée(s)';
$string['filter_all']           = 'Afficher toutes les conversations';

// Modération
$string['flag_label']            = 'Signalé : {$a}';
$string['flag_pending']          = 'Analyse de modération en cours…';
$string['flag_failed']           = 'Analyse de modération impossible (serveur indisponible).';
$string['moderation_reanalyse']  = 'Relancer les analyses échouées';
$string['moderation_requeued']   = '{$a} message(s) remis en file d\'analyse.';
$string['flagcat_manipulation']        = 'tentative de manipulation';
$string['flagcat_menace']              = 'menace';
$string['flagcat_chantage']            = 'chantage';
$string['flagcat_insulte']             = 'insulte ou harcèlement';
$string['flagcat_contenu_inapproprie'] = 'contenu inapproprié';
$string['flagcat_detresse']            = 'signes de détresse';
$string['notify_subject']  = 'Tuteur IA : message signalé ({$a->category}) — {$a->student}';
$string['notify_body']     = 'Un message de {$a->student} au tuteur IA a été signalé.

Cours : {$a->course}
Activité : {$a->activity}
Catégorie : {$a->category}
Motif : {$a->reason}

Consulter la conversation : {$a->url}

Ce signalement est produit automatiquement par une IA et peut être erroné : vérifiez la conversation avant toute décision.';
$string['notify_small']    = 'Tuteur IA : message de {$a->student} signalé ({$a->category})';
$string['notify_linkname'] = 'Voir la conversation';

$string['status_done']      = 'Terminé';
$string['status_pending']   = 'En attente';
$string['status_streaming'] = 'En cours de génération';
$string['status_failed']    = 'Échec';
$string['status_cancelled'] = 'Interrompu';

// === Widget (chaînes envoyées au JavaScript) ===
$string['widget_title']        = 'Tuteur IA';
$string['widget_open']         = 'Ouvrir le tuteur IA';
$string['widget_close']        = 'Fermer';
$string['widget_new']          = 'Nouvelle discussion';
$string['widget_placeholder']  = 'Posez votre question…';
$string['widget_send']         = 'Envoyer';
$string['widget_stop']         = 'Arrêter';
$string['widget_retry']        = 'Réessayer';
$string['widget_sendhint']     = 'Entrée pour envoyer, Maj+Entrée pour aller à la ligne.';
$string['widget_welcome']      = 'Bonjour ! Je peux t\'aider à comprendre le travail demandé : explique-moi où tu bloques. Je ne donne pas la réponse, mais je te mets sur la voie.';
$string['widget_connecting']   = 'Envoi…';
$string['widget_generating']   = 'Le tuteur rédige sa réponse…';
$string['widget_queued_next']  = 'Vous êtes le prochain servi…';
$string['widget_queued_n']     = '{$a} personne(s) devant vous…';
$string['widget_interrupted']  = '(réponse interrompue)';
$string['widget_quota']        = 'Quota :';
$string['widget_networkerror'] = 'Le tuteur est momentanément indisponible. Réessayez dans un instant.';

// === Erreurs ===
$string['notsupported']    = 'Le tuteur IA n\'est pas disponible sur ce type d\'activité.';
$string['tutordisabled']   = 'Le tuteur IA n\'est pas activé sur cette activité.';
$string['error_empty']     = 'Votre message est vide.';
$string['error_toolong']   = 'Votre message dépasse {$a} caractères. Raccourcissez-le.';
$string['error_busy']      = 'Une réponse est déjà en cours. Attendez qu\'elle se termine.';
$string['error_quota']     = 'Vous avez atteint votre quota d\'utilisation du tuteur. Réessayez dans {$a}.';
$string['error_noserver']  = 'Aucun serveur n\'est configuré pour le tuteur IA. Prévenez votre enseignant.';
$string['error_notfound']  = 'Message introuvable.';
$string['error_expired']   = 'La demande a expiré avant d\'être traitée. Reposez votre question.';
$string['error_badaction'] = 'Action inconnue.';
$string['error_llm']       = 'Le tuteur n\'a pas pu répondre (serveur indisponible). Réessayez dans un instant.';
$string['brieferror_empty'] = 'Le modèle a renvoyé un brief vide.';

// === Tâche planifiée ===
$string['task_purge'] = 'Nettoyage des conversations du tuteur IA';

// === Confidentialité ===
$string['privacy:metadata:conversation']             = 'Conversations d\'un élève avec le tuteur IA sur une activité.';
$string['privacy:metadata:conversation:userid']      = 'L\'élève propriétaire de la conversation.';
$string['privacy:metadata:conversation:status']      = 'État de la conversation (en cours, close).';
$string['privacy:metadata:conversation:timecreated'] = 'Date de début de la conversation.';
$string['privacy:metadata:message']                  = 'Messages échangés avec le tuteur IA.';
$string['privacy:metadata:message:userid']           = 'L\'élève à l\'origine de l\'échange.';
$string['privacy:metadata:message:role']             = 'Auteur du message (élève ou tuteur).';
$string['privacy:metadata:message:content']          = 'Le texte du message.';
$string['privacy:metadata:message:tokens']           = 'Le nombre de tokens consommés par l\'échange.';
$string['privacy:metadata:message:flagstatus']       = 'Résultat de l\'analyse de modération du message.';
$string['privacy:metadata:message:flagcategory']     = 'Catégorie du signalement, le cas échéant.';
$string['privacy:metadata:message:flagreason']       = 'Motif du signalement, rédigé par l\'IA de modération.';
$string['privacy:metadata:message:timecreated']      = 'Date du message.';
$string['privacy:metadata:llm']                      = 'Les messages sont transmis à un service LLM externe pour produire la réponse.';
$string['privacy:metadata:llm:message']              = 'Le message de l\'élève et le contexte de l\'activité.';
$string['privacy:path:conversations']                = 'Tuteur IA';
$string['privacy:path:conversation']                 = 'Conversation {$a}';
