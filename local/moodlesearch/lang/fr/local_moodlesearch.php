<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'MoodleSearch';

// Capacités.
$string['moodlesearch:use']            = 'Chercher avec MoodleSearch';
$string['moodlesearch:viewreport']     = 'Voir les recherches des élèves du cours';
$string['moodlesearch:viewsitereport'] = 'Voir les recherches de tout le site';
$string['moodlesearch:manageaccess']   = 'Activer ou désactiver MoodleSearch par cohorte';

// Page de recherche.
$string['guest']              = 'MoodleSearch n\'est pas accessible aux invités.';
$string['search_placeholder'] = 'Rechercher sur le Web';
$string['search_button']      = 'Rechercher';
$string['period']             = 'Période';
$string['period_any']         = 'Toutes les dates';
$string['period_day']         = 'Dernières 24 heures';
$string['period_week']        = 'Dernière semaine';
$string['period_month']       = 'Dernier mois';
$string['period_year']        = 'Dernière année';
$string['tab_web']            = 'Web';
$string['tab_news']           = 'Actualités';
$string['notice_monitoring']  = 'Tes recherches et les sites que tu ouvres sont enregistrés et consultables par tes enseignants.';
$string['noresults']          = 'Aucun résultat pour « {$a} ».';
$string['moreresults']        = 'Plus de résultats';
$string['badge_blocked']      = 'Bloqué par l\'établissement';
$string['badge_blocked_help'] = 'Ce site est en liste noire : l\'ouvrir ne débloquera pas l\'accès.';
$string['poweredby']          = 'Résultats fournis par Tavily, sans réponse générée par une IA';
$string['keyusage']           = 'Recherches faites avec ta clé ces 31 derniers jours : {$a->used} (plafond : {$a->cap})';
$string['recent']             = 'Mes dernières recherches';

// Refus et erreurs.
$string['reason_disabled']             = 'MoodleSearch n\'est pas activé sur ce site.';
$string['reason_capability']           = 'Tu n\'as pas accès à MoodleSearch.';
$string['reason_cohort']               = 'MoodleSearch est désactivé pour ta classe en ce moment.';
$string['reason_exam']                 = 'Un mode examen est en cours dans ta classe : MoodleSearch est fermé.';
$string['reason_nokey']                = 'Pour chercher avec MoodleSearch, ajoute ta clé Tavily personnelle (gratuite) : <a href="{$a}">mes clés de recherche</a>.';
$string['reason_ratelimit']            = 'Tu as fait beaucoup de recherches : réessaie dans un moment (au plus {$a} recherches par heure).';
$string['reason_emptyquery']           = 'Saisis ce que tu cherches.';
$string['reason_auth_error']           = 'Ta clé Tavily est refusée : vérifie-la dans tes clés de recherche.';
$string['reason_provider_quota']       = 'Les crédits de ta clé Tavily sont épuisés pour ce mois.';
$string['reason_quota_exhausted']      = 'Le plafond de recherches de ta clé est atteint pour les 31 derniers jours.';
$string['reason_budget_busy']          = 'MoodleSearch est très sollicité : réessaie dans quelques secondes.';
$string['reason_provider_unavailable'] = 'Le moteur de recherche est momentanément indisponible : réessaie dans une minute.';
$string['reason_provider_error']       = 'Le moteur de recherche n\'a pas répondu correctement : réessaie dans une minute.';
$string['reason_timeout']              = 'Le moteur de recherche a mis trop de temps à répondre : réessaie.';
$string['reason_rate_limited']         = 'Trop de recherches d\'un coup sur ta clé : réessaie dans quelques secondes.';
$string['reason_invalid_query']        = 'Cette recherche n\'a pas été acceptée par le moteur : reformule-la.';
$string['reason_other']                = 'La recherche n\'a pas abouti : réessaie dans un moment.';

