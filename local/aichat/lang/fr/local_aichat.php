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
$string['form_includeintro_help']   = 'La consigne de l\'activité est ajoutée au contexte du tuteur, pour qu\'il sache sur quoi l\'élève travaille : la description et, pour un devoir, les « Instructions de l\'activité ». À décocher si la consigne contient des éléments de réponse.';
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
$string['widget_resize']       = 'Redimensionner la fenêtre (flèches du clavier, Origine pour la taille par défaut)';
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
$string['privacy:metadata:message:websearches']      = 'Le nombre de recherches Web faites par le tuteur pour cette réponse.';
$string['privacy:metadata:message:toolcalls']        = 'Les requêtes de recherche Web rédigées par le tuteur pour cette réponse, et leur issue.';
$string['privacy:metadata:websearch']  = 'Si la recherche Web est activée et que l\'élève a renseigné ses propres clés, le tuteur interroge un moteur de recherche externe (Tavily, puis Brave Search en secours) avec la clé de l\'élève, donc sous son propre compte. Seule la requête rédigée par le modèle est envoyée, après retrait du nom, du prénom, de l\'identifiant et de l\'e-mail de l\'élève.';
$string['privacy:metadata:websearch:query']          = 'Le texte de la requête de recherche.';
$string['privacy:metadata:message:pagereads']        = 'Le nombre de pages ou documents lus par le tuteur pour cette réponse.';
$string['privacy:metadata:pagereader']               = 'En mode « recherche de matériel », le serveur télécharge les pages et documents que le tuteur lit, sur le site qui les publie. Seule l\'adresse de la page est transmise ; aucune donnée de l\'élève.';
$string['privacy:metadata:pagereader:url']           = 'L\'adresse de la page ou du document lu.';

