<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Heures de cours';
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

// Lecture hors créneau et verrou de remise.
$string['submit_closed'] = 'La remise n\'est possible que pendant les heures de cours. Tu peux relire ton devoir et ton feedback, mais pas le modifier maintenant.';

// Accès exceptionnel : capacités et notifications.
$string['classhours:requestaccess'] = 'Demander un accès exceptionnel à une activité fermée';
$string['classhours:grantaccess'] = 'Accepter, refuser ou accorder un accès exceptionnel';
$string['messageprovider:accessrequest'] = 'Demande d\'accès exceptionnel d\'un élève';
$string['messageprovider:accessdecision'] = 'Réponse à une demande d\'accès exceptionnel';

// Accès exceptionnel : page de l'élève.
$string['request_heading'] = 'Demander un accès exceptionnel';
$string['request_intro'] = 'L\'activité « {$a} » est fermée en dehors des heures de cours. Tu peux demander à ton enseignant de te l\'ouvrir un moment, par exemple pour rendre ton devoir.';
$string['request_reason'] = 'Message pour ton enseignant (facultatif)';
$string['request_reason_help'] = 'Explique en quelques mots pourquoi tu as besoin d\'accéder à l\'activité maintenant.';
$string['request_submit'] = 'Envoyer la demande';
$string['request_sent'] = 'Ta demande pour « {$a} » a été envoyée à ton enseignant.';
$string['request_already'] = 'Une demande pour « {$a} » est déjà en attente de réponse.';
$string['request_notneeded'] = 'Cette activité n\'est pas fermée pour toi en ce moment.';

// Accès exceptionnel : page de l'enseignant.
$string['requests_menu'] = 'Demandes d\'accès';
$string['requests_heading'] = 'Demandes d\'accès exceptionnel';
$string['requests_intro'] = 'Les élèves peuvent demander l\'ouverture d\'un devoir ou d\'un test fermé en dehors des heures de cours, par exemple pour rendre un devoir. Un accès accordé ouvre l\'activité à l\'élève pendant la durée choisie, puis elle se referme d\'elle-même.';
$string['requests_pending'] = 'Demandes en attente';
$string['requests_nopending'] = 'Aucune demande en attente.';
$string['requests_active'] = 'Accès en cours';
$string['requests_noactive'] = 'Aucun accès en cours.';
$string['requests_grant'] = 'Accorder un accès sans demande';
$string['requests_grant_submit'] = 'Accorder';
$string['requests_grant_none'] = 'Aucune activité restreinte aux heures de cours, ou aucun élève inscrit.';
$string['requests_history'] = 'Historique';
$string['requests_nohistory'] = 'Aucune décision pour l\'instant.';
$string['requests_comment'] = 'Commentaire';
$string['requests_accepted'] = 'Accès accordé.';
$string['requests_refused'] = 'Demande refusée.';
$string['requests_revoked'] = 'Accès retiré.';
$string['requests_granted'] = 'Accès accordé.';
$string['requests_alreadydecided'] = 'Un autre enseignant a déjà répondu à cette demande.';
$string['student'] = 'Élève';
$string['activity'] = 'Activité';
$string['requestedat'] = 'Demandée le';
$string['decision'] = 'Décision';
$string['until'] = 'Jusqu\'au';
$string['accept'] = 'Accepter';
$string['refuse'] = 'Refuser';
$string['revoke'] = 'Retirer l\'accès';
$string['decidedby'] = 'Décidé par';
$string['decidedat'] = 'Le';
$string['deletedactivity'] = 'activité supprimée';
$string['duration_tonight'] = 'Jusqu\'à ce soir';
$string['grantstatus_pending'] = 'En attente';
$string['grantstatus_accepted'] = 'Accordé';
$string['grantstatus_refused'] = 'Refusé';
$string['grantstatus_cancelled'] = 'Retiré';

// Accès exceptionnel : messages.
$string['msg_request_subject'] = 'Demande d\'accès : {$a->student} — {$a->activity}';
$string['msg_request_body'] = '{$a->student} demande un accès exceptionnel à « {$a->activity} » ({$a->course}).

Message : {$a->reason}';
$string['msg_noreason'] = '(aucun message)';
$string['msg_accepted_subject'] = 'Accès accordé : {$a->activity}';
$string['msg_accepted_body'] = 'Ton enseignant t\'a ouvert « {$a->activity} » jusqu\'au {$a->until}.';
$string['msg_refused_subject'] = 'Demande refusée : {$a->activity}';
$string['msg_refused_body'] = 'Ton enseignant n\'a pas accepté ta demande d\'accès à « {$a->activity} ».';
$string['msg_comment'] = 'Commentaire de l\'enseignant : {$a}';

