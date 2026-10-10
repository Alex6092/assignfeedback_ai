# Réglages Moodle par rôle d'activité

Ces réglages sont ceux des parcours Cyber et IA dans Moodle (relevés le 9 octobre 2026), corrigés
des décisions de l'enseignant. Le déploiement les applique selon le `role` de chaque activité du
manifeste ; le manifeste ne contient que les écarts voulus (`overrides`).

Noms des champs : ceux des tables Moodle (`quiz`, `assign`, `lesson`…), pour qu'une vérification par
`moodle_get_module` se compare directement.

## Commun à toutes les activités

| Réglage | Valeur |
|---|---|
| Visibilité à la création | **masquée** (`visible = 0`) : l'enseignant publie |
| Achèvement | automatique (`completion = 2`), règle propre au rôle ci-dessous |
| Avancement | l'activité est ajoutée au bloc Avancement, dans l'ordre du parcours |
| Mode de groupe | aucun (sauf projet en groupes) |
| Description affichée sur la page de cours | non |

## `lecon` — leçon (`mod_lesson`)

| Champ | Valeur | Pourquoi |
|---|---|---|
| `custom` / `grade` | 1 / 0 | leçon non notée |
| `maxattempts` | 1 | |
| `review`, `retake`, `modattempts`, `practice` | 0 | pas de questions notées dans la leçon |
| `progressbar`, `displayleft` | 1 si plusieurs pages, sinon 0 | |
| `completionendreached` | 1 | achèvement « fin de la leçon atteinte » |
| `intro` | vide | |
| Pages | une page de contenu par bloc entre marqueurs, chaînées : page suivante / précédente, la dernière mène à la fin de la leçon | |

## `entrainement` — QCM d'entraînement (`mod_quiz`)

