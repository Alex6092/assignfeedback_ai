<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Contrôle des tentatives';
$string['menu'] = 'Contrôle des tentatives';
$string['attemptcheck:view'] = 'Consulter le contrôle des tentatives du cours';
$string['attemptcheck:delete'] = 'Supprimer une tentative ou effacer une remise depuis le contrôle des tentatives';
$string['attemptcheck:notify'] = 'Recevoir les notifications de tentatives à vérifier';
$string['messageprovider:suspicious'] = 'Tentative à vérifier (contrôle des tentatives)';
$string['task_analyse'] = 'Contrôle des tentatives : analyse d\'une tentative remise';

// Vie privée.
$string['privacy:metadata:review'] = 'Tentatives et remises jugées légitimes par un enseignant.';
$string['privacy:metadata:review:userid'] = 'Élève auteur de la tentative.';
$string['privacy:metadata:review:itemtype'] = 'Type d\'activité (test ou devoir).';
$string['privacy:metadata:review:itemid'] = 'Tentative ou remise concernée.';
$string['privacy:metadata:review:status'] = 'Décision de l\'enseignant.';
$string['privacy:metadata:review:reviewerid'] = 'Enseignant qui a pris la décision.';
$string['privacy:metadata:review:timemodified'] = 'Date de la décision.';
$string['privacy:metadata:message'] = 'Les enseignants reçoivent une notification quand une tentative déclenche un indicateur.';

// Réglages.
$string['settings_intro'] = 'Les indicateurs repèrent des tentatives à examiner ; ils ne prouvent pas une triche. Le rapport se consulte dans chaque cours (« Contrôle des tentatives »).';
$string['setting_notify'] = 'Notifier les enseignants';
$string['setting_notify_desc'] = 'À chaque remise qui déclenche un indicateur, les enseignants du cours reçoivent une notification avec un lien vers le rapport.';
$string['setting_fastratio'] = 'Rapidité : seuil (% de la médiane)';
$string['setting_fastratio_desc'] = 'Une tentative (ou un devoir, du premier accès à la remise) plus courte que ce pourcentage de la durée médiane des autres élèves est signalée.';
$string['setting_questionratio'] = 'Par question : seuil (% de la médiane)';
$string['setting_questionratio_desc'] = 'Une question rédigée traitée en moins de ce pourcentage du temps médian des autres élèves est signalée.';
$string['setting_minrefs'] = 'Élèves de référence nécessaires';
$string['setting_minrefs_desc'] = 'Nombre minimal d\'autres élèves ayant terminé pour comparer à la classe.';
$string['setting_wpm'] = 'Vitesse d\'écriture maximale (mots/min)';
$string['setting_wpm_desc'] = 'Un texte apparu plus vite que cette vitesse n\'a vraisemblablement pas été tapé (collage). Repère : un élève tape 20 à 40 mots par minute.';
$string['setting_minwords'] = 'Taille minimale d\'un ajout de texte (mots)';
$string['setting_minwords_desc'] = 'Les ajouts de texte plus courts ne sont pas évalués (trop peu de mots pour mesurer une vitesse).';
$string['setting_textqtypes'] = 'Types de question rédigés';
$string['setting_textqtypes_desc'] = 'Types de question dont la rédaction est analysée (noms techniques séparés par des virgules).';

// Indicateurs.
$string['duration_s'] = '{$a} s';
$string['duration_min'] = '{$a->m} min {$a->s} s';
$string['duration_h'] = '{$a->h} h {$a->m} min';
$string['signal_offslot'] = 'Hors créneau';
$string['signal_fast'] = 'Rapide';
$string['signal_question'] = 'Question rapide';
$string['signal_typing'] = 'Écriture';
$string['signal_minduration'] = 'Durée minimale';
$string['text_offslot_start'] = 'commencée hors créneau ({$a})';
$string['text_offslot_end'] = 'terminée hors créneau ({$a})';
$string['text_offslot_submit'] = 'remise hors créneau ({$a})';
$string['text_fast'] = '{$a->duration} contre {$a->median} en médiane ({$a->refs} élèves)';
$string['text_question'] = 'question {$a->number} : {$a->time} contre {$a->median} en médiane';
$string['text_typing'] = '{$a->words} mots apparus en {$a->time} ({$a->wpm} mots/min)';
$string['text_typing_question'] = 'question {$a->number} : {$a->words} mots apparus en {$a->time} ({$a->wpm} mots/min)';
$string['text_minduration'] = '{$a->duration}, minimum attendu {$a->minimum}';

