# Anatomie d'un parcours et d'un module

Relevé sur les parcours Cyber/Pentest (2e année, 12 modules + projet) et IA (1re année, 6 modules),
tels qu'ils sont configurés dans Moodle. Les réglages de chaque rôle sont dans `reglages-moodle.md`.

## Le module type

| Ordre | Rôle | Activité Moodle | Rôle pédagogique | EFE |
|---|---|---|---|---|
| 1 | `lecon` | Leçon (`mod_lesson`) | apprendre la notion ; exercices « À toi ! » non notés | non |
| 2 | `video` *(optionnel)* | Vidéo + QCM (`mod_videoqcm`) | visualiser un concept dynamique difficile, à la maison | non |
| 3 | `td` — **TD** | Test (`mod_quiz`) | mettre en œuvre la leçon pas à pas sur un cas pratique : textes de guidage et questions à indices, 20 à 30 min | **jamais** (non noté) |
| 4 | `tp` (1 ou 2) — **TP d'entraînement** | Devoir (`mod_assign`) | appliquer, produire un livrable, **à la maison**, avec le Tuteur IA ; l'IA est tolérée | **non** |
| 5 | `entrainement` — **QCM d'entraînement** | Test (`mod_quiz`) | s'entraîner autant qu'on veut, à la maison, avec des questions du style du sommatif | **jamais** |
| 6 | `quiz-ia` *(optionnel)* | Test (`mod_quiz`) | 1–2 questions IA d'analyse courte (« Lis le scan ») | oui |
| 7 | `tp` surveillé (1 ou 2) — **TP évalué** | Devoir (`mod_assign`) | évaluation en classe, **fermée avant la séance** (code de séance, sans mode examen), sujet inédit, sans Tuteur IA | oui, 1 code N2/N3 |
| 8 | `tp` surveillé — **TP bonus** (au moins 1) | Devoir (`mod_assign`) | prolongement pour les élèves en avance, **ouvert à ceux qui ont rendu les TP évalués**, noté lui aussi | oui, 1 code N2/N3 |
| 9 | `sommatif` | Test (`mod_quiz`) | évaluation de connaissances en classe, surveillée (code de séance **et** mode examen) | oui, 1 code |

Ressources possibles dans la section : page ou fichier (extrait de rapport, jeu de données, sujet en
PDF). Le parcours commence par une section 0 (page d'accueil, liens utiles, ressources communes) et
finit par une évaluation finale.

Tout le module est suivi par le **bloc Avancement** : chaque activité y a son rang et les activités
notées une échéance. C'est aussi lui qui publie la correction des sommatifs.

## Durées de référence

| Élément | Durée |
|---|---|
| Module | 3 à 8 h de cours (Cyber ≈ 6–8 h, IA ≈ 1 h 30 à 3 h) |
| Lecture de la leçon | 15 à 45 min |
| TD | **20 à 30 min** (estimation du script de contrôle : 1,5 min par texte ou question courte, 2 à 3 min par appariement, ordre ou réponse à calculer, 7 min par programme CodeRunner) |
| QCM d'entraînement | 1 min par question tirée, + 2 min par réponse courte IA, + 5 min par composition IA |
| TP d'entraînement | 1 à 3 h, avec un livrable (à la maison) |
| TP évalués en classe | **environ 2 h par séance de 3 h** pour l'ensemble des TP évalués du module (une séance de module : 1 TP long, ou 2 TP à la suite), durées **indicatives** |
| TP bonus | 45 à 60 min, en plus |
| Test sommatif | 15 min (tiers-temps : 20 min) |
| Évaluation finale | 45 à 60 min |

## La leçon : une page ou plusieurs ?

C'est toujours une **leçon** (`mod_lesson`), même d'une seule page : l'achèvement « fin de la leçon
atteinte » suit la progression. Ensuite :

- **Une seule page par défaut.** Les élèves préfèrent tout voir d'un tenant : moins de navigation,
  et ils retrouvent plus vite une information. Les leçons IA font une page de 9 000 à 12 000
  caractères de HTML.
- **Plusieurs pages** quand la leçon dépasse environ 12 000 caractères de HTML (au-delà de
  15 minutes de lecture), ou quand elle enchaîne des notions distinctes qu'on gagne à aborder une à
  une. Coupe alors **par notion**, en 4 à 7 pages de taille comparable (Cyber : 7 pages de 1 500 à
  2 500 caractères). La page 1 porte les objectifs ; la dernière, le récapitulatif et la transition.
- Justifie le choix dans la carte du parcours (« 1 page : 9 k caractères », « 6 pages : 6 notions »).

Contenu attendu (détails dans `style-html.md`) :
- page 1 : titre du module et encadré d'objectifs selon le modèle du parcours, « métier ou labo »
  (🎯 ce que tu vas maîtriser, 🛠 au menu, 🎓 compétence travaillée, puis « Où on en est ») ou
  « programmation » (🎯, 🐍 Python au menu, 📦 données) ; **jamais de durée** (elle est dans la
  fiche enseignant) ;
- des sections courtes, des exemples, du code commenté, des encadrés astuce/danger ;
- des exercices « ➡️ À toi ! » non notés, avec indice et résultat attendu ;
- là où une capture d'écran aiderait (fenêtre d'un logiciel, console, étape d'installation), un
  encadré **📸 Capture suggérée** qui dit ce qu'elle doit montrer ; la leçon s'appelle alors
  « Titre (TODO : Captures) » jusqu'à ce que l'enseignant les ait faites (`style-html.md` § 3) ;
- un récapitulatif (« ✅ À retenir ») et la transition vers la suite (TD, TP, test).

## Le TD (travail dirigé)

Le TD vient juste après la leçon. Il fait **mettre en œuvre** les notions de la leçon, pas à pas,
avant que l'élève ne travaille seul (TP d'entraînement) puis ne soit évalué (TP évalués, sommatif).
C'est un test Moodle (rôle `td`) **non évalué** : ni EFE, ni surveillance, ni note dans le carnet
(note maximale 0), tentatives illimitées, comportement **interactif avec tentatives multiples** (bouton
« Vérifier », indice, nouvel essai), tout sur une seule page, corrections visibles.

