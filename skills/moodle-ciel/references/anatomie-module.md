# Anatomie d'un parcours et d'un module

Relevé sur les parcours Cyber/Pentest (2e année, 12 modules + projet) et IA (1re année, 6 modules),
tels qu'ils sont configurés dans Moodle. Les réglages de chaque rôle sont dans `reglages-moodle.md`.

## Le module type

| Ordre | Rôle | Activité Moodle | Rôle pédagogique | EFE |
|---|---|---|---|---|
| 1 | `lecon` | Leçon (`mod_lesson`) | apprendre la notion ; exercices « À toi ! » non notés | non |
| 2 | `video` *(optionnel)* | Vidéo + QCM (`mod_videoqcm`) | visualiser un concept dynamique difficile, à la maison | non |
| 3 | `entrainement` | Test (`mod_quiz`) | s'entraîner autant qu'on veut, à la maison | **jamais** |
| 4 | `tp` (1 ou 2) | Devoir (`mod_assign`) | appliquer, produire un livrable, avec le Tuteur IA | oui, 1 code + ponctualité |
| 5 | `quiz-ia` *(optionnel)* | Test (`mod_quiz`) | 1–2 questions IA d'analyse courte (« Lis le scan ») | oui |
| 6 | `sommatif` | Test (`mod_quiz`) | évaluation en classe, surveillée | oui, 1 code |

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
| QCM d'entraînement | 1 min par question tirée, + 2 min par réponse courte IA, + 5 min par composition IA |
| TP | 1 à 3 h, avec un livrable |
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
- un récapitulatif (« ✅ À retenir ») et la transition vers la suite (TP, test).

## Les TP

Deux formats existent dans les parcours de l'enseignant. **Fais-le choisir au cadrage**, ne le
déduis pas : le format change la durée, le livrable et ce que la Correction IA peut juger.

| Format | Parcours | Durée | Ce que fait l'élève | Remise |
|---|---|---|---|---|
| **TP de labo** | Cyber | 1 à 3 h | une manipulation en phases sur le labo (`phase`), avec un livrable et des preuves | PDF ou texte en ligne |
| **Exercice court** | IA | 20 à 40 min | une seule tâche : **transférer** la notion à un cas nouveau (« décris le réseau pour Fashion-MNIST ») ou **analyser des résultats fournis** (« 3 entraînements, 3 durées : quel compromis ? ») | texte en ligne |

- L'exercice court ne dépend pas de l'exécution du code en salle : les résultats sont donnés dans
  l'énoncé. C'est le bon choix quand les postes sont lents, ou quand la notion se vérifie mieux par
  un raisonnement que par une manipulation. Il ne recopie pas l'exemple de la leçon : un cas voisin
  oblige à transférer.
- 1 TP par module, 2 si le module a deux facettes (attaque puis défense : « TP 6.1 Compromettre un
  compte » / « TP 6.2 Concevoir une politique d'authentification » ; concevoir puis analyser).
- Chaque TP vise **une** compétence (un code EFE) ; son barème est centré sur elle.
- Un **fil rouge** est bienvenu : dans Cyber, chaque TP alimente une page du rapport d'audit final.
- Le TP n'est faisable qu'en classe (restriction automatique aux heures de cours des activités qui
  remontent à EFE) : prévoir un livrable réalisable dans les séances, la fin pouvant se faire à la
  maison avant l'échéance si l'enseignant ouvre un accès.
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
| Leçon | `Titre` | `Reconnaissance & cartographie (Nmap)` |
| Vidéo | `Vidéo - Titre` | `Vidéo - Les états d'un port` |
| QCM d'entraînement | `QCM - Titre` | `QCM - Reconnaissance & cartographie (Nmap)` |
| TP | `TP n - Titre` (ou `TP n.m`) | `TP 2 - Cartographier ta cible (surface d'attaque)` |
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
| Évaluation finale | `FIN-A1 — Titre` (partie A, question 1 ; `FIN-Dossier` pour la mise en situation) | `IA-FIN-A1` | `IA-FINALE` + compétence(s) |

Ces conventions sont celles du parcours IA (le parcours Cyber écrit `TF-A1` / `CY-TF-A1` pour sa
finale) ; la banque d'entraînement IA, générée par aiquizgen, n'avait ni idnumber ni étiquette.

## La carte du parcours

Elle est validée avant tout contenu. Elle contient (gabarit `templates/carte-parcours.md`) :
- public, volume, prérequis, matériel ;
- le tableau des modules : durée, objectifs, leçon (pages), TP (compétence et code EFE),
  test (code EFE, appariement), vidéo proposée et pourquoi ;
- l'évaluation finale et le projet éventuel ;
- la couverture prévisionnelle : compétences × activités notées, et les blocs d'examen couverts ;
- le planning : dates prévues des sommatifs et échéances des TP, à partir du début du cours ;
- les points à faire trancher par l'enseignant.
