<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']         = 'Correction IA';
$string['privacy:metadata']   = 'Le plugin Correction IA transmet le texte des soumissions à un LLM externe et stocke le feedback JSON résultant.';

$string['enabled']      = 'Correction IA';
$string['enabled_help'] = 'Si activé, le devoir est corrigé par une IA qui peut laisser des commentaires de feedback pour chaque soumission.';

$string['default']                  = 'Activé par défaut';
$string['default_help']             = 'Si activé, la Correction IA sera active pour tous les nouveaux devoirs.';
$string['sharedconfignote']         = 'Les réglages communs (URL de l\'API, modèle, clé API, vision, binaires) sont gérés dans le plugin <em>Correction IA (bibliothèque partagée)</em> — voir <strong>Administration → Plugins → Plugins locaux → Correction IA (bibliothèque partagée)</strong>.';
$string['apiurl']                   = 'URL de l\'API LM Studio';
$string['apiurl_help']              = 'Point d\'accès compatible OpenAI. Défaut : http://localhost:1234/v1/chat/completions';
$string['model']                    = 'Nom du modèle';
$string['model_help']               = 'Identifiant du modèle utilisé dans les appels API, ex. qwen3.5-9b-instruct.';
$string['apikey']                   = 'Clé API';
$string['apikey_help']              = 'Clé secrète envoyée dans l\'en-tête HTTP "Authorization: Bearer ...". Laisser vide si le serveur n\'en demande pas (cas par défaut de LM Studio). La valeur est chiffrée en base.';
$string['apiurl_override']          = 'Remplacer l\'URL de l\'API du site';
$string['model_override']           = 'Remplacer le nom du modèle du site';
$string['apikey_override']          = 'Remplacer la clé API du site';
$string['defaultsystemprompt']      = 'Prompt système par défaut';
$string['defaultsystemprompt_help'] = 'Utilisé quand aucun prompt n\'est défini au niveau du devoir.';
$string['pdftotextpath']            = 'Chemin du binaire pdftotext';
$string['pdftotextpath_help']       = 'Chemin absolu vers l\'exécutable pdftotext (paquet poppler-utils). Laisser vide pour une détection automatique dans /usr/bin, /usr/local/bin, etc.';
$string['pdftotextmissing']         = 'Le binaire pdftotext est introuvable sur le serveur. Installez le paquet poppler-utils ou renseignez le chemin dans les réglages du plugin.';
$string['pdftotexterror']           = 'Erreur d\'extraction du texte PDF.';

// === Vision ===
$string['vision_heading']               = 'Vision (lecture d\'images)';
$string['vision_enabled']               = 'Activer l\'envoi d\'images au modèle';
$string['vision_enabled_help']          = 'Si activé, les images contenues dans les soumissions (PDF avec illustrations, images embarquées dans l\'éditeur en ligne, fichiers PNG/JPEG joints, etc.) sont envoyées au LLM avec le texte. Nécessite un modèle multimodal côté LM Studio.';
$string['vision_enabled_override']      = 'Remplacer le réglage Vision du site';
$string['maximagespersubmission']       = 'Nombre maximum d\'images par soumission';
$string['maximagespersubmission_help']  = 'Plafond global d\'images envoyées au LLM pour une seule soumission étudiante. Permet de contrôler la consommation de contexte et le temps de génération. Défaut : 5.';
$string['imagemindimension']            = 'Taille minimale d\'image (pixels)';
$string['imagemindimension_help']       = 'Les images extraites d\'un PDF dont la largeur OU la hauteur est inférieure à cette valeur sont ignorées (filtre les vignettes décoratives). Défaut : 200.';
$string['pdftoppmpath']                 = 'Chemin du binaire pdftoppm';
$string['pdftoppmpath_help']            = 'Chemin absolu vers l\'exécutable pdftoppm (paquet poppler-utils), utilisé pour rasteriser les pages de PDF contenant des images. Laisser vide pour détection auto.';
$string['pdfimagespath']                = 'Chemin du binaire pdfimages';
$string['pdfimagespath_help']           = 'Chemin absolu vers l\'exécutable pdfimages (paquet poppler-utils), utilisé pour détecter les pages contenant des images. Laisser vide pour détection auto.';

$string['systemprompt']           = 'Prompt système';
$string['systemprompt_help']      = 'Instructions envoyées à l\'IA en rôle « system ».';
$string['exercise']               = 'Énoncé de l\'exercice';
$string['exercise_help']          = 'Le texte de l\'exercice auquel l\'étudiant devait répondre.';
$string['expectedanswer']         = 'Corrigé attendu / barème';
$string['expectedanswer_help']    = 'Réponse de référence que l\'IA utilisera pour évaluer les copies.';
$string['competencies']           = 'Compétences évaluées';
$string['competencies_help']      = 'Compétences ou critères que l\'IA doit évaluer, une par ligne.';

$string['nofeedback']            = 'Aucun feedback IA généré pour l\'instant.';
$string['feedbackerror']         = 'Erreur de lecture du feedback IA. Veuillez régénérer.';
$string['generate']              = 'Générer le feedback IA';
$string['regenerate']            = 'Régénérer le feedback IA';
$string['strengths']             = 'Points forts';
$string['improvements']          = 'Points à améliorer';
$string['detailedfeedback']      = 'Feedback détaillé';
$string['competency_scores']     = 'Scores par compétence';
$string['competency']            = 'Compétence';
$string['mastery_level']         = 'Niveau de maîtrise';
$string['commentary']            = 'Commentaire';
$string['score']                 = 'Score';

$string['nosubmissiontext']      = '(Aucun texte de soumission trouvé)';
$string['noconfiguration']       = 'La Correction IA n\'est pas configurée pour ce devoir.';
$string['generationerror']       = 'Erreur lors de l\'appel à l\'IA. Vérifiez la connexion LM Studio.';
$string['generationsuccess']     = 'Feedback IA généré avec succès.';
$string['generationpending']     = 'Génération du feedback IA en cours…';
$string['generationfailed']      = 'Échec de la génération';
$string['queued']                = 'Feedback ajouté à la file de génération.';
$string['queuedcount']           = '{$a} génération(s) ajoutée(s) à la file.';
$string['lockcontention']        = 'Un autre appel IA est en cours, retenter plus tard.';

$string['taskname']              = 'Génération différée du feedback IA';

// Page de gestion (manage.php)
$string['manage']                = 'Gérer';
$string['managepagetitle']       = 'Gestion des feedbacks IA';
$string['student']               = 'Étudiant';
$string['status']                = 'Statut';
$string['attempts']              = 'Tentatives';
$string['lasterror']             = 'Dernière erreur';
$string['lastupdate']            = 'Dernière mise à jour';
$string['actions']               = 'Actions';
$string['retry']                 = 'Relancer';
$string['statuspending']         = 'En cours';
$string['statusgenerated']       = 'Généré';
$string['statusfailed']          = 'Échec';
$string['nofbrow']               = 'Aucune ligne';
$string['nosubmissions']         = 'Aucune soumission validée pour ce devoir.';
$string['requeuefailed']         = 'Relancer les échecs';
$string['requeueall']            = 'Tout regénérer';
$string['requeuemissing']        = 'Générer pour les soumissions sans feedback';
$string['countgenerated']        = '{$a} généré(s)';
$string['countpending']          = '{$a} en cours';
$string['countfailed']           = '{$a} échec(s)';