- **Durée : 20 à 30 minutes** de travail de l'élève. Le script de contrôle estime la durée d'après le
  nombre et le type des éléments ; compte 8 à 14 éléments, dont 6 à 15 questions.
- **Contextualisé** : un **cas pratique** unique, cohérent et convaincant, du métier d'un technicien
  CIEL (une PME qui équipe ses bornes, le service informatique d'un lycée, un objet connecté, un
  atelier…). Le premier texte le pose : qui, quel besoin, ce qu'on va construire ensemble. Chaque
  question fait avancer ce cas ; le dernier texte fait le bilan (« ce que tu as construit, ce que tu
  sauras faire seul dans les TP »). Le cas est **à lui** : ni le sujet d'un TP évalué, ni une question
  du sommatif, ni le fil rouge des TP d'entraînement s'il risque d'en dévoiler un.
- **Didactisé** : on explique avant de faire faire. Chaque étape est un texte de guidage (question
  `description` : la notion utile, rappelée en quelques lignes avec un mini-exemple, puis ce qu'on va
  faire) suivi d'une ou deux questions. Les questions montent en difficulté : **reconnaître**
  (QCM), **prévoir** (« que va afficher… » : réponse numérique ou courte), **compléter** (une ligne
  manquante, un ordre à rétablir), **écrire** (une courte question CodeRunner avec un squelette
  `preload`, 2 au plus). Les textes de guidage sont intercalés, jamais tous en tête.
- **Indices** : chaque question a 1 ou 2 indices (`hints`), qui s'affichent après un essai faux. Le
  premier renvoie à la notion (« relis la règle de la division entière »), le second est plus précis,
  aucun ne donne la réponse. Le feedback de chaque mauvaise réponse nomme l'erreur probable ; le
  feedback général explique la bonne réponse (il est montré : le TD est un apprentissage).
- **Pas de question corrigée par IA** : sa correction est différée et coûte un appel au LLM ; le TD
  vit de corrections immédiates.
- **Diagrammes** (UML, SysML, architecture, maquette de fenêtre) : générés avec PlantUML et embarqués
  dans l'énoncé ou dans les réponses d'un QCM (`files` de `build-questions.mjs`), voir `style-html.md`
  § 3. Jamais de boîtes dessinées en caractères, ni dans l'énoncé ni dans les réponses.
- **Fichiers** : `td.questions.mjs` (source, format de `build-questions.mjs`) → `td.xml` ; dans le
  manifeste, une activité de rôle `td` placée juste après la leçon, questions dans l'ordre du fichier
  (catégorie `Mx - TD`). Les solutions CodeRunner se contrôlent avec
  `verifier-tpe.mjs <module> --questions td.questions.mjs`. Gabarit : `templates/td/td.questions.mjs` ;
  exemple réel : `templates/exemples-cpp/td-M2.questions.mjs`.

## Les TP : entraînement, évalués, bonus

Un module comporte trois sortes de devoirs. **Ne les mélange pas** : la raison d'être de la
distinction est qu'un devoir fait à la maison avec une IA ne mesure rien.

