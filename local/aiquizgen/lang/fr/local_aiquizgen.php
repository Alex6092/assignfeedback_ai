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
$string['form_intro']       = 'Choisissez une source de contenu (un PDF que vous uploadez, ou une leçon Moodle déjà présente dans le cours) et le nombre de questions à générer. Le LLM produira les questions, qui seront déposées dans une nouvelle catégorie de la banque du quiz, puis ajoutées à un quiz prêt à l\'emploi.';

$string['source_heading']      = 'Source';
$string['source_type']         = 'Type de source';
$string['source_type_help']    = 'Choisissez d\'où vient le contenu que le LLM va exploiter pour rédiger les questions.';
$string['source_type_pdf']     = 'Document PDF (uploadé)';
$string['source_type_lesson']  = 'Leçon Moodle du cours';

$string['source_pdf']       = 'Document PDF';
$string['source_pdf_help']  = 'PDF d\'où le LLM extraira la matière des questions (chapitre de cours, fiche TD, polycopié, etc.). Un seul fichier.';

$string['source_lesson']        = 'Leçon';
$string['source_lesson_help']   = 'Leçon du cours dont le contenu (titres + textes des pages, et illustrations significatives si la vision est activée dans les réglages globaux) sera transmis au LLM.';
$string['source_lesson_choose'] = 'Choisir une leçon';
$string['source_lesson_none']   = 'Aucune leçon n\'est disponible dans ce cours.';

$string['counts_heading']        = 'Types et nombres';
$string['mcqcount']              = 'QCM standard Moodle';
$string['mcqcount_help']         = 'Nombre de questions à choix multiple (type Moodle standard) à générer à partir de la source. Entre 0 et 50. Met 0 si tu ne veux que des réponses courtes IA.';
$string['shortanswercount']      = 'Réponses courtes corrigées par IA';
$string['shortanswercount_help'] = 'Nombre de questions à réponse courte (1 à 3 phrases) corrigées automatiquement par le LLM (type qtype_aishortanswer). Entre 0 et 50. À utiliser avec parcimonie : chaque copie d\'étudiant déclenchera un appel au LLM à la soumission du quiz.';
$string['essaycount']            = 'Compositions corrigées par IA';
$string['essaycount_help']       = 'Nombre de sujets de composition (1 à 2 pages rédigées par l\'étudiant) corrigés automatiquement par le LLM (type qtype_aiessay). Entre 0 et 10. À utiliser TRÈS parcimonieusement : la correction d\'une composition est longue (plusieurs secondes par copie), et les questions ouvertes longues sont plus difficiles à noter de manière homogène par un LLM.';

$string['answersselectcount']        = 'QCM à pool aléatoire';
$string['answersselectcount_help']   = 'Nombre de questions à pool aléatoire (plugin tiers qtype_answersselect de Joseph Rézeau). Chaque question contient un grand pool de bonnes réponses et un grand pool de distracteurs ; à chaque tentative, le système en tire X et Y au hasard. Entre 0 et 50. Combiné au tirage aléatoire de questions (mode « Tirage aléatoire par tentative »), donne une très grande variabilité entre étudiants.';
$string['answersselect_plugin_missing'] = 'Le plugin tiers qtype_answersselect n\'est pas installé sur ce site. Pour activer la génération de QCM à pool aléatoire, installe-le depuis https://moodle.org/plugins/qtype_answersselect.';