// Notification.
$string['notify_subject'] = 'Tentative à vérifier : {$a->student} — {$a->activity}';
$string['notify_body'] = 'La tentative de {$a->student} pour « {$a->activity} » ({$a->course}) déclenche des indicateurs :
{$a->signals}

Ce n\'est pas une preuve : à examiner dans le contrôle des tentatives.
{$a->url}';
$string['notify_small'] = 'Tentative à vérifier : {$a->student} — {$a->activity}';
$string['notify_linkname'] = 'Contrôle des tentatives';

// Rapport.
$string['intro'] = 'Tentatives de test et remises de devoir à examiner. Un indicateur n\'est pas une preuve : un élève peut être rapide, avoir préparé son texte ou le dicter. Consultez la tentative avant de décider.';
$string['info_noschedule'] = 'Indicateur « hors créneau » inactif : aucun créneau n\'est configuré dans les heures de cours de ce cours.';
$string['info_nologs'] = 'Le journal standard n\'est pas activé : premier accès aux devoirs et vitesse d\'écriture sont estimés plus grossièrement.';
$string['allactivities'] = 'Tous les tests et devoirs';
$string['filter_flagged'] = 'Tentatives signalées';
$string['filter_all'] = 'Toutes les tentatives';
$string['scope_restricted'] = 'Hors créneau : activités restreintes';
$string['scope_all'] = 'Hors créneau : toutes les activités';
$string['showlegit'] = 'Afficher les tentatives jugées légitimes';
$string['filter'] = 'Filtrer';
$string['minduration_label'] = 'Durée minimale attendue :';
$string['minduration_help'] = 'Une tentative plus courte est signalée, même sans autre élève de référence. 0 : pas de minimum.';
$string['minduration_saved'] = 'Durée minimale enregistrée.';
$string['nothing_found'] = 'Aucune tentative à afficher.';
$string['count_shown'] = '{$a} tentative(s) affichée(s).';
$string['student'] = 'Élève';
$string['attempt'] = 'Tentative';
$string['attempt_number'] = 'n° {$a}';
$string['timestart'] = 'Début';
$string['timeend'] = 'Fin';
$string['duration'] = 'Durée';
$string['signals'] = 'Indicateurs';
$string['legit'] = 'Légitime';
$string['action_legit'] = 'Légitime';
$string['action_unlegit'] = 'À revoir';
$string['bulk_legit'] = 'Marquer la sélection légitime';
$string['bulk_delete'] = 'Supprimer la sélection…';
$string['legit_done'] = '{$a} tentative(s) marquée(s) légitime(s).';
$string['unlegit_done'] = '{$a} tentative(s) remise(s) à examiner.';
$string['type_quiz'] = 'test';
$string['type_assign'] = 'devoir';

// Suppression.
$string['nothing_selected'] = 'Aucune tentative sélectionnée.';
$string['confirm_heading'] = 'Supprimer ces tentatives ?';
$string['confirm_warning'] = 'Test : la tentative est supprimée et la note recalculée. Devoir : le contenu de la remise, la note et le feedback IA sont effacés ; l\'élève pourra redéposer. La note effacée est transmise au carnet de notes (et à EFE, qui reçoit une note vide). Cette action est définitive.';
$string['confirm_delete'] = 'Supprimer ({$a})';
$string['cannotdelete'] = 'Vous n\'avez pas le droit de la supprimer';
$string['delete_done'] = '{$a} tentative(s) supprimée(s).';
$string['delete_failed'] = '{$a} n\'ont pas pu être supprimée(s).';
$string['error_notfound'] = 'Tentative introuvable dans ce cours.';
$string['error_teamsubmission'] = 'Les remises de groupe ne sont pas prises en charge.';