| | TP d'entraînement | TP évalué en classe | TP bonus |
|---|---|---|---|
| Où | à la maison | en classe, séance ouverte par l'enseignant | en classe, après les TP évalués |
| Accès | ouvert | **fermé avant la séance** (activité surveillée : code de séance, **sans mode examen** : la leçon et le TD restent consultables) | comme le TP évalué, **et** seulement pour ceux qui ont **rendu les TP évalués** du module (`requires`) |
| Sujet | peut annoncer la suite, utilise le fil rouge | **inédit** : autre contexte, mêmes compétences ; ne figure ni dans la leçon, ni dans le TD, ni dans un autre TP | inédit, plus ouvert |
| Aide | Tuteur IA, IA tolérée | **pas de Tuteur IA** | pas de Tuteur IA |
| Notation | Correction IA | Correction IA, **note directe** | Correction IA, note directe |
| EFE | **non** | oui, 1 code N2/N3, **report actif** | oui, 1 code N2/N3, **report actif** |
| Nom | `TP d'entrainement Mn - Titre` (`Mn.k` s'il y en a deux) | `TP évalué Mn.k - Titre` | `TP bonus Mn - Titre` |
| Nombre | 1 ou 2 | **1 ou 2** (1 si le module est court) | au moins 1 |

Dans le manifeste, tous restent de rôle `tp` ; ce sont les champs `supervised`, `efe`, `requires` et
`overrides` qui les distinguent (`reglages-moodle.md`, `deploiement.md`). Les TP évalués et le bonus se
**génèrent** avec `scripts/make-tpe.mjs` à partir d'un fichier de données `tp-evalues.mjs`
(modèles : `templates/tp-evalue/tp-evalues.mjs`, exemple réel `templates/exemples-cpp/tp-evalues-M2.mjs`) :
un seul manifeste `tp-evalues.deploy.json`, TP évalués puis bonus (`requires` = leurs clés).

### Écrire un TP évalué

- **Volume** : demande au cadrage la part d'une séance consacrée aux TP évalués (exemple Cpp1 :
  environ 2 h sur 3 h). Le total des durées indicatives des TP évalués d'un module doit tenir dans
  cette part. Les durées vont dans la fiche enseignant, **jamais dans la consigne** : un élève qui
  lit « 45 minutes » se règle sur le temps annoncé.
- **Élèves occupés** : découpe chaque sujet en **3 ou 4 niveaux** (`Niveau 1 — …` à `Niveau 4 (défi)
  — …`), à faire dans l'ordre. Les premiers niveaux assurent la maîtrise de base, les suivants font
  monter la note, le dernier est un défi. La consigne dit « si tu n'as pas terminé un niveau, rends
  ce qui fonctionne : tout ce qui est correct est compté ». Un élève rapide ne manque jamais de
  travail, un élève lent n'est pas bloqué.
- **Formats qui alternent** (au moins deux par module quand il y a deux TP) : **Réaliser** (un
  programme ou une production d'après un cahier des charges), **Réparer** (un code fourni avec des
  défauts voulus : chaque correction est commentée `// CORRECTION : …` avec sa cause), **Compléter**
  (un squelette à `// TODO`), **Concevoir** (choisir des types, un diagramme), **Valider** (écrire et
  dérouler une fiche de recette), **Analyser** (sans code : sorties de commandes à interpréter). Les
  formats « Réparer » et « Compléter » sont plus courts et plus difficiles à faire faire à une IA
  sans comprendre.
