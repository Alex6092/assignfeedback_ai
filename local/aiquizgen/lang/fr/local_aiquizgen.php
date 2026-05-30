<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']         = 'Générateur de tests IA';
$string['generate_menu']      = 'Générer un test IA';

// Capability
$string['aiquizgen:generate'] = 'Générer un test IA depuis une leçon ou un PDF';

// Privacy
$string['privacy:metadata']   = 'Le plugin local_aiquizgen ne stocke pas de données personnelles directement. Les sources fournies par l\'enseignant (leçon Moodle, PDF) sont transmises au LLM via local_aifeedback.';

// -----------------------------------------------------------
//  Formulaire de génération
// -----------------------------------------------------------
$string['form_intro']       = 'Choisissez une source de contenu et le nombre de questions à générer. Le LLM produira les questions, qui seront déposées dans une nouvelle catégorie de la banque de questions du cours, puis ajoutées à un quiz prêt à l\'emploi.';

$string['source_heading']   = 'Source';
$string['source_pdf']       = 'Document PDF';
$string['source_pdf_help']  = 'PDF d\'où le LLM extraira la matière des questions (chapitre de cours, fiche TD, polycopié, etc.). Un seul fichier pour l\'instant.';

$string['counts_heading']   = 'Types et nombres';
$string['mcqcount']         = 'QCM standard Moodle';
$string['mcqcount_help']    = 'Nombre de questions à choix multiple (type Moodle standard) à générer à partir de la source. Entre 1 et 50. Les autres types (réponse courte IA, composition IA, QCM à pool aléatoire) seront ajoutés dans les étapes suivantes de développement.';

$string['dest_heading']     = 'Destination';
$string['quizname']         = 'Titre du quiz à créer';
$string['quizname_help']    = 'Le quiz sera créé dans la section générale du cours, avec ce titre. Vous pourrez le déplacer et l\'ajuster ensuite.';

$string['generate_button']  = 'Générer le test';

// Erreurs de validation
$string['error_mcqcount_min']    = 'Au moins 1 question est requise.';
$string['error_mcqcount_max']    = 'Maximum 50 questions par génération.';
$string['error_source_required'] = 'Vous devez fournir un fichier PDF source.';

// -----------------------------------------------------------
//  Page de statut
// -----------------------------------------------------------
$string['status_pagetitle']      = 'Statut de la génération';

$string['status_pending']        = 'En attente';
$string['status_running']        = 'En cours';
$string['status_done']           = 'Terminé';
$string['status_failed']         = 'Échec';

$string['status_pending_help']   = 'Le job est en file d\'attente. La génération démarrera à la prochaine exécution du cron.';
$string['status_running_help']   = 'Le LLM est en train de traiter votre demande. Cette page se rafraîchit automatiquement toutes les 5 secondes.';
$string['status_done_help']      = 'Le test a été généré avec succès. Vous pouvez maintenant ouvrir le quiz ou la catégorie créée et ajuster.';
$string['status_failed_help']    = 'La génération a échoué.';
$string['status_lasterror']      = 'Dernière erreur :';

$string['status_label_status']   = 'Statut';
$string['status_label_created']  = 'Créé le';
$string['status_label_attempts'] = 'Tentatives';

$string['open_quiz']             = 'Ouvrir le quiz';
$string['open_category']         = 'Ouvrir la catégorie de questions';
$string['back_to_form']          = '← Retour au formulaire de génération';

// -----------------------------------------------------------
//  Erreurs de pipeline (handler)
// -----------------------------------------------------------
$string['source_missing']        = 'Le PDF source associé à ce job est introuvable dans le stockage Moodle.';
$string['source_empty']          = 'Aucun texte n\'a pu être extrait du PDF source. Le document est probablement scanné/image, sans couche texte.';
$string['llm_no_questions']      = 'Le LLM n\'a renvoyé aucune question exploitable.';
$string['quiz_creation_failed']  = 'La création du module quiz a échoué.';
$string['untitled_question']     = 'Question sans titre';

// -----------------------------------------------------------
//  Métadonnées créées
// -----------------------------------------------------------
$string['category_info']         = 'Catégorie générée automatiquement par le plugin local_aiquizgen.';
$string['quiz_intro_generated']  = 'Quiz généré automatiquement par IA. À relire et ajuster avant publication.';

// -----------------------------------------------------------
//  Log (affichage sur la page de statut)
// -----------------------------------------------------------
$string['status_label_log']      = 'Journal de génération';