// Clic : ouverture du site (OPNsense).
$string['opnsense_reason']  = 'Résultat ouvert depuis MoodleSearch';
$string['click_notfound']   = 'Ce résultat n\'existe plus : relance la recherche.';
$string['open_title']       = 'Ouverture de {$a}';
$string['open_wait']        = 'Ouverture de l\'accès à {$a} pour ta classe…';
$string['open_link']        = 'Aller sur {$a}';
$string['open_declaremac']  = 'Déclarer mon poste';
$string['open_back']        = 'Retour aux résultats';
$string['open_pending']     = 'Ta demande d\'accès à ce site a été envoyée à ton enseignant. Le site s\'ouvrira quand il l\'aura acceptée.';
$string['open_blocked']     = 'Ce site est en liste noire : l\'accès n\'est pas ouvert.';
$string['open_exam']        = 'Un mode examen est en cours dans ta classe : aucun site n\'est ouvert.';
$string['open_invalid']     = 'Cette adresse ne peut pas être ouverte.';
$string['open_wildcard']    = 'Cette adresse ne peut pas être ouverte.';
$string['open_nomac']       = 'Ton poste n\'est pas déclaré : le site ne peut pas être ouvert pour toi. Déclare ton poste, puis réessaie.';
$string['open_error']       = 'L\'accès n\'a pas pu être ouvert pour le moment : le site sera peut-être bloqué.';

// Statut des clics (rapport).
$string['click_opened']        = 'ouvert pour la classe';
$string['click_already']       = 'déjà ouvert';
$string['click_pending']       = 'demande en attente';
$string['click_blocked']       = 'liste noire';
$string['click_exam']          = 'mode examen';
$string['click_nomac']         = 'poste non déclaré';
$string['click_error']         = 'échec d\'ouverture';
$string['click_none']          = 'sans pare-feu';
$string['click_waiting']       = 'en cours';
$string['click_nocohort']      = 'sans classe';
$string['click_notconfigured'] = 'classe non configurée';
$string['click_invalid']       = 'adresse invalide';

// Rapport.
$string['report_title']          = 'Recherches MoodleSearch';
$string['report_sitetitle']      = 'MoodleSearch : recherches du site';
$string['report_intro']          = 'Les recherches des élèves et les sites qu\'ils ont ouverts, avec la suite donnée par le pare-feu. Les recherches ne sont rattachées à aucun cours : toutes celles des élèves figurent ici.';
$string['report_allstudents']    = 'Tous les élèves';
$string['report_days']           = '{$a} derniers jours';
$string['report_alltime']        = 'Tout';
$string['report_filter']         = 'Filtrer';
$string['report_none']           = 'Aucune recherche pour ces critères.';
$string['report_col_time']       = 'Date';
$string['report_col_student']    = 'Élève';
$string['report_col_query']      = 'Recherche';
$string['report_col_results']    = 'Résultats';
$string['report_col_opened']     = 'Sites ouverts';
$string['report_status_refused'] = 'refusée';
$string['report_status_error']   = 'erreur';

// Cohortes.
$string['cohorts_title']          = 'MoodleSearch : accès par cohorte';
$string['cohorts_intro']          = 'Une cohorte suit le réglage par défaut ({$a}), ou est activée, ou désactivée. Une cohorte désactivée l\'emporte toujours : pour couper MoodleSearch à une classe pendant une évaluation, désactivez sa cohorte. Le changement est immédiat. Un mode examen OPNsense ferme aussi MoodleSearch à la classe concernée.';
$string['cohorts_default_open']   = 'autorisé';
$string['cohorts_default_closed'] = 'fermé';
$string['cohorts_none']           = 'Aucune cohorte sur ce site.';
$string['cohorts_saved']          = 'Accès modifié.';
$string['cohorts_col_cohort']     = 'Cohorte';
$string['cohorts_col_context']    = 'Contexte';
$string['cohorts_col_members']    = 'Membres';
$string['cohorts_col_access']     = 'Accès à MoodleSearch';
$string['cohorts_col_actions']    = 'Changer';
$string['cohorts_state_on']       = 'Activé';
$string['cohorts_state_off']      = 'Désactivé';
$string['cohorts_state_default']  = 'Par défaut ({$a})';
$string['cohorts_exam']           = 'Mode examen OPNsense';
$string['cohorts_set_on']         = 'Activer';
$string['cohorts_set_off']        = 'Désactiver';
$string['cohorts_set_default']    = 'Par défaut';

