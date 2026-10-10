# Déploiement : manifeste, création dans Moodle, vérifications

## Sommaire

1. Fichiers d'un module
2. Le manifeste `module.deploy.json`
3. Créer dans Moodle
4. Vérifier après création
5. Ce qui reste à faire à la main

## 1. Fichiers d'un module

```
Mx-sujet/
├── module.deploy.json     manifeste (ce qui est créé, dans quel ordre, avec quels réglages)
├── lecon.html             pages de la leçon entre marqueurs
├── images/                images référencées par la leçon (facultatif)
├── entrainement.questions.mjs   source des questions (→ scripts/build-questions.mjs)
├── entrainement.xml       banque d'entraînement (Moodle XML généré)
├── tp1/                   un dossier par TP
│   ├── consigne.html      → Instructions de l'activité (HTML, vue par l'élève)
│   ├── enonce.txt         → Correction IA : énoncé de l'exercice
│   ├── corrige.txt        → Correction IA : corrigé attendu / barème (score sur 100)
│   ├── competences.txt    → Correction IA : compétences évaluées (une par ligne)
│   └── tuteur.txt         → Tuteur IA : consignes supplémentaires (facultatif)
├── sommatif.questions.mjs source du test sommatif
├── sommatif.xml           test sommatif (Moodle XML généré)
├── video.json             vidéo + QCM (facultatif, voir video.md)
└── fiche-enseignant.html  barème, vigilance, corrigés (jamais déployée)
```

Modèles réels : `templates/exemples-ia/` (fichiers tirés du parcours IA, hors dépôt git) —
`tp-M3-1-moodle.json` et `tp-M4-1-moodle.json` montrent un TP tel qu'il est configuré dans Moodle
(champs `activity`, `extensions.aifeedback.config`, `extensions.aichat`, `extensions.efe`),
`sommatif-M1.xml` et sa fiche un test sommatif, `entrainement-M2.xml` une banque d'entraînement,
`lecon-M2-moodle.html` une leçon d'une page.

## 2. Le manifeste `module.deploy.json`

Gabarit : `templates/module.deploy.json`.

```json
{
  "format": "moodle-ciel/module@1",
  "course": { "id": 123, "teacher_id": 2 },
  "section": { "number": 3, "name": "M2 - Titre du module" },
  "prefix": "IA",
  "activities": [ … dans l'ordre d'affichage … ]
}
```