// Vie privée.
$string['privacy:path'] = 'Accès exceptionnels';
$string['privacy:metadata:grant'] = 'Demandes d\'accès exceptionnel à une activité fermée, et décisions des enseignants.';
$string['privacy:metadata:grant:userid'] = 'L\'élève qui demande l\'accès.';
$string['privacy:metadata:grant:reason'] = 'Le message de l\'élève.';
$string['privacy:metadata:grant:status'] = 'L\'état de la demande (en attente, accordée, refusée, retirée).';
$string['privacy:metadata:grant:requestedat'] = 'La date de la demande.';
$string['privacy:metadata:grant:decidedby'] = 'L\'enseignant qui a décidé.';
$string['privacy:metadata:grant:decidedat'] = 'La date de la décision.';
$string['privacy:metadata:grant:decisioncomment'] = 'Le commentaire de l\'enseignant.';
$string['privacy:metadata:grant:timestart'] = 'Le début de l\'accès accordé.';
$string['privacy:metadata:grant:timeend'] = 'La fin de l\'accès accordé.';
$string['privacy:metadata:messages'] = 'Les demandes et les décisions sont envoyées en notifications Moodle.';
$string['privacy:path_supervised'] = 'Activités surveillées';
$string['privacy:metadata:gate'] = 'Ouvertures d\'activités surveillées décidées par les enseignants.';
$string['privacy:metadata:gate:scopeid'] = 'L\'élève visé, pour une ouverture individuelle.';
$string['privacy:metadata:gate:openedby'] = 'L\'enseignant qui a ouvert l\'activité.';
$string['privacy:metadata:gate:timeopened'] = 'La date d\'ouverture.';
$string['privacy:metadata:gate:closeat'] = 'La fermeture automatique prévue.';
$string['privacy:metadata:gate:closedby'] = 'L\'enseignant qui a fermé l\'activité.';
$string['privacy:metadata:gate:timeclosed'] = 'La date de fermeture.';

// Activités surveillées (ouvertes par l'enseignant, en classe).
$string['classhours:supervise'] = 'Ouvrir et fermer les activités surveillées';
$string['task_collect_supervised'] = 'Activités surveillées : ramassage du travail en cours à la fermeture';
$string['task_close_supervised'] = 'Activités surveillées : fermeture automatique à l\'heure prévue';
$string['submit_closed_supervised'] = 'Cette activité se fait en classe : la remise n\'est possible que lorsque l\'enseignant l\'ouvre.';
$string['supervised_menu'] = 'Activités surveillées';
$string['supervised_intro'] = 'Une activité surveillée reste fermée aux élèves tant que vous ne l\'ouvrez pas. Ouvrez-la en classe pour tout le cours, un groupe ou un élève, avec ou sans durée ; à la fermeture, les tentatives de test en cours sont envoyées et les brouillons de devoir sont remis. Après fermeture, un élève déjà noté peut rouvrir l\'activité pour lire sa note et son feedback, sans pouvoir remettre.';
$string['supervised_conditiondisabled'] = 'La condition d\'accès « Activité surveillée » n\'est pas installée ou pas activée : aucune activité ne peut être surveillée (Administration du site → Plugins → Restrictions d\'accès).';
$string['supervised_live'] = 'En direct';
$string['supervised_none'] = 'Aucune activité surveillée dans ce cours. Choisissez-les ci-dessous.';
$string['supervised_open'] = 'Ouverte';
$string['supervised_closed'] = 'Fermée';
$string['supervised_until'] = 'jusqu\'à {$a}';
$string['supervised_untilclosed'] = 'jusqu\'à fermeture';
$string['supervised_open_button'] = 'Ouvrir';
$string['supervised_close'] = 'Fermer l\'activité';
$string['supervised_close_one'] = 'Fermer';
$string['supervised_closeall'] = 'Tout fermer';
$string['supervised_confirm_close'] = 'Fermer maintenant ? Les tentatives de test en cours seront envoyées et les brouillons de devoir remis.';
$string['supervised_confirm_closeall'] = 'Fermer toutes les activités ouvertes du cours ? Les tentatives de test en cours seront envoyées et les brouillons de devoir remis.';
$string['supervised_scope'] = 'Pour';
$string['supervised_scope_students'] = 'Élèves';
$string['supervised_duration'] = 'Durée';
$string['supervised_duration_manual'] = 'Jusqu\'à ce que je ferme';
$string['supervised_duration_slot'] = 'Jusqu\'à la fin du créneau';
$string['supervised_noslot'] = 'Aucun créneau des heures de cours n\'est en cours pour ces élèves : choisissez une autre durée.';
$string['supervised_counters_quiz'] = '{$a->active} tentative(s) en cours, {$a->done} envoyée(s) depuis l\'ouverture';
$string['supervised_counters_assign'] = '{$a->active} brouillon(s) en cours, {$a->done} remis depuis l\'ouverture';
$string['supervised_collect_help'] = 'Le ramassage se fait dans la minute qui suit la fermeture ; l\'activité, elle, est fermée aux élèves immédiatement.';
$string['supervised_opened_done'] = '« {$a} » est ouverte.';
$string['supervised_closed_done'] = '« {$a} » est fermée : le travail en cours sera ramassé dans la minute.';
$string['supervised_closedall_done'] = '{$a} activité(s) fermée(s) : le travail en cours sera ramassé dans la minute.';
$string['supervised_notsupervised'] = 'Cette activité n\'est pas surveillée.';
$string['supervised_selection'] = 'Choisir les activités surveillées';
$string['supervised_selection_help'] = 'Une activité cochée reçoit la restriction « Activité surveillée » : elle est fermée tant que vous ne l\'ouvrez pas. La décocher retire la restriction ; l\'activité redevient libre.';
$string['supervised_supervise'] = 'Surveillée';
$string['supervised_history'] = 'Historique';
$string['supervised_openedby'] = 'Ouverte par';
$string['block_none'] = 'Aucune activité surveillée n\'est ouverte pour le moment.';
$string['block_opennow'] = 'Ouverte maintenant';
$string['block_start'] = 'Commencer';
$string['block_teacher_open'] = 'Vos activités ouvertes';
$string['block_manage'] = 'Piloter';
$string['block_moreoptions'] = 'Plus d\'options (groupe, élève, durée)…';
$string['block_nosupervised'] = 'Aucune activité surveillée dans ce cours.';
