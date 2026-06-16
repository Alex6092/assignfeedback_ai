<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Missions client IA';

// Capacités.
$string['aimissions:generate']  = 'Générer des missions client IA';
$string['aimissions:review']    = 'Relire et publier les missions générées';
$string['aimissions:askclient'] = 'Poser une question au client IA';

// Privacy.
$string['privacy:metadata'] = 'Le plugin local_aimissions transmet au LLM (via local_aifeedback) le dossier projet (une entreprise fictive et l\'historique des missions générées) ainsi que les questions des étudiants. Les questions au client IA sont stockées pour afficher le fil de discussion.';
$string['privacy:metadata:ticket'] = 'Questions posées par un étudiant au client IA, et les réponses.';
$string['privacy:metadata:ticket:userid'] = 'L\'étudiant ayant posé la question.';
$string['privacy:metadata:ticket:question'] = 'La question posée au client IA.';
$string['privacy:metadata:ticket:answer'] = 'La réponse du client IA.';
$string['privacy:metadata:llm'] = 'Données envoyées au LLM pour générer les missions et répondre aux questions.';
$string['privacy:metadata:llm:dossier'] = 'Le dossier projet (entreprise fictive, historique des sprints, technologies).';
$string['privacy:metadata:llm:question'] = 'La question de l\'étudiant transmise au LLM.';

// Réglages.
$string['settings_heading']       = 'Missions client IA';
$string['settings_intro']         = 'Ce plugin réutilise la connexion LLM globale configurée dans le plugin Correction IA (local_aifeedback). Les réglages ci-dessous n\'ajustent que la génération des missions.';
$string['setting_model']          = 'Modèle de génération (optionnel)';
$string['setting_model_desc']     = 'Modèle utilisé pour générer les missions. Laisser vide pour utiliser le modèle global de local_aifeedback. Un modèle économique suffit en général pour la génération.';
$string['setting_maxsprints']     = 'Nombre maximum de sprints par projet';
$string['setting_maxsprints_desc'] = 'Plafond du nombre de sprints qu\'un même projet peut accumuler (sécurité).';

// Profils de client.
$string['persona_neutre']       = 'Neutre / coopératif';
$string['persona_exigeant']     = 'Exigeant';
$string['persona_imprecis']     = 'Imprécis / flou';
$string['persona_versatile']    = 'Change souvent d\'avis';
$string['persona_lent']         = 'Lent à répondre';
$string['persona_nontechnique'] = 'Non technique';

// Types d'événements.
$string['event_besoin'] = 'Changement de besoin';
$string['event_bug']    = 'Bug critique signalé';
$string['event_rgpd']   = 'Nouvelle réglementation (RGPD)';
$string['event_budget'] = 'Réduction de budget';

// Statuts mission / job.
$string['status_pending'] = 'En attente';
$string['status_running'] = 'En cours';
$string['status_done']    = 'Terminé';
$string['status_failed']  = 'Échec';
$string['mission_draft']     = 'Brouillon (caché)';
$string['mission_published'] = 'Publié';
$string['mission_archived']  = 'Archivé';

// Pont EFE.
$string['efe_unavailable'] = 'Le plugin EFE Notes (local_efenotes) n\'est pas installé ; le report des compétences est désactivé pour les missions générées.';
$string['efe_attached']    = 'Compétence {$a} rattachée au devoir pour le report EFE.';

// Erreurs de génération.
$string['error_maxsprints']      = 'Le nombre maximum de sprints pour ce projet est atteint.';
$string['error_llm_invalid']     = 'Le LLM n\'a pas renvoyé de mission exploitable (titre, demande ou critères manquants).';
$string['error_assign_creation'] = 'La création du devoir a échoué.';

// Formulaire de génération.
$string['form_intro']            = 'Choisissez la compétence à faire travailler, le niveau et les groupes. Une demande client (cahier des charges) sera générée par l\'IA pour chaque groupe, déposée en devoir caché, et corrigée automatiquement au dépôt. Relisez puis publiez depuis l\'écran « Missions générées ».';
$string['form_target']           = 'Cible pédagogique';
$string['form_module']           = 'Module / matière';
$string['form_module_help']      = 'Le module ou la matière concernée (ex. « Développement web », « Réseaux », « Cybersécurité »). Sert à orienter le contexte de la demande client.';
$string['form_competency']       = 'Compétence évaluée (EFE)';
$string['form_competency_help']  = 'La compétence du référentiel EFE à faire travailler. Son libellé guide la génération (sans être nommé dans l\'énoncé) et son code est positionné sur le devoir pour le report automatique vers EFE à la correction.';
$string['form_competency_choose'] = 'Choisir une compétence…';
$string['form_competencylabel']  = 'Compétence à faire travailler';
$string['form_competencylabel_help'] = 'Décrivez la compétence visée (ex. « Concevoir une base de données relationnelle »). Le LLM construira un besoin métier qui l\'exerce sans la nommer. (EFE non disponible : pas de report de compétence.)';
$string['form_level']            = 'Niveau';
$string['form_complexity']       = 'Complexité';
$string['complexity_easy']       = 'Découverte';
$string['complexity_medium']     = 'Intermédiaire';
$string['complexity_hard']       = 'Avancé';
$string['form_constraints']      = 'Nombre de contraintes';
$string['form_persona']          = 'Profil du client';
$string['form_persona_help']     = 'Le caractère du client fictif. Il colore le ton des demandes (et plus tard ses réponses aux questions). Le profil est fixé à la création du projet d\'un groupe.';
$string['form_groups_heading']   = 'Groupes cibles';
$string['form_groups_help']      = 'Un projet (entreprise fictive) distinct est généré pour chaque groupe : les missions diffèrent d\'un groupe à l\'autre (individualisation anti-triche).';
$string['form_nogroups']         = 'Aucun groupe dans ce cours. Créez d\'abord des groupes (Participants → Groupes) : chaque groupe aura sa propre entreprise cliente.';
$string['form_submit']           = 'Générer les missions';
$string['error_nogroup']         = 'Sélectionnez au moins un groupe.';
$string['error_nocompetency']    = 'Indiquez une compétence (référentiel EFE ou libellé libre).';
$string['jobs_queued']           = '{$a} génération(s) mise(s) en file. Elles seront traitées au prochain passage du cron.';

// Page de statut.
$string['status_title']          = 'Génération de missions — suivi';
$string['status_newgeneration']  = '+ Nouvelle génération';
$string['status_managemissions'] = 'Missions générées';
$string['status_nojobs']         = 'Aucune génération pour l\'instant.';
$string['status_col_created']    = 'Lancée le';
$string['status_col_status']     = 'Statut';
$string['status_col_result']     = 'Mission';
$string['status_col_log']        = 'Journal';

// Page de gestion / publication.
$string['manage_title']          = 'Missions générées';
$string['manage_noprojects']     = 'Aucune mission générée pour ce cours.';
$string['manage_nogroup']        = 'Sans groupe';
$string['manage_col_sprint']     = 'Sprint';
$string['manage_col_title']      = 'Mission';
$string['manage_col_status']     = 'Statut';
$string['manage_col_actions']    = 'Actions';
$string['manage_publish']        = 'Publier';
$string['manage_hide']           = 'Masquer';