$string['coderunnercount']           = 'Exercices CodeRunner';
$string['coderunnercount_help']      = 'Nombre d\'exercices de programmation (plugin tiers qtype_coderunner de Richard Lobb). Chaque exercice contient un énoncé, une fonction de référence et 3 à 5 tests automatiques. Le code de l\'étudiant est exécuté dans un sandbox (Jobe) à la soumission. Entre 0 et 20 (génération gourmande en tokens). <strong>Important :</strong> la solution générée par le LLM peut comporter des erreurs ; pense à cliquer « Check » dans l\'éditeur de chaque question pour valider que les tests passent contre la solution de référence avant publication.';
$string['coderunnerlanguage']        = 'Langage cible';
$string['coderunnerlanguage_help']   = 'Langage de programmation pour TOUS les exercices CodeRunner de cette génération. Les variantes « function / method » impliquent que l\'étudiant écrit UNE FONCTION (le driver/main est généré par CodeRunner), ce qui est plus pédagogique pour des étudiants BTS qu\'un programme complet.';
$string['coderunnerlang_python3']      = 'Python 3 (fonction)';
$string['coderunnerlang_c_function']   = 'C (fonction)';
$string['coderunnerlang_cpp_function'] = 'C++ (fonction)';
$string['coderunnerlang_java_method']  = 'Java (méthode statique)';
$string['coderunner_plugin_missing']   = 'Le plugin tiers qtype_coderunner n\'est pas installé sur ce site. Pour activer la génération d\'exercices de programmation auto-évalués, installe-le depuis https://moodle.org/plugins/qtype_coderunner. Note : ce plugin nécessite aussi un serveur de sandbox (Jobe) pour exécuter le code.';

$string['variation_heading']         = 'Variation entre étudiants';
$string['variation_mode']            = 'Mode de variation';
$string['variation_mode_help']       = 'En mode <strong>fixe</strong>, tous les étudiants voient les mêmes questions dans le même ordre. En mode <strong>aléatoire</strong>, chaque tentative tire un sous-ensemble aléatoire des questions générées (variabilité étudiant/étudiant). Le mode aléatoire reste compatible avec d\'éventuels plugins de QCM à pool de réponses aléatoires.';
$string['variation_mode_fixed']      = 'Quiz fixe (mêmes questions pour tous)';
$string['variation_mode_random']     = 'Tirage aléatoire par tentative';
$string['randomperattempt']          = 'Questions tirées par tentative';
$string['randomperattempt_help']     = 'Nombre de questions tirées au hasard dans la catégorie générée à chaque tentative. Doit être inférieur ou égal au nombre total de questions générées (un pool plus grand que le tirage donne plus de variabilité).';
$string['error_randomperattempt_min'] = 'Au moins 1 question par tentative.';
$string['error_randomperattempt_max'] = 'Le tirage ne peut pas dépasser le nombre total de questions générées.';

$string['dest_heading']     = 'Destination';
$string['quizname']         = 'Titre du quiz à créer';
$string['quizname_help']    = 'Le quiz sera créé dans la section générale du cours, avec ce titre. Vous pourrez le déplacer et l\'ajuster ensuite.';

$string['generate_button']  = 'Générer le test';

// Erreurs de validation
$string['error_total_min']                  = 'Demande au moins une question (QCM ou réponse courte).';
$string['error_mcqcount_negative']          = 'Le nombre de QCM ne peut pas être négatif.';
$string['error_mcqcount_min']               = 'Au moins 1 QCM si tu en demandes.';
$string['error_mcqcount_max']               = 'Maximum 50 QCM par génération.';
$string['error_shortanswercount_negative']  = 'Le nombre de réponses courtes ne peut pas être négatif.';
$string['error_shortanswercount_max']       = 'Maximum 50 réponses courtes par génération.';
$string['error_essaycount_negative']        = 'Le nombre de compositions ne peut pas être négatif.';
$string['error_essaycount_max']             = 'Maximum 10 compositions par génération (coûteuses à corriger).';
$string['error_answersselectcount_negative'] = 'Le nombre de QCM à pool aléatoire ne peut pas être négatif.';
$string['error_answersselectcount_max']      = 'Maximum 50 QCM à pool aléatoire par génération.';
$string['error_coderunnercount_negative']    = 'Le nombre d\'exercices CodeRunner ne peut pas être négatif.';
$string['error_coderunnercount_max']         = 'Maximum 20 exercices CodeRunner par génération (gourmand en tokens).';
$string['error_source_required']        = 'Vous devez fournir un fichier PDF source.';
$string['error_source_lesson_required'] = 'Vous devez choisir une leçon.';
$string['error_source_lesson_invalid']  = 'La leçon sélectionnée n\'appartient pas à ce cours.';

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
$string['source_lesson_missing'] = 'La leçon source est introuvable (elle a été supprimée du cours ?).';
$string['source_lesson_empty']   = 'La leçon ne contient aucun texte exploitable.';
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