`course.teacher_id` : identifiant Moodle de l'enseignant, désigné dans EFE pour chaque activité qui
y remonte (sinon, le compte de service du pont serait désigné). `course.id` : le cours bac à sable
cible (il peut aussi être donné à l'outil de déploiement).

Champs d'une activité :

| Champ | Rôles | Contenu |
|---|---|---|
| `key` | tous | identifiant court, unique dans le module (`lecon`, `tp1`, `sommatif`) ; sert aux liens `@@CM:clé@@` |
| `role` | tous | `lecon`, `video`, `entrainement`, `tp`, `quiz-ia`, `sommatif`, `finale`, `page`, `label`, `resource`, `url` |
| `name` | tous | nom affiché dans Moodle (conventions : `anatomie-module.md`) |
| `file` | `lecon`, `page`, `label`, `video`, `resource` | fichier source relatif au dossier du module |
| `dir` | `tp` | dossier du TP (`tp1`) |
| `questions` | `entrainement`, `quiz-ia`, `sommatif`, `finale` | `{ "file": "…xml", "category": "nom de catégorie", "draw": 10, "draw_ai": 1 }` ; `draw` (tirage aléatoire) seulement pour `entrainement` ; `draw_ai` = nombre de questions IA tirées d'une sous-catégorie à part |
| `points` | `sommatif`, `finale`, `quiz-ia` | total attendu des points (20, 40, 10) : contrôlé |
| `timelimit_minutes` | `entrainement`, `quiz-ia` | durée |
| `submission` | `tp` | `{ "type": "file", "filetypes": ".pdf", "maxfiles": 1 }` ou `{ "type": "onlinetext" }` |
| `vision` | `tp` | `true` si la remise contient des captures que la Correction IA doit lire |
| `tutor` | `tp` | `{ "websearch": "none" }` (ou `"web"`, `"material"` avec `"sites": […]`) |
| `efe` | `tp`, `quiz-ia`, `sommatif`, `finale` | `{ "competences": ["C04.3"], "ponctualite": ["C03.4.R4.T5"], "after_due": true }` — codes N2/N3 uniquement ; pas de ponctualité sur les tests |
| `duedate` | `tp` (date du devoir), tests (date de rendu dans l'Avancement) | `"2026-11-03T17:40"` (heure de Paris) ; obligatoire avec `after_due` |
| `supervised` | `sommatif`, `finale` | `{ "planned": "2026-11-03T14:00", "sessioncode": true, "exammode": true }` |
| `minduration_minutes` | tests | durée minimale attendue d'une tentative (contrôle des tentatives), si l'enseignant la demande |
| `overrides` | tous | écarts voulus aux réglages du rôle, par nom de champ Moodle : `{ "delay2": 86400 }` |

Les réglages par défaut de chaque rôle sont ceux de `reglages-moodle.md` : n'écris dans le
manifeste que ce qui en diffère.

## 3. Créer dans Moodle

Par le serveur MCP « moodle », quand ses outils d'écriture sont disponibles (activés avec
`MOODLE_MCP_READONLY=0`, et seulement dans les catégories bac à sable du pont) :

1. Contrôle le module (`scripts/check-module.mjs`) : pas de déploiement avec une erreur.
2. **Simulation** : `moodle_deploy_module` avec `dry_run: true` ; relis la liste des opérations.
3. **Création** : même appel sans `dry_run` (avec `courseid` si le manifeste n'a pas de `course.id`).
   Tout est créé **masqué**, dans l'ordre du manifeste. Pour chaque activité : questions importées
   d'abord (banque du cours, une catégorie par test ; les questions IA de l'entraînement dans
   « <catégorie> — IA »), puis l'activité avec les réglages de son rôle, ses pages (leçon) ou ses
   questions (fixes par idnumber, ou tirées au hasard), enfin la surveillance et l'Avancement. Une
   activité du même nom déjà présente dans la section fait refuser le déploiement : supprime d'abord
   les brouillons (`moodle_delete_draft`). Les vidéos (`mod_videoqcm`) se créent encore à la main.
4. Le rapport d'écart que renvoie l'outil (manifeste comparé à ce que Moodle a enregistré) doit être
   vide ; sinon, explique chaque écart.

Sans les outils d'écriture (lecture seule, ou plugin pas encore à jour), produis pour l'enseignant
la liste de création pas à pas, activité par activité, avec la valeur de chaque champ, tirée du
manifeste et de `reglages-moodle.md`.

Sur un test ou une leçon, la date de rendu de l'Avancement n'est jamais recopiée dans l'échéance
native (`timeclose`, `deadline`) : le pont coupe cette synchronisation, sinon l'Avancement fermerait
le test sommatif à sa date de rendu.

## 4. Vérifier après création

- `moodle_get_course` : section, ordre des activités, toutes masquées, extensions attendues
  (« Correction IA », « Tuteur IA (brief ready) », « EFE … », « Surveillée (code, examen) »).
- `moodle_get_module` sur chaque activité notée : réglages du rôle, codes EFE, dates, questions du
  test dans l'ordre, nombre de points.
- Le brief du Tuteur IA est généré en tâche de fond : `briefstatus` passe à `ready` après le cron.

## 5. Ce qui reste à faire à la main

À lister à l'enseignant après chaque déploiement :
- relire le **brief du Tuteur IA** de chaque TP (il ne doit rien divulguer) ;
- vérifier les solutions des questions **CodeRunner** (bouton « Check ») ;
- fournir les images marquées `IMAGE À FOURNIR` dans la leçon ;
- vérifier que le **cron** tourne (corrections IA, briefs, report EFE) ;
- rendre une copie de test sur un TP pour voir arriver la Correction IA ;
- publier (rendre visible) quand tout est relu ; après chaque sommatif, publier la correction avec le
  bloc Avancement quand tout le monde l'a passé.
