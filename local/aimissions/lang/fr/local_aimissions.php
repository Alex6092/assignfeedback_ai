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
$string['manage_delete']         = 'Supprimer';
$string['manage_delete_confirm'] = 'Supprimer la mission « {$a} » ? Le devoir associé sera supprimé du cours, ainsi que les éventuelles remises et notes des étudiants. Le projet du groupe reviendra au sprint précédent (vous pourrez le régénérer). Cette action est irréversible.';
$string['manage_deleted']        = 'Mission supprimée.';
$string['manage_orphan']         = 'Devoir supprimé (orpheline)';

// Suppression d'un job sur la page de statut.
$string['status_col_actions']    = 'Actions';
$string['status_deletejob']      = 'Retirer';

// Tickets — questions au client IA.
$string['ticket_title']          = 'Questions au client';
$string['ticket_navlabel']       = 'Contacter le client';
$string['ticket_noproject']      = 'Aucun projet n\'est encore associé à votre groupe. Le client se manifestera dès qu\'une première mission aura été publiée.';
$string['ticket_chooseproject']  = 'Vous appartenez à plusieurs projets. Choisissez celui à consulter :';
$string['ticket_empty']          = 'Aucune question pour l\'instant. Posez la première au client !';
$string['ticket_pending']        = 'Le client réfléchit…';
$string['ticket_failed']         = 'Le client n\'a pas pu répondre pour le moment. Réessayez un peu plus tard.';
$string['ticket_yourquestion']   = 'Votre question au client';
$string['ticket_send']           = 'Envoyer au client';
$string['ticket_readonly']       = 'Consultation en lecture seule (enseignant).';
$string['manage_viewtickets']    = 'Questions au client ({$a})';

// Confidentialité (champs additionnels).
$string['privacy:metadata:ticket:timecreated'] = 'La date de la question.';
$string['privacy:metadata:job']         = 'Jobs de génération de missions lancés par un enseignant.';
$string['privacy:metadata:job:userid']  = 'L\'enseignant ayant lancé la génération.';
$string['privacy:metadata:job:timecreated'] = 'La date de lancement de la génération.';
$string['privacy:path:tickets']  = 'Questions au client IA';
$string['privacy:path:jobs']     = 'Générations de missions';

// Événements (le client se manifeste).
$string['error_event_noproject'] = 'Le projet ciblé par l\'événement est introuvable.';
$string['event_title']           = 'Injecter un événement client';
$string['event_intro']           = 'Le client se manifeste spontanément avec un imprévu. Une communication cohérente avec le projet sera générée pour chaque cible, en attente de votre publication.';
$string['event_noprojects']      = 'Aucun projet dans ce cours. Générez d\'abord au moins une mission.';
$string['event_type']            = 'Type d\'événement';
$string['event_target']          = 'Cible';
$string['event_target_all']      = 'Tous les groupes / projets';
$string['event_hint']            = 'Précision (optionnel)';
$string['event_hint_ph']         = 'Ex. : « la nouvelle contrainte concerne les données des mineurs »';
$string['event_submit']          = 'Générer l\'événement';
$string['status_eventjob']       = 'Événement';
$string['manage_injectevent']    = '+ Injecter un événement';
$string['manage_events']         = 'Événements client';
$string['event_published']       = 'Publié';
$string['event_pending']         = 'En attente';

// Réglage : compétence « Communiquer ».
$string['setting_commcomp']      = 'Code compétence « Communiquer » (EFE)';
$string['setting_commcomp_desc'] = 'Code EFE de la compétence de communication (ex. C01) reportée lors de l\'évaluation de la communication client. Laisser vide pour désactiver cette évaluation.';

// Évaluation de la communication client.
$string['manage_evalcomm']       = 'Évaluer la communication';
$string['comm_title']            = 'Évaluation de la communication client';
$string['comm_intro']            = 'Évalue la communication de chaque étudiant à partir des messages qu\'il a adressés au client. Relis les niveaux proposés, puis envoie-les vers EFE (compétence « Communiquer »).';
$string['comm_nocode']           = 'Le code de la compétence « Communiquer » n\'est pas configuré. Renseigne-le dans les réglages du plugin (Administration → Plugins locaux → Missions client IA) pour activer cette évaluation.';
$string['comm_nostudents']       = 'Aucun étudiant dans le groupe de ce projet.';
$string['comm_col_student']      = 'Étudiant';
$string['comm_col_tickets']      = 'Messages';
$string['comm_col_level']        = 'Niveau';
$string['comm_col_comment']      = 'Commentaire';
$string['comm_col_status']       = 'Statut';
$string['comm_evaluate']         = 'Évaluer la communication';
$string['comm_send']             = 'Envoyer vers EFE';
$string['comm_evaluated']        = '{$a} étudiant(s) évalué(s).';
$string['comm_sent']             = '{$a} positionnement(s) envoyé(s) vers EFE.';
$string['comm_sendfailed']       = '{$a} envoi(s) en échec (voir les logs).';
$string['comm_status_draft']     = 'À envoyer';
$string['comm_status_sent']      = 'Envoyé';
$string['comm_notickets']        = 'Aucun message exploitable pour cet étudiant.';
$string['comm_efelabel']         = 'Communication client — {$a}';
$string['colour_vert']           = 'Vert';
$string['colour_bleu']           = 'Bleu';
$string['colour_jaune']          = 'Jaune';
$string['colour_rouge']          = 'Rouge';

// Confidentialité (évaluation communication).
$string['privacy:metadata:commeval']             = 'Évaluations de la communication client d\'un étudiant (compétence transversale).';
$string['privacy:metadata:commeval:userid']      = 'L\'étudiant évalué.';
$string['privacy:metadata:commeval:colour']      = 'Le niveau (couleur) attribué.';
$string['privacy:metadata:commeval:score']       = 'Le score estimé (0-100).';
$string['privacy:metadata:commeval:comment']     = 'Le commentaire d\'évaluation.';
$string['privacy:metadata:commeval:timecreated'] = 'La date de l\'évaluation.';
$string['privacy:path:commeval'] = 'Évaluations de communication client';
