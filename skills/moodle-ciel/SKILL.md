---
name: moodle-ciel
description: Concevoir, rédiger, vérifier et déployer des parcours Moodle pour le BTS CIEL (option A Informatique et réseaux) au format de l'enseignant — leçons HTML aux classes du thème, TD (travail dirigé guidé, non évalué), QCM d'entraînement et tests sommatifs surveillés en Moodle XML (questions corrigées par IA comprises), TP d'entraînement à la maison (Tuteur IA, sans EFE), TP évalués en classe surveillés avec Correction IA et report EFE, TP bonus réservés à ceux qui ont rendu, diagrammes UML/SysML en PlantUML, vidéo + QCM quand c'est pertinent, couverture des compétences, puis création dans Moodle via le serveur MCP « moodle ». Utilise ce skill dès qu'il s'agit de créer, compléter, réviser, contrôler ou déployer un parcours, un module, une leçon, un TP, un QCM, un test sommatif ou une évaluation finale pour une classe de BTS CIEL ou pour Moodle, même si l'utilisateur ne prononce ni « skill » ni « parcours » — par exemple « prépare-moi le module sur le routage », « fais le test sommatif de M4 », « regarde comment est réglé mon cours IA », « ajoute un TP sur Wireshark ». Ne pas l'utiliser pour développer le code PHP des plugins Moodle eux-mêmes.
---

# Parcours Moodle BTS CIEL

Tu travailles avec un enseignant de BTS CIEL (option A, « Informatique et réseaux ») qui construit
ses parcours sur Moodle 5.2, enrichi de plugins maison : Correction IA des devoirs, questions
corrigées par IA, Tuteur IA, report des compétences vers EFE, heures de cours et activités
surveillées, avancement. Ce skill encode **sa** façon de faire, relevée sur ses parcours aboutis
(Cyber/Pentest et IA) tels qu'ils sont réellement configurés dans Moodle — pas une méthode générique.

Le résultat attendu est un parcours **déployable** : des fichiers prêts à être créés dans Moodle
(par le serveur MCP ou à la main), où chaque réglage est décidé, et rien n'est laissé à « penser
à configurer ».

## Les règles qui ne se discutent pas, et pourquoi

1. **Le corrigé ne va jamais dans un texte que l'élève ou le tuteur peut lire.** La description et
   les « Instructions de l'activité » d'un devoir sont transmises au Tuteur IA ; un élément de
   corrigé y fuiterait. Le corrigé va dans « Corrigé attendu / barème » (Correction IA) ou dans
   `<expectedanswer>` (questions IA) ou dans le feedback général des questions (vu après correction).