// === Recherche Web ===
$string['websearch_heading']          = 'Recherche Web';
$string['websearch_heading_desc']      = 'Permet au tuteur de chercher sur Internet quand l\'information nécessaire est récente ou externe. C\'est le modèle qui décide de chercher ; le serveur Moodle exécute la recherche. Chaque élève utilise SES propres clés d\'API (Préférences > « Tuteur IA : mes clés de recherche ») : Tavily en priorité (1 000 recherches gratuites par mois, sans carte bancaire), puis Brave Search en secours. Sans clé, le tuteur ne va pas sur Internet pour cet élève. Le lycée ne paie rien et chaque clé est plafonnée. La recherche doit ensuite être autorisée activité par activité par l\'enseignant.';
$string['websearch_link']             = 'Voir l\'état, la consommation et les tests.';
$string['setting_websearch']          = 'Activer la recherche Web';
$string['setting_websearch_help']      = 'Interrupteur du site. Une fois activé, les élèves peuvent renseigner leurs clés dans leurs Préférences, et chaque enseignant peut autoriser la recherche dans les réglages du tuteur de son activité. Avant d\'activer, lancez le test « appel d\'outil » sur chaque serveur du tuteur : tous les modèles ne sont pas reconnus correctement par LM Studio.';
$string['setting_wsapikey']            = 'Clé Brave de test';
$string['setting_wsapikey_help']       = 'Clé Brave Search API utilisée UNIQUEMENT par la page de diagnostic (« Tester Brave »), jamais pour les élèves, qui utilisent leurs propres clés. Chiffrée en base, jamais réaffichée.';
$string['setting_wscap']               = 'Plafond par clé Brave (31 jours)';
$string['setting_wscap_help']          = 'Nombre maximal de recherches par clé Brave (celle d\'un élève ou la clé de test) sur 31 jours GLISSANTS. Brave débite la carte du titulaire au-delà de ses crédits mensuels (5 $, environ 1 000 recherches) au lieu de refuser : ce plafond protège les élèves d\'une facture pour les recherches faites via Moodle. Avec une fenêtre glissante de 31 jours, aucun cycle de facturation ne peut dépasser ce nombre. 0 = Brave n\'est jamais utilisé. Défaut : 900.';
$string['setting_wsperuser']          = 'Recherches par élève et par fenêtre';
$string['setting_wsperuser_help']     = 'Nombre maximal de recherches par élève sur la fenêtre du quota élève (4 h par défaut, voir ci-dessus), toutes activités confondues. Évite qu\'un seul élève épuise le budget du lycée. 0 = pas de limite. Défaut : 10.';
$string['setting_wsmaxcalls']         = 'Recherches maximales par réponse';
$string['setting_wsmaxcalls_help']    = 'Nombre maximal de recherches pour une même réponse du tuteur (protection contre les boucles). Une fois la limite atteinte, l\'outil n\'est plus proposé et le modèle doit répondre. Chaque recherche ajoute un tour de génération (le serveur relit le prompt et les résultats). Entre 1 et 5. Défaut : 2.';
$string['setting_wsmaxresults']       = 'Résultats par recherche';
$string['setting_wsmaxresults_help']  = 'Nombre de résultats (titre, URL, extrait) transmis au modèle pour chaque recherche. Plus de résultats = plus de contexte à relire. Entre 1 et 10. Défaut : 5.';
$string['setting_wstimeout']          = 'Délai maximal d\'une recherche (secondes)';
$string['setting_wstimeout_help']     = 'Au-delà, la recherche est abandonnée et le tuteur répond sans le Web. L\'élève attend pendant ce temps. Entre 2 et 20. Défaut : 6.';
$string['setting_wscachedays']        = 'Durée du cache des recherches (jours)';
$string['setting_wscachedays_help']   = 'Les résultats d\'une recherche réussie sont gardés et resservis à tout élève qui pose la même requête (casse et espaces ignorés) : gratuit, instantané, et disponible même si le moteur est en panne. Une recherche servie par le cache ne consomme ni le plafond du site ni le quota de l\'élève. Le modèle est informé de la date des résultats. Plus court = informations plus fraîches ; plus long = plus d\'économies. 0 = pas de cache. Entre 0 et 90. Défaut : 7.';
$string['cachedef_searchcache']       = 'Résultats de recherche Web du tuteur IA';
$string['cachedef_pagecache']         = 'Pages et datasheets lues par le tuteur IA';
$string['material_heading']           = 'Recherche de matériel (lecture des pages)';
$string['material_heading_desc']      = 'Dans les activités en mode « Recherche de matériel », le tuteur peut lire les pages des fabricants et les datasheets PDF trouvées par ses recherches, pour y relever des caractéristiques précises. La lecture est gratuite (aucun budget Brave) ; seules sont lisibles les adresses trouvées par une recherche, les documents liés d\'une page lue et les adresses données par l\'élève. Les adresses internes sont refusées par la politique de sécurité HTTP de Moodle. Les PDF utilisent pdftotext (réglages de la bibliothèque partagée).';
$string['setting_wstoolcalls']        = 'Appels d\'outils par réponse';
$string['setting_wstoolcalls_help']   = 'Recherches et lectures au total pour une même réponse du tuteur en mode matériel (les recherches restent aussi limitées par le réglage « Recherches maximales par réponse »). Au-delà, le modèle doit répondre. Chaque appel ajoute un tour de génération. Entre 2 et 8. Défaut : 5.';
$string['setting_wsreadsperuser']     = 'Lectures de pages par élève et par fenêtre';
$string['setting_wsreadsperuser_help'] = 'Nombre maximal de pages ou documents lus pour un élève sur la fenêtre du quota élève, toutes activités confondues. Protège le serveur (téléchargement, extraction). 0 = pas de limite. Défaut : 30.';
$string['setting_wsmaxmb']            = 'Taille maximale d\'un document (Mo)';
$string['setting_wsmaxmb_help']       = 'Au-delà, la page ou le PDF n\'est pas téléchargé. Entre 1 et 50. Défaut : 8.';
$string['setting_wsreadtimeout']      = 'Délai de lecture d\'une page (secondes)';
$string['setting_wsreadtimeout_help'] = 'Délai maximal de téléchargement. L\'élève attend pendant ce temps. Entre 3 et 30. Défaut : 10.';
$string['setting_wspagecachehours']   = 'Durée du cache des pages lues (heures)';
$string['setting_wspagecachehours_help'] = 'Le texte extrait d\'une page ou d\'une datasheet est gardé et resservi : une classe qui lit la même datasheet ne la télécharge qu\'une fois. 0 = pas de cache. Entre 0 et 720. Défaut : 24.';

