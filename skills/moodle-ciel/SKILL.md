---
name: moodle-ciel
description: Concevoir, rédiger, vérifier et déployer des parcours Moodle pour le BTS CIEL (option A Informatique et réseaux) au format de l'enseignant — leçons HTML aux classes du thème, QCM d'entraînement et tests sommatifs surveillés en Moodle XML (questions corrigées par IA comprises), TP en devoir avec Correction IA, Tuteur IA et report EFE, vidéo + QCM quand c'est pertinent, couverture des compétences, puis création dans Moodle via le serveur MCP « moodle ». Utilise ce skill dès qu'il s'agit de créer, compléter, réviser, contrôler ou déployer un parcours, un module, une leçon, un TP, un QCM, un test sommatif ou une évaluation finale pour une classe de BTS CIEL ou pour Moodle, même si l'utilisateur ne prononce ni « skill » ni « parcours » — par exemple « prépare-moi le module sur le routage », « fais le test sommatif de M4 », « regarde comment est réglé mon cours IA », « ajoute un TP sur Wireshark ». Ne pas l'utiliser pour développer le code PHP des plugins Moodle eux-mêmes.
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
2. **EFE ne reçoit que des évaluations significatives** : TP (devoirs) et tests sommatifs faits en
   classe. Jamais les QCM d'entraînement ni la vidéo : faits à la maison, souvent avec une IA, ils
   noieraient les pastilles qui comptent. Codes **N2 au minimum, N3 de préférence, jamais N1 seul**
   (exigence de l'inspection). « Reporter après la date de rendu » est **activé** : sinon un élève
   rapide fait chuter l'avancement relatif des autres. Toute activité qui remonte à EFE a donc une
   date de rendu.
3. **Le test sommatif se passe en classe, surveillé** : condition « Activité surveillée », code de
   séance, mode examen (qui ferme tout le reste du cours : pas besoin de verrous en plus). Pas de
   date de fermeture imposée : la correction est publiée par l'enseignant avec le bouton
   « Correction » du bloc Avancement, quand tous les absents ont rattrapé — sinon les questions
   fuitent vers ceux qui doivent encore le passer.
4. **Chaque copie corrigée par IA coûte un appel au LLM local**, et les appels passent un par un sur
   tout le site. Les questions IA sont les bienvenues (y compris à l'entraînement : elles préparent
   au sommatif), mais dosées : 1 à 2 par test, une Correction IA par TP.
5. **Le HTML n'utilise que les classes du thème** (liste dans `references/style-html.md`). Pas de
   JavaScript, pas de `<style>` dans le contenu, pas de `<center>`. Ce qui n'est pas dans le thème
   s'affiche sans mise en forme sur Moodle.
6. **Tout ce qui est créé dans Moodle l'est masqué**, dans un cours bac à sable. C'est l'enseignant
   qui relit et publie.
7. **Quand tu n'es pas sûr d'une valeur technique** (une option d'outil, une version, un comportement
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

Pour chaque module, dans un dossier `Mx-sujet/`, dans cet ordre :

| Fichier | Contenu | Référence et modèle |
|---|---|---|
| `lecon.html` | la leçon, une ou plusieurs pages entre marqueurs | `references/style-html.md`, `templates/lecon.html`, `templates/exemples-ia/lecon-M2-moodle.html` |
| `entrainement.xml` | banque de 15 à 25 questions, dont 1 à 3 corrigées par IA | `templates/exemples-ia/entrainement-M2.xml` |
| `tp1/`, `tp2/`… | chaque TP : consigne élève, énoncé, corrigé sur 100, compétences, consignes du tuteur | `references/deploiement.md` § 1, `templates/tp/`, `templates/exemples-ia/tp-M3-1-moodle.json` |
| `sommatif.xml` | le test sommatif (ou dans le dossier du test apparié) | `templates/exemples-ia/sommatif-M1.xml` et sa fiche |
| `video.json` | seulement si une vidéo est vraiment pertinente | `references/video.md`, `templates/video.json` |
| `fiche-enseignant.html` | barème, points de vigilance, corrigés, vérifications de labo | `templates/fiche-enseignant.html` |
| `module.deploy.json` | le manifeste : section, activités, rôles, dates, EFE | `references/deploiement.md` § 2, `templates/module.deploy.json` |

Les exemples de `templates/exemples-ia/` sont de **vrais fichiers** du parcours IA, tels qu'ils sont
dans Moodle : imite leur forme (structure des énoncés, corrigé « RÉPONSE TYPE / PONDÉRATION /
ERREURS TYPIQUES » des questions IA, barème par blocs des TP, étiquettes et idnumbers des questions),
pas leur sujet. Ils ne sont pas versionnés dans git, car ils contiennent des questions de tests réels.

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

## Organisation des fichiers d'un parcours

```
<Dossier du parcours>/
├── 00-carte.md                  carte validée (et ses évolutions)
├── 00-introduction.html         page d'accueil (section 0)
├── M0-cadre/                    un dossier par module
│   ├── lecon.html
│   ├── entrainement.xml
│   ├── tp1/                     consigne.html, enonce.txt, corrige.txt, competences.txt, tuteur.txt
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

Il vérifie notamment : marqueurs et classes du HTML, absence de `<script>`, de `<center>` et de
styles en ligne superflus, champs obligatoires des TP et **fuite du corrigé dans la consigne**,
questions XML (idnumbers uniques, étiquettes, total des points, champs IA), manifeste (rôles,
fichiers, codes EFE N2/N3, dates). Avec `--efe` (fichier tiré de `moodle_get_efe_competences`), il
vérifie aussi que chaque code EFE existe. Corrige les erreurs ; explique les avertissements que tu
choisis de garder.

Le script ne juge pas la qualité pédagogique : relis toi-même la cohérence leçon → entraînement →
TP → sommatif (tout ce qui est évalué a été enseigné, et le sommatif mesure bien la compétence
annoncée).

## Références (à lire au moment où tu en as besoin)

| Fichier | Quand le lire |
|---|---|
| `references/anatomie-module.md` | carte du parcours, structure d'un module, découpage d'une leçon, appariements, finale, projet |
| `references/referentiel-ciel.md` | choix des compétences, critères, codes EFE N2/N3, ponctualité, blocs d'examen |
| `references/style-html.md` | toute écriture de HTML (leçon, consigne de TP, page d'accueil) |
| `references/reglages-moodle.md` | réglages de chaque rôle d'activité (ce que le déploiement applique) |
| `references/video.md` | décider s'il faut une vidéo, la choisir, écrire ses questions |
| `references/outils-ia.md` | générateur de tests IA, Missions client IA, limites et coûts des outils IA |
| `references/deploiement.md` | manifeste, création dans Moodle, vérifications après coup |

Les gabarits sont dans `templates/` : pars d'eux plutôt que d'une page blanche, ils portent les
conventions (marqueurs, bloc de style d'aperçu, champs des TP, squelettes XML).