// Réglages.
$string['settings_intro']               = 'MoodleSearch est un moteur de recherche Web sans IA : il n\'affiche que des résultats. Chaque personne cherche avec sa clé Tavily personnelle, gérée dans ses Préférences (« mes clés de recherche ») ; la consommation, le plafond par clé et le cache des recherches sont ceux du Tuteur IA (réglages « Recherche Web » du Tuteur IA). Un clic sur un résultat demande au bloc OPNsense l\'ouverture du site pour la classe, sauf s\'il est en liste noire.';
$string['setting_enabled']              = 'Activer MoodleSearch';
$string['setting_enabled_desc']         = 'Ajoute « MoodleSearch » au menu principal.';
$string['setting_defaultaccess']        = 'Accès par défaut';
$string['setting_defaultaccess_desc']   = 'Coché : ouvert à tous, sauf cohortes désactivées. Décoché : réservé aux cohortes activées.';
$string['setting_perpage']              = 'Résultats par page';
$string['setting_perpage_desc']         = '« Plus de résultats » en affiche 20 (une nouvelle recherche, sauf si elle est en cache).';
$string['setting_maxperhour']           = 'Recherches par personne et par heure';
$string['setting_maxperhour_desc']      = 'Recherches réellement envoyées au moteur ; celles servies par le cache ne comptent pas.';
$string['setting_country']              = 'Pays privilégié';
$string['setting_country_desc']         = 'Nom du pays en anglais et en minuscules (ex. france), pour favoriser ses résultats. Vide : aucun.';
$string['setting_excludedomains']       = 'Domaines exclus des résultats';
$string['setting_excludedomains_desc']  = 'Un domaine par ligne (150 au plus), jamais proposés : sites de corrigés tout faits, par exemple. Les sites en liste noire OPNsense restent affichés, avec un badge.';
$string['setting_retentiondays']        = 'Conservation des traces (jours)';
$string['setting_retentiondays_desc']   = 'Recherches et clics plus anciens supprimés chaque nuit. 0 : conservés sans limite.';
$string['task_purge']                   = 'MoodleSearch : purge des anciennes recherches';

// Vie privée.
$string['privacy:metadata:search']          = 'Recherches faites dans MoodleSearch, consultables par les enseignants de l\'élève.';
$string['privacy:metadata:search:userid']   = 'La personne qui a cherché.';
$string['privacy:metadata:search:query']    = 'Le texte de la recherche.';
$string['privacy:metadata:search:results']  = 'Les résultats affichés (adresse, titre).';
$string['privacy:metadata:click']           = 'Résultats ouverts depuis MoodleSearch.';
$string['privacy:metadata:click:userid']    = 'La personne qui a ouvert le résultat.';
$string['privacy:metadata:click:url']       = 'L\'adresse ouverte.';
$string['privacy:metadata:click:opnstatus'] = 'La suite donnée par le pare-feu (ouvert, en attente, liste noire…).';
$string['privacy:metadata:timecreated']     = 'La date.';
$string['privacy:metadata:tavily']          = 'La recherche est envoyée au moteur Tavily, sous le compte de la personne (sa clé personnelle).';
$string['privacy:metadata:tavily:query']    = 'Le texte de la recherche.';
$string['privacy:metadata:opnsense']        = 'Le domaine d\'un résultat ouvert est transmis au pare-feu de l\'établissement (bloc OPNsense) pour l\'ouvrir à la classe.';
$string['privacy:metadata:opnsense:domain'] = 'Le domaine du site.';