$string['form_websearch']             = 'Recherches du tuteur';
$string['form_websearch_help']        = '<strong>Aucune</strong> : le tuteur répond avec ses seules connaissances.<br><strong>Recherche Web ponctuelle</strong> : il peut chercher sur Internet quand une information récente ou externe est nécessaire (version d\'un logiciel, documentation officielle…) et cite ses sources.<br><strong>Recherche de matériel</strong> : pour une activité où l\'élève choisit un matériel d\'après un cahier des charges (carte d\'entrées/sorties, capteur…). Le tuteur cherche des références, lit les pages des fabricants et les datasheets pour y relever les caractéristiques, propose au plus 3 pistes par réponse, mais ne remplit jamais l\'étude comparative et ne choisit jamais à la place de l\'élève.<br>Dans tous les cas, il ne cherche jamais la solution de l\'activité. À laisser sur « Aucune » si l\'activité s\'y prête mal (exercice dont la solution se trouve facilement en ligne, évaluation).';
$string['form_websearch_none']        = 'Aucune';
$string['form_websearch_web']         = 'Recherche Web ponctuelle';
$string['form_websearch_material']    = 'Recherche de matériel (Web + lecture des pages et datasheets)';
$string['form_websearchsites']        = 'Sites de référence';
$string['form_websearchsites_help']   = 'Facultatif, un domaine par ligne (ex. teracomsystems.com, hw-group.com, icpdas-europe.com, gotronic.fr, raspberrypi.com). Le tuteur privilégie ces sites dans ses recherches (opérateur site:) : pages des fabricants et distributeurs habituels, plutôt que places de marché ou blogs.';
$string['widget_searching']            = 'Recherche sur le Web : « {$a} »…';
$string['widget_reading']             = 'Lecture d\'une page : {$a}…';
$string['ws_sources_heading']          = 'Sources consultées (recherche Web : {$a}) :';

$string['ws_transcript']              = 'Recherches Web :';
$string['ws_results']                 = '{$a} résultat(s)';
$string['ws_cached']                  = '(cache)';
$string['ws_cache_hits']              = '{$a->hits} recherche(s) servie(s) par le cache sur les 31 derniers jours, sans rien décompter (durée du cache : {$a->days} jour(s)).';
$string['ws_unavailable']             = 'non effectuée ({$a})';
$string['ws_noquery']                 = 'requête invalide';
$string['ws_read']                    = 'Lecture :';
$string['ws_read_done']               = '{$a->type}, {$a->chars} caractères retenus';
$string['ws_reason_url_not_allowed']      = 'adresse non autorisée';
$string['ws_reason_fetch_error']          = 'page inaccessible';
$string['ws_reason_too_large']            = 'document trop volumineux';
$string['ws_reason_unsupported_type']     = 'format non lisible';
$string['ws_reason_empty_content']        = 'contenu illisible (JavaScript, PDF scanné…)';
$string['ws_reason_pdftotext_missing']    = 'lecture des PDF indisponible (pdftotext)';
$string['ws_reason_reads_exhausted']      = 'quota de lectures de l\'élève atteint';
$string['ws_offered_unused']          = 'recherche proposée au modèle, non utilisée';
$string['ws_notoffered']              = 'recherche non proposée au modèle ({$a})';
$string['ws_reason_not_configured']       = 'clé API absente';
$string['ws_reason_provider_unavailable'] = 'moteur suspendu';
$string['ws_reason_quota_exhausted']      = 'plafond du site atteint';
$string['ws_reason_user_quota_exhausted'] = 'quota de l\'élève atteint';
$string['ws_reason_tool_call_limit']      = 'limite de recherches par réponse atteinte';
$string['ws_reason_invalid_query']        = 'requête vide ou invalide';
$string['ws_reason_unknown_tool']         = 'outil inconnu demandé par le modèle';
$string['ws_reason_budget_busy']          = 'compteur momentanément indisponible';
$string['ws_reason_rate_limited']         = 'trop de requêtes par seconde';
$string['ws_reason_provider_quota']       = 'quota du plan du moteur épuisé';
$string['ws_reason_auth_error']           = 'clé API refusée';
$string['ws_reason_provider_error']       = 'erreur du moteur ou du réseau';
$string['ws_reason_timeout']              = 'délai dépassé';