- **Sujet inédit et dans le périmètre** : un contexte jamais vu (le fil rouge du cours n'apparaît pas),
  les seules notions de la leçon. Quand la consigne utilise une aide hors leçon (une fonction de
  bibliothèque, par exemple), elle la donne en « Rappel » dans la consigne, comme le font les exercices.
- **Barème sur 100**, par niveau : le niveau de base pèse environ 60 %, les niveaux supérieurs le
  reste ; un élève qui s'arrête avant le dernier niveau peut tout de même obtenir 80 %. Chaque ligne
  du barème a ses points ; le générateur refuse un total différent de 100.
- **Un corrigé de code est compilé et exécuté** (`scripts/verifier-tpe.mjs`) avec des jeux d'essai ;
  un programme « à réparer » doit compiler **et** échouer à au moins un test ; un squelette à trous
  doit se comporter comme prévu (compiler ou non). Un corrigé Qt est contrôlé en syntaxe seulement.
- **Compétence EFE** : un code N3 par TP, choisi pour **varier** d'un TP à l'autre et par rapport au
  sommatif du module (le TP « Réparer » rapporte volontiers C08.5, le « Compléter » C04.4.1, le
  « Valider » C06.3, le diagnostic réseau C11.2). Vérifie les codes avec `moodle_get_efe_competences`.
- **Pas de ponctualité** : la séance fermée la rend sans objet.
- **Le sujet ne circule pas** : pas dans la leçon, pas dans une annonce, pas dans le plan du cours
  visible des élèves. Les absents passent le TP à une autre séance ; prévoir une variante.
- **TP bonus** : réservé à ceux qui ont déjà rendu les TP évalués (la consigne le dit, et la
  condition d'accès `requires` l'impose), noté et reporté à EFE, 4 niveaux, environ une heure, sur les
  mêmes notions ; il ne remplace jamais un TP évalué.

### Les formats de contenu

Deux formats de contenu existent dans les parcours de l'enseignant, valables pour un TP
d'entraînement comme pour un TP évalué. **Fais-le choisir au cadrage**, ne le
déduis pas : le format change la durée, le livrable et ce que la Correction IA peut juger.

| Format | Parcours | Durée | Ce que fait l'élève | Remise |
|---|---|---|---|---|
| **TP de labo** | Cyber | 1 à 3 h | une manipulation en phases sur le labo (`phase`), avec un livrable et des preuves | PDF ou texte en ligne |
| **Exercice court** | IA | 20 à 40 min | une seule tâche : **transférer** la notion à un cas nouveau (« décris le réseau pour Fashion-MNIST ») ou **analyser des résultats fournis** (« 3 entraînements, 3 durées : quel compromis ? ») | texte en ligne |

- L'exercice court ne dépend pas de l'exécution du code en salle : les résultats sont donnés dans
  l'énoncé. C'est le bon choix quand les postes sont lents, ou quand la notion se vérifie mieux par
  un raisonnement que par une manipulation. Il ne recopie pas l'exemple de la leçon : un cas voisin
  oblige à transférer.
- 1 TP de chaque sorte par module, 2 si le module a deux facettes (attaque puis défense : « TP 6.1
  Compromettre un compte » / « TP 6.2 Concevoir une politique d'authentification » ; concevoir puis
  analyser ; réaliser puis réparer).
- Chaque TP **évalué** vise **une** compétence (un code EFE) ; son barème est centré sur elle. Un TP
  d'entraînement porte les mêmes compétences dans « compétences évaluées » (champ de la Correction IA)
  mais ne remonte pas à EFE.
- Un **fil rouge** est bienvenu dans les TP d'entraînement : dans Cyber, chaque TP alimente une page
  du rapport d'audit final. Les TP évalués s'en détachent (sujet inédit).
- Un TP évalué n'est faisable qu'en classe (activité surveillée, restriction automatique aux heures de
  cours des activités qui remontent à EFE) : son livrable tient dans la séance. Le TP d'entraînement se
  fait à la maison.
- **Temps d'exécution** : si le TP fait tourner du code (entraînement d'un modèle, scan), estime sa
  durée sur les postes de la salle (demande-la au cadrage) et ne demande que ce qui tient dans la
  séance. Une valeur que tu n'as pas pu mesurer va dans la fiche enseignant, « à vérifier sur un
  poste ».
- Modèles : `templates/tp/`, `templates/exemples-ia/tp-M3-1-moodle.json` et `tp-M4-1-moodle.json`
  (exercices courts).

## Les tests sommatifs

- **6 questions, 20 points, 15 minutes**, une compétence rapportée à EFE.
- **Un test par module**, ou **un test pour deux modules** quand chacun seul serait trop léger ou
  qu'ils évaluent la même compétence. Exemples Cyber : énumération + élévation de privilèges (M3 +
  M8), exploitation + pentest web (M5 + M7), traces + durcissement (M9 + M10). Le test apparié se
  place dans la section du second module et a son propre dossier (`T4-enumeration-elevation/`).
- Il ne porte que sur ce qui a été enseigné et entraîné ; il ne doit pas surprendre par sa forme
  (l'entraînement contient le même genre de questions, IA comprises). Une notion que la leçon
  présente en **bonus** (« pour aller plus loin, hors évaluation ») n'apparaît dans aucune activité
  notée.
- Ses questions portent **majoritairement sur la compétence rapportée à EFE** (au moins la moitié
  des points) : c'est elle que la note doit refléter. Le script de contrôle le vérifie par les
  étiquettes.
- Planification : une date prévue par test (séance surveillée), dans l'ordre du parcours, **à la
  séance qui suit l'échéance des TP du module** (exemple IA : TP rendus le lundi 9 à 12 h, test le
  lundi 16 à 9 h 20). Le même jour que l'échéance, l'élève finit ses TP au lieu de réviser.