| Champ | Valeur | Pourquoi |
|---|---|---|
| `attempts` | 0 (illimitées) | s'entraîner autant qu'on veut |
| `grademethod` | 1 (note la plus haute) | |
| `delay1` / `delay2` | 86400 / 172800 (24 h puis 48 h) | éviter les tentatives en rafale pour « gratter » la note |
| `timelimit` | voir durées (`anatomie-module.md`) ; 10 min pour 10 questions | |
| `questionsperpage`, `navmethod`, `shuffleanswers` | 1, `free`, 1 | |
| `preferredbehaviour` | `deferredfeedback` | |
| Questions | **tirage aléatoire** : `draw` questions par tentative dans la catégorie de la banque (10 par défaut, 6 pour un petit module) | chaque tentative est différente |
| Relecture | pendant : la tentative ; juste après et plus tard : juste/faux, points, retours de chaque réponse, appréciation globale du test ; **feedback général des questions et bonne réponse : jamais** | l'élève qui veut progresser retourne chercher la réponse dans la leçon |
| Feedbacks des questions | le retour de chaque mauvaise réponse donne un **indice** (l'erreur probable, la notion à relire), jamais la bonne réponse ; le feedback général peut expliquer la réponse : il ne sera pas affiché | une tentative suivante redonne souvent la même question |
| `grade` | 100 | |
| `gradepass` | 0 | |
| Achèvement | une tentative faite (`completionminattempts = 1`) | |
| EFE, surveillance, heures de cours | **aucun** | fait à la maison |
| `showuserpicture`, `showblocks` | 0, 0 | |

## `tp` — devoir (`mod_assign`)

| Champ | Valeur | Pourquoi |
|---|---|---|
| `intro` (description) | vide : le plugin EFE y ajoute le bloc « Compétence évaluée » | |
| `activity` (Instructions de l'activité) | **la consigne élève** (HTML, `activityformat = 1`) | |
| `alwaysshowdescription` | 0 | |
| Remise | fichier PDF, 1 fichier (`assignsubmission_file`, `filetypeslist = .pdf`, `maxfilesubmissions = 1`), **ou** texte en ligne (`assignsubmission_onlinetext`) selon le livrable | |
| Commentaires de remise | activés | |
| `submissiondrafts` | 1 (bouton « Envoyer » obligatoire) | la correction IA part à l'envoi |
| `requiresubmissionstatement` | 0 (1 si l'enseignant veut la déclaration « travail personnel ») | |
| `maxattempts` / `attemptreopenmethod` | **2 / `manual`** | l'enseignant rouvre une 2e tentative s'il le juge utile |
| Note | **échelle « Barème officiel »** (4 niveaux) | |
| `duedate` | **fin de séance** (17 h 40 ou 17 h 45), ou 12 h ; `cutoffdate` = 0 | la date de rendu sert aussi au report EFE |
| Achèvement | remettre un travail (`completionsubmit = 1`) | |
| Plugins de feedback | commentaires, fichiers, **Correction IA** ; pas d'annotation PDF ni de feuille hors ligne | |
| Correction IA (`assignfeedback_ai`) | activée ; `systemprompt` **vide** (prompt par défaut du plugin, toujours à jour) ; `autograde = 1` (note appliquée directement ; l'enseignant passe en validation manuelle s'il le souhaite) ; `vision_enabled` = 0, ou 1 si la remise contient des captures à lire ; aucune surcharge d'URL, de modèle ou de clé | |
| Tuteur IA (`local_aichat`) | activé ; consigne **non** transmise (`includeintro = 0`) ; brief transmis (`includebrief = 1`) ; recherche web : aucune (`websearch = 0`), sauf recherche de matériel justifiée | |
| EFE | 1 code N2/N3 ; ponctualité activée sur un code C03.x ou C11.5 ; **reporter après la date de rendu : oui** | |

## `quiz-ia` — test court de questions IA (`mod_quiz`)

Ex. « Lis le scan », « Sous le capot » : 1 ou 2 questions IA d'analyse, notées.

| Champ | Valeur |
|---|---|
| `attempts` / `grademethod` | 1 / 1 |
| `timelimit` | 15 à 30 min, ou 0 |
| `preferredbehaviour` | `deferredfeedback` |
| Relecture | tout visible juste après et plus tard (y compris la bonne réponse) : c'est un exercice d'apprentissage |
| `grade` | 10 |
| EFE | 1 code N2/N3, reporter après la date de rendu |
| Échéance | date prévue dans l'Avancement |

## `sommatif` — test sommatif surveillé (`mod_quiz`)

| Champ | Valeur | Pourquoi |
|---|---|---|
| `timeopen` | 0 | c'est la surveillance qui ouvre |
| `timeclose` | **0** | la correction est publiée par le bloc Avancement, quand tout le monde a passé le test |
| `timelimit` | 0 | la durée vient de l'ouverture surveillée (15 min), qui gère le tiers-temps |
| `attempts` / `grademethod` / délais | 1 / 1 / 0 | |
| `questionsperpage`, `navmethod` | 1, `free` | |
| Sections | **une seule**, questions mélangées (`shufflequestions = 1`) ; réponses mélangées | |
| `preferredbehaviour` | `deferredfeedback` | |
| `showuserpicture` | 2 (grande photo) | contrôle d'identité en salle |
| `showblocks` | 0 | |
| Relecture | pendant : la tentative, points max ; juste après et plus tard : points max, note, feedback global ; **après fermeture : tout** | la correction complète attend sa publication |
| `grade` / `sumgrades` | 20 / 20 | |
| `gradepass` | 0 | |
| Achèvement | une tentative faite | |
| Condition d'accès | **activité surveillée** (`{"type":"supervised"}`) ; heures de cours ajoutées automatiquement (option EFE du cours) | |
| Options surveillées | **code de séance** et **mode examen** activés ; date prévue de la séance | le mode examen ferme le reste du cours : **pas de verrous** |
| EFE | 1 code N2/N3, reporter après la date de rendu ; pas de ponctualité | |
| Date de rendu (Avancement) | quelques jours après la date prévue (3 à 7 jours) | déclenche le report EFE |
| Durée minimale (contrôle des tentatives) | non réglée dans les parcours actuels ; 5 min si l'enseignant le demande | |

Publication de la correction : bouton « Correction » du bloc Avancement, à la main, ou
automatiquement quand toute la cohorte a rendu. Le bloc force la relecture complète, puis restaure
ces options quand on referme la correction.

## `finale` — évaluation finale (`mod_quiz`)

Comme `sommatif`, sauf :
- questions **dans l'ordre**, sans mélange (le dossier de mise en situation vient en premier) ;
- `sumgrades` = 40 (ou le total des points), `grade` = 20 ;
- durée de la séance surveillée : 45 à 60 min ;
- **même relecture que les sommatifs** : rien de la correction avant sa publication.

## `video` — vidéo + QCM (`mod_videoqcm`)

| Champ | Valeur |
|---|---|
| `youtubeurl` | vidéo YouTube (existante, ou produite et mise en ligne « non répertoriée » par l'enseignant) |
| `questions` | questions pendant la vidéo (JSON, voir `video.md`) |
| `livequestions` | QCM joué en classe (JSON), ou vide |
| `grade` | 10 (visionnage : +1 par bonne réponse aux questions de la vidéo) |
| `allowtablet` | 0 |
| Achèvement | terminer le visionnage (`completionfinish = 1`) |
| EFE | aucun par défaut (le QCM live peut remonter si l'enseignant le demande) |

Le visionnage est bloqué pendant les heures de cours par le réglage du site : c'est un travail à la
maison.

## `page`, `label`, `resource`, `url` — contenus d'accompagnement

Page d'accueil (section 0) : étiquette (`label`) ou page. Ressources : fichier (`resource`) ou lien.
Achèvement : à la consultation pour une ressource indispensable (jeu de données), sinon aucun.