$string['ws_page']                    = 'Tuteur IA : recherche Web';
$string['ws_page_intro']              = 'État du moteur de recherche, consommation du budget et tests de compatibilité.';
$string['ws_state_heading']           = 'État';
$string['ws_state_disabled']          = 'La recherche Web est désactivée pour le site (réglages du tuteur IA). Les tests restent disponibles.';
$string['ws_state_blocked']           = 'Recherche suspendue : {$a->reason}, jusqu\'à {$a->until}. Le tuteur répond sans le Web en attendant.';
$string['ws_until_manual']            = 'remise en service manuelle';
$string['ws_unblock']                 = 'Remettre en service';
$string['ws_unblocked']               = 'Recherche Web remise en service.';
$string['ws_budget_heading']          = 'Budget';
$string['ws_budget_explain']           = 'Chaque clé est plafonnée sur 31 jours glissants (voir le tableau). Par élève : {$a->peruser} recherche(s) par fenêtre de {$a->hours} h (0 = pas de limite). Par réponse : {$a->maxcalls} au plus.';
$string['ws_ratelimit']               = 'Dernières limites annoncées par {$a->provider} ({$a->time}) : {$a->persecond} requête(s)/s, {$a->permonth} par période du plan, dont {$a->remaining} restantes ; réinitialisation dans {$a->reset}.';
$string['ws_ratelimit_note']           = 'Ces chiffres sont ceux du plan Brave de la dernière clé utilisée, pas ses crédits gratuits : Brave facture au-delà des crédits au lieu de refuser. Seul le plafond par clé protège.';
$string['ws_lasterror']               = 'Dernier échec ({$a->time}) : {$a->reason} — {$a->detail}';
$string['ws_testsearch_explain']       = '« Tester » lance une vraie recherche (« Moodle LMS ») avec la clé de TEST du moteur (réglages du plugin), décomptée dans le plafond de cette clé. Un succès lève une éventuelle suspension du moteur. Les élèves, eux, utilisent leurs propres clés.';
$string['ws_testsearch_ok']           = 'Recherche réussie : {$a} résultat(s).';
$string['ws_testsearch_failed']       = 'Échec de la recherche : {$a}.';
$string['ws_testtools_heading']       = 'Tester l\'appel d\'outil sur les serveurs du tuteur';
$string['ws_testtools_explain']       = 'Pose au modèle de chaque serveur une question qui exige une recherche, puis lui renvoie un résultat factice (aucun appel au moteur, rien n\'est décompté). Vérifie que LM Studio reconnaît l\'appel d\'outil en streaming (sinon il apparaîtrait en texte brut chez l\'élève) et que le modèle rédige ensuite sa réponse. Le test contourne la file d\'attente : lancez-le de préférence hors des heures de cours. Il peut durer une à deux minutes.';
$string['ws_col_server']              = 'Serveur';
$string['ws_globalmodel']             = 'modèle global';
$string['ws_testtools']               = 'Tester l\'appel d\'outil';
$string['ws_notutorserver']           = 'Aucun serveur n\'accepte le tuteur (réglages de la bibliothèque partagée).';
$string['ws_check_ok']                = 'OK';
$string['ws_check_failed']            = 'Échec';
$string['ws_check_info']              = 'Info';
$string['ws_testtools_result']        = 'Résultat du serveur {$a->id} ({$a->seconds} s)';
$string['ws_testtools_verdict_ok']    = 'Ce serveur gère correctement la recherche Web.';
$string['ws_testtools_verdict_bad']   = 'Ce serveur ne gère pas correctement l\'appel d\'outil avec ce modèle. N\'activez pas la recherche Web tant qu\'il sert le tuteur : mettez LM Studio à jour, essayez un autre modèle, ou retirez-lui l\'usage « tuteur ».';
$string['ws_answer1']                 = 'Texte du premier tour (doit être vide ou court, sans balise d\'appel) :';
$string['ws_answer2']                 = 'Réponse finale après le résultat factice :';
$string['ws_top_activities']          = 'Activités qui consomment le plus de recherches Web (31 jours) :';
$string['ws_activity_used']            = '{$a} recherche(s)';
$string['ws_testread_heading']        = 'Tester la lecture d\'une page';
$string['ws_testread_explain']        = 'Lit une page ou un PDF par le même chemin que l\'outil read_page du tuteur (téléchargement, extraction, sélection des passages pertinents pour les mots-clés), sans cache ni appel au moteur de recherche. Le résultat affiché est exactement ce que le modèle recevrait.';
$string['ws_testread_nopdftotext']    = 'pdftotext est introuvable sur ce serveur : les datasheets PDF ne pourront pas être lues (voir les réglages de la bibliothèque partagée, binaires poppler-utils).';
$string['ws_testread_url']            = 'Adresse de la page ou du PDF';
$string['ws_testread_focus']          = 'Mots-clés (focus)';
$string['ws_testread']                = 'Tester la lecture';
$string['ws_testread_ok']             = 'Lecture réussie.';
$string['ws_testread_failed']         = 'Lecture impossible : {$a}.';
$string['ws_testread_output']         = 'Texte transmis au modèle :';
$string['diag_step_request']          = 'Requête acceptée par le serveur';
$string['diag_step_toolcall']         = 'Appel de l\'outil web_search reconnu dans le flux (tool_calls)';
$string['diag_step_noleak']           = 'Aucune balise d\'appel d\'outil dans le texte du premier tour';
$string['diag_step_arguments']        = 'Arguments JSON valides avec une requête';
$string['diag_step_final']            = 'Réponse rédigée après le résultat, sans nouvel appel';
$string['diag_step_noleak2']          = 'Aucune balise d\'appel d\'outil dans la réponse finale';