- Modèle : `templates/exemples-ia/sommatif-M1.xml` et sa fiche.

## L'évaluation finale

- 13 questions environ, 40 points ramenés sur 20, 45 à 60 minutes, surveillée comme un sommatif.
- Parties A, B, C… qui reprennent les blocs du parcours ; une composition IA de synthèse en fin.
- Une mise en situation commune (question `description` sans points, « dossier ») donne du sens à
  toutes les questions : une entreprise, un cahier des charges, des exigences E1–E3.
- Elle rapporte une compétence transversale (C10 dans les deux parcours), et complète la couverture
  des compétences que les tests de module n'ont pas prises.

## Le projet de fin de parcours (optionnel)

Missions client IA (`local_aimissions`, voir `outils-ia.md`) : un projet par groupe, en sprints,
chaque sprint étant un devoir corrigé par IA avec sa compétence EFE (Cyber : C03 → C04 → C10 → C11
→ C01). Les groupes doivent exister dans le cours avant la génération.

## Nommage dans Moodle

| Élément | Modèle | Exemple |
|---|---|---|
| Section | `Mx - Titre` | `M2 - Reconnaissance & cartographie (Nmap)` |
| Leçon | `Titre`, suivi de ` (TODO : Captures)` tant que des captures suggérées restent à faire | `Reconnaissance & cartographie (Nmap)`, `Premiers pas avec Qt (TODO : Captures)` |
| Vidéo | `Vidéo - Titre` | `Vidéo - Les états d'un port` |
| TD | `TD Mn - Titre du cas pratique` | `TD M2 - La borne de recharge du parking` |
| QCM d'entraînement | `QCM - Titre` | `QCM - Reconnaissance & cartographie (Nmap)` |
| TP d'entraînement | `TP d'entrainement Mn - Titre` (`Mn.k` s'il y en a deux) | `TP d'entrainement M3.2 - Le salon de discussion` |
| TP évalué en classe | `TP évalué Mn.k - Titre` | `TP évalué M2.1 - Le distributeur de boissons` |
| TP bonus | `TP bonus Mn - Titre` | `TP bonus M2 - Le duel du nombre mystère` |
| Test IA court | titre parlant | `Lis le scan` |
| Test sommatif | `Test sommatif : Titre` (+ `(M3 + M8)` si apparié) | `Test sommatif : Reconnaissance & cartographie` |
| Évaluation finale | `Evaluation finale - Parcours X` | `Evaluation finale - Parcours IA` |

Numérote les modules à partir de M0 (cadre, méthodologie, règles) si le parcours en a besoin, sinon
à partir de M1. Les tests sommatifs sont numérotés T1, T2… dans l'ordre de passage, ce qui fixe les
idnumbers des questions (`CY-T3-Q5`).

| Questions | Nom | idnumber | Étiquettes |
|---|---|---|---|
| Test sommatif | `T5-Q1 — Titre` | `IA-T5-Q1` | `IA-T5` + compétence(s) `C08` |
| Banque d'entraînement | `M5-E01 — Titre` | `IA-M5-E01` | `IA-M5` + compétence |
| TD (textes et questions, dans l'ordre) | `M5-TD01 — Titre` | `IA-M5-TD01` | `IA-M5-TD` + compétence (pas d'étiquette sur un texte) |
| Évaluation finale | `FIN-A1 — Titre` (partie A, question 1 ; `FIN-Dossier` pour la mise en situation) | `IA-FIN-A1` | `IA-FINALE` + compétence(s) |

Ces conventions sont celles du parcours IA (le parcours Cyber écrit `TF-A1` / `CY-TF-A1` pour sa
finale) ; la banque d'entraînement IA, générée par aiquizgen, n'avait ni idnumber ni étiquette.

## La carte du parcours

Elle est validée avant tout contenu. Elle contient (gabarit `templates/carte-parcours.md`) :
- public, volume, prérequis, matériel ;
- le tableau des modules : durée, objectifs, leçon (pages), **TD** (cas pratique), TP
  d'entraînement, **TP évalués** (titre, format, compétence et code EFE) et TP bonus, test (code
  EFE, appariement), vidéo proposée et pourquoi ; la part de séance consacrée aux TP évalués ;
- l'évaluation finale et le projet éventuel ;
- la couverture prévisionnelle : compétences × activités notées, et les blocs d'examen couverts ;
- le planning : dates prévues des sommatifs et échéances des TP, à partir du début du cours ;
- les points à faire trancher par l'enseignant.
