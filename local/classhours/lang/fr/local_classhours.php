<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Heures de cours';
$string['privacy:metadata'] = 'Le plugin Heures de cours ne stocke que l\'emploi du temps des cours et des réglages d\'activités, aucune donnée personnelle.';
$string['classhours:manage'] = 'Régler les heures de cours du cours et les activités restreintes';
$string['task_sync_efe'] = 'Heures de cours : restriction automatique des activités à remontée EFE';

// Réglages du site.
$string['settings_intro'] = 'L\'emploi du temps se règle dans chaque cours, depuis le lien « Heures de cours » de la navigation du cours. Les heures sont toujours celles du fuseau horaire du serveur.';
$string['setting_graceminutes'] = 'Tolérance après la fin d\'un créneau (minutes)';
$string['setting_graceminutes_desc'] = 'Pendant ces quelques minutes, l\'activité reste accessible : l\'envoi automatique d\'un test et le dernier enregistrement d\'un devoir passent. Aucune nouvelle tentative de test ne peut commencer pendant la tolérance.';
$string['setting_efeauto_default'] = 'Option EFE cochée par défaut';
$string['setting_efeauto_default_desc'] = 'Valeur de l\'option « Restreindre automatiquement les activités avec remontée EFE » pour les cours qui ne l\'ont jamais réglée. Sans aucun créneau configuré, l\'option n\'a aucun effet dans un cours.';

// Page du cours.
$string['menu'] = 'Heures de cours';
$string['intro'] = 'Les activités restreintes aux heures de cours ne sont accessibles aux élèves que pendant leurs créneaux. Les heures sont celles de l\'établissement (fuseau {$a}).';
$string['warn_availabilitydisabled'] = 'Les restrictions d\'accès sont désactivées sur ce site : les heures de cours ne s\'appliquent pas. Un administrateur doit activer « Activer les restrictions d\'accès ».';
$string['warn_conditiondisabled'] = 'La condition d\'accès « Heures de cours » (availability_classhours) n\'est pas installée ou est désactivée : aucune activité ne peut être restreinte.';
$string['allcourse'] = 'Tout le cours';
$string['unknowngroup'] = 'groupe supprimé';
$string['strftimeslot'] = '%A %d %B à %H:%M';

$string['status_heading'] = 'En ce moment';
$string['status_now'] = 'Heure du serveur : <strong>{$a->time}</strong> (fuseau {$a->tz}).';
$string['status'] = 'État';
$string['status_everyone'] = 'Tous les élèves';
$string['status_nogroup'] = 'Élèves sans groupe';
$string['status_open'] = 'Ouvert jusqu\'au {$a}';
$string['status_closed'] = 'Fermé';
$string['status_next'] = 'prochain créneau : {$a}';
$string['status_nonext'] = 'aucun créneau dans les 60 prochains jours';
$string['offslot_link'] = 'Voir les tentatives faites hors créneau';

$string['slots_heading'] = 'Emploi du temps de la semaine';
$string['slots_none'] = 'Aucun créneau hebdomadaire pour l\'instant.';
$string['weekday'] = 'Jour';
$string['hours'] = 'Horaire';
$string['starttime'] = 'Début';
$string['endtime'] = 'Fin';
$string['slot_add'] = 'Ajouter le créneau';
$string['slot_added'] = 'Créneau ajouté.';
$string['error_endbeforestart'] = 'La fin doit être après le début.';

$string['open_heading'] = 'Ouvertures exceptionnelles';
$string['open_help'] = 'Un créneau daté, en plus de l\'emploi du temps : rattrapage, séance déplacée. Il s\'applique même pendant une période fermée.';
$string['open_add'] = 'Ajouter l\'ouverture';
$string['timestart'] = 'Début';
$string['timeend'] = 'Fin';
$string['periodname'] = 'Libellé';

$string['closed_heading'] = 'Périodes fermées';
$string['closed_help'] = 'Vacances, stage : pendant ces jours, les créneaux de la semaine ne s\'appliquent pas.';
$string['closed_add'] = 'Ajouter la période';
$string['closed_course_heading'] = 'Périodes propres au cours';
$string['useglobal'] = 'Utiliser les périodes de fermeture globales';
$string['global_none'] = 'Aucune période de fermeture globale n\'est définie pour le site.';
$string['global_manage'] = 'Gérer les périodes de fermeture globales';

// Périodes de fermeture globales (administration).
$string['global_heading'] = 'Périodes de fermeture globales';
$string['global_menu'] = 'Heures de cours : périodes de fermeture globales';
$string['global_intro'] = 'Vacances et autres fermetures communes à tout l\'établissement. Elles s\'appliquent aux cours qui cochent « Utiliser les périodes de fermeture globales » sur leur page Heures de cours ; chaque cours peut en ajouter d\'autres. Dates du fuseau {$a}.';
$string['setting_global_desc'] = 'Les vacances et fermetures communes à tous les cours se saisissent sur la page <a href="{$a}">Périodes de fermeture globales</a>.';
$string['setting_useglobal_default'] = 'Périodes globales appliquées par défaut';
$string['setting_useglobal_default_desc'] = 'Valeur de la case « Utiliser les périodes de fermeture globales » pour les cours qui ne l\'ont jamais réglée.';
$string['datestart'] = 'Premier jour';
$string['dateend'] = 'Dernier jour';
$string['period_added'] = 'Période ajoutée.';
$string['deleted'] = 'Supprimé.';

$string['activities_heading'] = 'Activités restreintes';
$string['activities_help'] = 'Cochez « Restreindre » pour limiter une activité aux heures de cours. Vous pouvez aussi ajouter la condition « Pendant les heures de cours » depuis la restriction d\'accès de l\'activité.';
$string['activities_none'] = 'Aucun test ni devoir dans ce cours.';
$string['efeauto'] = 'Restreindre automatiquement les activités avec remontée EFE';
$string['efeauto_help'] = 'Toute activité dont la remontée EFE est activée est restreinte aux heures de cours, sauf si elle est exclue ci-dessous. La restriction est posée et retirée automatiquement quand la remontée EFE change.';
$string['efeauto_noslots'] = 'L\'option est active mais sans effet tant qu\'aucun créneau n\'est défini.';
$string['sync_now'] = 'Synchroniser maintenant';
$string['sync_done'] = 'Synchronisation faite : {$a} activité(s) mise(s) à jour.';
$string['saved'] = 'Modifications enregistrées.';
$string['efe'] = 'EFE';
$string['restrict'] = 'Restreindre';
$string['exclude'] = 'Exclure de l\'option EFE';
$string['state_manual'] = 'Restreinte';
$string['state_auto'] = 'Restreinte (auto EFE)';
$string['state_nested'] = 'Restreinte (règle personnalisée)';
$string['state_excluded'] = 'Exclue';
$string['state_free'] = 'Libre';