2. **Tout ce qui est évalué remonte à EFE, et seulement cela** : les **TP évalués en classe**
   (devoirs surveillés), le **TP bonus** et les tests sommatifs ont leur report EFE **activé par
   défaut**. Jamais le TD, les QCM d'entraînement, la vidéo ni les **TP d'entraînement** : faits à
   la maison ou pour apprendre, souvent avec une IA, ils noieraient les pastilles qui comptent (et un
   élève qui prépare à la maison le sujet suivant avec une IA n'est pas évalué).
   Codes **N2 au minimum, N3 de préférence, jamais N1 seul** (exigence de l'inspection). Pour une
   activité qui n'est pas passée en séance fermée, « Reporter après la date de rendu » est
   **activé** : sinon un élève rapide fait chuter l'avancement relatif des autres, et l'activité a
   donc une date de rendu. Un TP évalué en séance fermée est reporté sans date de rendu tant que
   l'enseignant n'en a pas posé.
3. **Le test sommatif se passe en classe, surveillé** : condition « Activité surveillée », code de
   séance, mode examen (qui ferme tout le reste du cours : pas besoin de verrous en plus). Pas de
   date de fermeture imposée : la correction est publiée par l'enseignant avec le bouton
   « Correction » du bloc Avancement, quand tous les absents ont rattrapé — sinon les questions
   fuitent vers ceux qui doivent encore le passer.
4. **Un module suit toujours le même déroulé** : leçon → **TD** → 1 ou 2 **TP d'entraînement** →
   **QCM d'entraînement** → 1 ou 2 **TP évalués en classe** → au moins un **TP bonus** → **test
   sommatif**. Le **TD** (travail dirigé, 20 à 30 min, non évalué) déroule un cas pratique concret
   en intercalant des textes de guidage et des questions à indices : il fait mettre en œuvre la leçon
   pas à pas. Le **TP d'entraînement** se fait à la maison, avec le Tuteur IA, sans EFE ni
   surveillance : l'IA est tolérée, c'est de l'apprentissage. Le **TP évalué en classe** est un
   devoir **fermé avant la séance**, surveillé avec **code de séance mais sans mode examen** (la
   leçon et le TD restent consultables), reporté à EFE, que l'enseignant ouvre lui-même pour la
   séance puis laisse fermé, noté directement par la Correction IA, **sans Tuteur IA**. Son sujet est
   **inédit** (jamais dans la leçon, le TD, un exemple ou un TP d'entraînement), sur les seules
   notions du module, et il ne circule pas avant la séance. Le **TP bonus**, pour les élèves en
   avance, est réglé comme un TP évalué (noté, reporté à EFE) et **ne s'ouvre qu'à ceux qui ont rendu
   les TP évalués** du module (condition d'achèvement `requires`). Détails dans
   `references/anatomie-module.md`.
5. **Chaque copie corrigée par IA coûte un appel au LLM local**, et les appels passent un par un sur
   tout le site. Les questions IA sont les bienvenues (y compris à l'entraînement : elles préparent
   au sommatif), mais dosées : 1 à 2 par test, une Correction IA par TP.
6. **Le HTML n'utilise que les classes du thème** (liste dans `references/style-html.md`). Pas de
   JavaScript, pas de `<style>` dans le contenu, pas de `<center>`. Ce qui n'est pas dans le thème
   s'affiche sans mise en forme sur Moodle.
7. **Un diagramme s'écrit en PlantUML, jamais en caractères** : UML ou SysML (classes, séquence,
   cas d'utilisation, états, exigences…), architecture réseau, maquette de fenêtre (module Salt). Source
   `.puml` gardée à côté de l'image PNG qu'on en tire, dans la leçon, le TD, une question ou une
   consigne ; dans un corrigé lu par l'IA, la source PlantUML elle-même. Jamais de boîtes en `+---+`
   ou `┌─┐` : approximatif, illisible au lecteur d'écran, pénible à corriger. Et on ne fait jamais
   **dessiner** un diagramme en texte à l'élève : dans un devoir, il rend une **image** (PlantUML
   exporté ou autre outil, `vision: true`) ; dans une question corrigée par IA (le correcteur des
   tests ne lit que le texte), il écrit la **source PlantUML**, apprise dans la leçon. Restent en
   texte : les sorties console et les arborescences de fichiers. Méthode et pièges :
   `references/style-html.md` § 3.
8. **Tout ce qui est créé dans Moodle l'est masqué**, dans un cours bac à sable. C'est l'enseignant
   qui relit et publie.
9. **Un corrigé de code se compile et s'exécute avant d'être livré** (`scripts/verifier-tpe.mjs`
   pour les TP, compilation des solutions CodeRunner pour le TD et les tests) : la Correction IA et
   CodeRunner notent d'après lui, un corrigé faux fausse toutes les notes.
10. **Quand tu n'es pas sûr d'une valeur technique** (une option d'outil, une version, un comportement
    Moodle), dis-le plutôt que de l'affirmer : ces contenus partent devant des élèves.

## Déroulé d'un parcours

Avance par étapes, avec une validation de l'enseignant entre chaque : sur un parcours entier, une
livraison d'un bloc est trop longue à relire et part souvent dans une direction qu'il n'avait pas
voulue.

### 0. Regarder l'existant (si le serveur MCP « moodle » est disponible)

- `moodle_site_info` : plugins réellement installés, réglages du site, classes CSS présentes dans le
  thème (`missingclasses` = à ne pas utiliser, ou à faire ajouter au SCSS).
- `moodle_list_courses` puis `moodle_get_course` sur le cours cible, et sur un parcours de référence
  si l'enseignant veut « faire comme dans le cours X ».
- `moodle_get_efe_competences` (niveaux `n2`, `n3`) : les codes EFE valides de son établissement.
- Pour imiter un réglage précis, `moodle_get_module` sur une activité équivalente vaut mieux que la
  mémoire : la configuration réelle fait foi.

Sans serveur MCP, appuie-toi sur les références de ce skill et dis-le.

### 1. Cadrage

Avant de produire quoi que ce soit, pose les questions qui manquent :
- thème, année (1re / 2e), effectif, demi-groupe ou classe entière ;
- volume : nombre de séances, durée, heures totales ; place dans l'année ;
- prérequis acquis ; matériel, VM, logiciels, accès Internet en salle ;
- **puissance des postes** : ce qui s'exécute en combien de temps (un entraînement, un scan), et
  les notions qu'il veut laisser en bonus, hors évaluation ;
- **format des TP** : TP de labo en phases (1 à 3 h) ou exercices courts de transfert et d'analyse
  (20 à 40 min) — voir `references/anatomie-module.md` ;
- **TP évalués en classe** : combien par module (1 ou 2), **quelle part d'une séance** leur est
  consacrée (exemple : environ 2 h sur 3 h, ce qui fixe leur taille), report EFE immédiat ou après
  une date de rendu, correction directe par la Correction IA ou validation manuelle (le TP bonus et
  le TD sont prévus dans chaque module : ne demande que s'il veut s'en écarter) ;
- **cas pratiques** : le contexte professionnel qui servira de fil rouge aux TD (une entreprise, un
  service, un produit crédible pour un technicien CIEL), s'il en a un en tête ;
- compétences visées (s'il en impose) et bloc d'examen visé (U4, U5, U6) ;
- évaluations attendues ; s'il veut une évaluation finale ; un projet de fin (Missions IA) ;
- le cours Moodle cible (id) et la date de début, pour placer dates prévues et échéances.

### 2. Carte du parcours (à faire valider avant tout contenu)

Écris `00-carte.md` d'après `templates/carte-parcours.md` : modules dans l'ordre, durée, objectifs,
compétence et code EFE de chaque activité notée, appariement des tests sommatifs, vidéos proposées
(avec la raison), évaluation finale, tableau de couverture prévisionnel. Lis
`references/anatomie-module.md` (structure d'un module, appariements, découpage) et
`references/referentiel-ciel.md` (compétences, critères, codes EFE) avant de l'écrire.

Signale ce que tu ferais autrement (une compétence trop présente, une autre absente, un module trop
lourd) **avant** de produire. Ne produis aucun module tant que la carte n'est pas validée.

### 3. Un module à la fois

Pour chaque module, dans un dossier `Mx-sujet/`, dans l'ordre du déroulé :

| Fichier | Contenu | Référence et modèle |
|---|---|---|
| `lecon.html` | la leçon, une ou plusieurs pages entre marqueurs ; encadrés 📸 « Capture suggérée » là où une capture d'écran aiderait, et nom de leçon terminé par « (TODO : Captures) » tant qu'il en reste | `references/style-html.md`, `templates/lecon.html`, `templates/exemples-ia/lecon-M2-moodle.html` |
| `td.questions.mjs` → `td.xml` | le **TD** : un cas pratique en 8 à 14 éléments, textes de guidage (`description`) intercalés avec des questions à indices, 20 à 30 min | `references/anatomie-module.md` § Le TD, `templates/td/td.questions.mjs`, `templates/exemples-cpp/td-M2.questions.mjs` |
| `tp1/`, `tp2/`… | chaque **TP d'entraînement** (maison, Tuteur IA, sans EFE) : consigne élève, énoncé, corrigé sur 100, compétences, consignes du tuteur | `references/deploiement.md` § 1, `templates/tp/`, `templates/exemples-ia/tp-M3-1-moodle.json` |
| `entrainement.xml` | **QCM d'entraînement** : banque de 15 à 25 questions dans le style du sommatif, dont 1 à 3 corrigées par IA | `templates/exemples-ia/entrainement-M2.xml` |
| `tp-evalues.mjs` → `tpe1/`, `tpe2/`, `tpb/` | les **TP évalués en classe** et le **TP bonus** : un fichier de données (sujet en niveaux, corrigé, barème, erreurs typiques, jeux d'essai) d'où `scripts/make-tpe.mjs` tire les dossiers de TP, le manifeste `tp-evalues.deploy.json` (TP évalués puis bonus) et la `fiche-tp-evalues.html` | `references/anatomie-module.md` § Les TP, `templates/tp-evalue/tp-evalues.mjs`, `templates/exemples-cpp/tp-evalues-M2.mjs` |
| `sommatif.xml` | le test sommatif (ou dans le dossier du test apparié) | `templates/exemples-ia/sommatif-M1.xml` et sa fiche |
| `video.json` | seulement si une vidéo est vraiment pertinente | `references/video.md`, `templates/video.json` |
| `fiche-enseignant.html` | barème, points de vigilance, corrigés, vérifications de labo | `templates/fiche-enseignant.html` |
| `module.deploy.json` | le manifeste : section, activités, rôles, dates, EFE | `references/deploiement.md` § 2, `templates/module.deploy.json` |

Les exemples de `templates/exemples-ia/` (parcours IA) et `templates/exemples-cpp/` (parcours C++ :
TD, TP évalués) sont de **vrais fichiers**, tels qu'ils sont dans Moodle : imite leur forme
(structure des énoncés, corrigé « RÉPONSE TYPE / PONDÉRATION / ERREURS TYPIQUES » des questions IA,
barème par blocs des TP, étiquettes et idnumbers des questions), pas leur sujet. Ils ne sont pas
versionnés dans git, car ils contiennent des questions de tests et des sujets évalués réels.

Écris les questions (TD, entraînement, sommatif, finale) dans un module JavaScript, puis génère le
Moodle XML avec `scripts/build-questions.mjs` : plus court à écrire qu'à la main, et sans erreur de
balisage. Le format est décrit en tête du script (indices `hints`, images embarquées `files` pour un
diagramme PlantUML) ; garde le module source à côté du XML (`entrainement.questions.mjs`) pour les
corrections suivantes.

Les réglages de chaque type d'activité (tentatives, délais, relecture, achèvement, surveillance…)
sont fixés par son **rôle** dans le manifeste et détaillés dans `references/reglages-moodle.md`.
N'en écris dans le manifeste que les écarts voulus.

Puis **contrôle** le module (section « Contrôles ») et corrige avant de le livrer. Présente-le en
quelques lignes (ce qui a été fait, les choix, les doutes), et attends le retour de l'enseignant
avant le module suivant.

### 4. Fin de parcours

- `couverture.md` : tableau compétence × activité notée, avec les codes EFE réellement utilisés et
  les compétences non couvertes (dis pourquoi c'est acceptable ou propose d'y remédier).
- Évaluation finale (`FINAL/`) si prévue, sur le modèle des sommatifs (`templates/exemples-ia/sommatif-M1.xml`).
- `00-introduction.html` : la page d'accueil du parcours (section 0), si elle n'existe pas.

### 5. Déploiement et vérification

Suis `references/deploiement.md` : simulation, création masquée dans le cours bac à sable,
relecture de ce qui a été créé (`moodle_get_module`) et comparaison avec le manifeste, puis liste
de ce qui reste à faire à la main (relire le brief du tuteur, vérifier le cron, CodeRunner…). Si
les outils d'écriture du serveur MCP ne sont pas disponibles, produis la liste de création
pas à pas tirée des manifestes.

Propose enfin la trace PDF des leçons (`references/deploiement.md` § 6) : activation des « Leçons
imprimables » dans le cours, ou export en lot par `moodle_export_lessons_pdf`.

## Organisation des fichiers d'un parcours

```
<Dossier du parcours>/
├── 00-carte.md                  carte validée (et ses évolutions)
├── 00-introduction.html         page d'accueil (section 0)
├── M0-cadre/                    un dossier par module
│   ├── lecon.html
│   ├── images/                  images de la leçon ; diagrammes : source .puml + PNG généré
│   ├── td.questions.mjs, td.xml TD (source, Moodle XML généré)
│   ├── tp1/                     TP d'entraînement : consigne.html, enonce.txt, corrige.txt, competences.txt, tuteur.txt
│   ├── entrainement.xml         QCM d'entraînement
│   ├── tp-evalues.mjs           TP évalués + bonus (source), puis générés : tpe1/, tpe2/, tpb/,
│   │                            tp-evalues.deploy.json, fiche-tp-evalues.html
│   ├── sommatif.xml
│   ├── fiche-enseignant.html
│   └── module.deploy.json
├── T4-enumeration-elevation/    test sommatif commun à deux modules (sommatif.xml + manifeste)
├── FINAL/                       évaluation finale
└── couverture.md
```

## Contrôles

Après chaque module, lance le script de contrôle (Node ≥ 18, aucune dépendance) :

```bash
node <dossier du skill>/scripts/check-module.mjs <dossier du module> [--efe efe-competences.json]
```

Les TP évalués et le TP bonus ont leur propre manifeste : contrôle-le avec
`--manifest tp-evalues.deploy.json`. Pour un TP de code, lance
aussi `scripts/verifier-tpe.mjs <dossier du module>` : il **compile et exécute** les corrigés avec les
jeux d'essai, vérifie que le programme « à réparer » compile et échoue, et que les squelettes à trous se
comportent comme prévu (modules Qt : contrôle de syntaxe seulement, avec `QT_INCLUDE`). Avec
`--questions td.questions.mjs` (ou un autre fichier de questions), il compile et exécute aussi les
solutions des questions CodeRunner sur leurs tests.

Il vérifie notamment : marqueurs et classes du HTML, absence de `<script>`, de `<center>` et de
styles en ligne superflus, champs obligatoires des TP et **fuite du corrigé dans la consigne**
(simple avertissement pour un TP évalué dont la consigne fournit une signature ou un programme de
départ), TP évalué et bonus (code de séance sans mode examen, Tuteur IA coupé, report EFE actif,
bonus réservé par `requires`, niveaux, durée non affichée), TP d'entraînement (pas de report EFE),
TD (textes de guidage intercalés, indices, 6 à 15 questions, durée estimée de 20 à 30 min, ni EFE
ni surveillance),
questions XML (idnumbers uniques, étiquettes, total des points, champs IA, part des points sur la
compétence rapportée à EFE), manifeste (rôles,
fichiers, codes EFE N2/N3, dates). Avec `--efe` (fichier tiré de `moodle_get_efe_competences`), il
vérifie aussi que chaque code EFE existe. Corrige les erreurs ; explique les avertissements que tu
choisis de garder.

Le script ne juge pas la qualité pédagogique : relis toi-même la cohérence leçon → TD → TP
d'entraînement → QCM → TP évalués → sommatif (tout ce qui est évalué a été enseigné, le TD fait
pratiquer ce que les TP évalués demanderont sans en donner les sujets, et le sommatif mesure bien la
compétence annoncée).

## Références (à lire au moment où tu en as besoin)

| Fichier | Quand le lire |
|---|---|
| `references/anatomie-module.md` | carte du parcours, structure d'un module, découpage d'une leçon, appariements, finale, projet |
| `references/referentiel-ciel.md` | choix des compétences, critères, codes EFE N2/N3, ponctualité, blocs d'examen |
| `references/style-html.md` | toute écriture de HTML (leçon, consigne de TP, page d'accueil), images et diagrammes PlantUML |
| `references/reglages-moodle.md` | réglages de chaque rôle d'activité (ce que le déploiement applique) |
| `references/video.md` | décider s'il faut une vidéo, la choisir, écrire ses questions |
| `references/outils-ia.md` | générateur de tests IA, Missions client IA, limites et coûts des outils IA |
| `references/deploiement.md` | manifeste, création dans Moodle, vérifications après coup |

Les gabarits sont dans `templates/` : pars d'eux plutôt que d'une page blanche, ils portent les
conventions (marqueurs, bloc de style d'aperçu, champs des TP, squelettes XML).