// === Clés de recherche personnelles ===
$string['ws_sources_heading_plain']    = 'Sources consultées :';
$string['ws_fallback']                 = 'moteur de secours';
$string['setting_wstavilykey']         = 'Clé Tavily de test';
$string['setting_wstavilykey_help']    = 'Clé Tavily utilisée UNIQUEMENT par la page de diagnostic (« Tester Tavily »), jamais pour les élèves. Chiffrée en base, jamais réaffichée. Compte gratuit, sans carte bancaire, sur app.tavily.com.';
$string['setting_wstavilycap']         = 'Plafond par clé Tavily (31 jours)';
$string['setting_wstavilycap_help']    = 'Nombre maximal de recherches par clé Tavily sur 31 jours glissants. L\'offre gratuite de Tavily donne 1 000 crédits par mois et ne facture jamais (pas de carte) : au-delà, Tavily refuse et le tuteur passe à Brave si l\'élève a une clé Brave. Ce plafond évite seulement des appels voués à l\'échec. 0 = pas de plafond local. Défaut : 1000.';
$string['widget_needkeys']             = 'Pour que le tuteur puisse chercher sur le Web, ajoutez votre clé de recherche personnelle (gratuite).';
$string['widget_needkeys_link']        = 'Ajouter ma clé';
$string['ws_state_userkeys']           = 'Les élèves cherchent avec leurs propres clés ; les clés de test des réglages ne servent qu\'aux tests de cette page.';
$string['ws_col_engine']               = 'Moteur';
$string['ws_col_users']                = 'Élèves avec une clé';
$string['ws_col_used']                 = 'Recherches (31 j)';
$string['ws_col_keycap']               = 'Plafond par clé';
$string['ws_col_state']                = 'État';
$string['ws_col_test']                 = 'Test (clé de test)';
$string['ws_state_inservice']          = 'En service';
$string['ws_notestkey']                = 'pas de clé de test';
$string['ws_testsearch_engine']        = 'Tester {$a}';
$string['privacy:metadata:preference:key'] = 'Clé d\'API personnelle de recherche Web de l\'élève (chiffrée).';
$string['privacy:metadata:preference:keystate'] = 'État de la clé de recherche (refusée, crédits épuisés) et date de fin de suspension.';
$string['privacy:keyconfigured']       = 'clé configurée ({$a})';
$string['mykeys_page']                 = 'Tuteur IA : mes clés de recherche';
$string['mykeys_guest']                = 'Les visiteurs anonymes ne peuvent pas enregistrer de clé.';
$string['mykeys_loggedinas']           = 'Les clés de recherche sont personnelles : elles ne peuvent être ni consultées ni modifiées en étant connecté en tant qu\'un autre utilisateur.';
$string['mykeys_disabled']             = 'La recherche Web du tuteur n\'est pas activée sur ce site.';
$string['mykeys_intro']                = 'Le tuteur IA peut chercher des informations sur Internet pour vous aider, dans les activités où l\'enseignant l\'a autorisé. Il utilise pour cela VOS propres clés de recherche : sans clé, il ne va pas sur Internet.<br><strong>Tavily (recommandé, gratuit)</strong> : créez un compte sur <a href="https://app.tavily.com" target="_blank" rel="noopener noreferrer">app.tavily.com</a> (aucune carte bancaire demandée), copiez votre clé (elle commence par « tvly- ») et collez-la ci-dessous. L\'offre gratuite donne 1 000 recherches par mois.<br><strong>Brave Search (facultatif, en secours)</strong> : utilisé seulement si Tavily ne répond pas ou si ses crédits du mois sont épuisés. Attention : Brave demande une carte bancaire et facture au-delà de 5 $ de crédits par mois ; le tuteur s\'arrête à {$a} recherches sur 31 jours avec cette clé.';
$string['mykeys_status']               = 'État';
$string['mykeys_newkey']               = 'Nouvelle clé';
$string['mykeys_tavily']               = 'Clé Tavily';
$string['mykeys_tavily_help']          = 'Collez la clé de votre compte Tavily (tableau de bord app.tavily.com, elle commence par « tvly- »). Laissez vide pour conserver la clé déjà enregistrée.';
$string['mykeys_brave']                = 'Clé Brave Search';
$string['mykeys_brave_help']           = 'Facultatif. Collez la clé de votre compte Brave Search API (api-dashboard.search.brave.com). Laissez vide pour conserver la clé déjà enregistrée.';
$string['mykeys_remove']               = 'Supprimer cette clé';
$string['mykeys_invalidformat']        = 'Cette clé ne semble pas valide (8 à 200 caractères : lettres, chiffres, tirets, points ou soulignés).';
$string['mykeys_none']                 = 'Aucune clé';
$string['mykeys_saved']                = 'Clé enregistrée (se termine par {$a})';
$string['mykeys_suspended']            = 'Clé suspendue : {$a->reason}, jusqu\'à {$a->until}.';
$string['mykeys_until_change']         = 'une modification de la clé ou un test réussi';
$string['mykeys_used']                 = 'Recherches du tuteur avec cette clé sur 31 jours : {$a->used} (plafond : {$a->cap}).';
$string['mykeys_savedmsg']             = 'Vos clés ont été enregistrées.';
$string['mykeys_test']                 = 'Tester ma clé {$a}';
$string['mykeys_test_brave_note']      = 'Le test de la clé Brave lance une vraie recherche (1 requête décomptée).';
$string['mykeys_test_ok_tavily']       = 'Clé Tavily valide. Crédits utilisés ce mois-ci : {$a->usage} sur {$a->limit}.';
$string['mykeys_test_ok_brave']        = 'Clé Brave valide : recherche réussie ({$a} résultat(s)).';
$string['mykeys_test_failed']          = 'La clé n\'a pas pu être vérifiée : {$a}.';
$string['mykeys_privacy']              = 'Vos clés sont chiffrées dans Moodle et ne sont jamais réaffichées, ni transmises au navigateur ou à l\'IA. Les recherches du tuteur partent sous VOTRE compte chez le moteur (Tavily, Brave) : seul le texte de la recherche est envoyé, sans votre nom ni votre e-mail. Vous pouvez supprimer une clé à tout moment.';
