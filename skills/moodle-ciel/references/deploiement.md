# Déploiement : manifeste, création dans Moodle, vérifications

## Sommaire

1. Fichiers d'un module
2. Le manifeste `module.deploy.json`
3. Créer dans Moodle
4. Vérifier après création
5. Ce qui reste à faire à la main
6. Trace PDF des leçons

## 1. Fichiers d'un module

```
Mx-sujet/
├── module.deploy.json     manifeste (ce qui est créé, dans quel ordre, avec quels réglages)
├── lecon.html             pages de la leçon entre marqueurs
├── images/                images de la leçon, envoyées avec elle (facultatif) ; diagramme : source .puml + PNG
├── td.questions.mjs       source du TD (→ scripts/build-questions.mjs)
├── td.xml                 TD (Moodle XML généré)
├── tp1/                   un dossier par TP d'entraînement
│   ├── consigne.html      → Instructions de l'activité (HTML, vue par l'élève)
│   ├── enonce.txt         → Correction IA : énoncé de l'exercice
│   ├── corrige.txt        → Correction IA : corrigé attendu / barème (score sur 100)
│   ├── competences.txt    → Correction IA : compétences évaluées (une par ligne)
│   └── tuteur.txt         → Tuteur IA : consignes supplémentaires (facultatif)
├── entrainement.questions.mjs   source des questions (→ scripts/build-questions.mjs)
├── entrainement.xml       banque d'entraînement (Moodle XML généré)
├── tp-evalues.mjs         TP évalués et bonus (source → scripts/make-tpe.mjs), qui génère :
├── tpe1/, tpe2/, tpb/     un dossier par TP évalué et pour le bonus (mêmes fichiers que tp1/, sans tuteur.txt)
├── tp-evalues.deploy.json manifeste des TP évalués puis du bonus
├── fiche-tp-evalues.html  sujets, corrigés, barèmes (jamais déployée)
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
| `key` | tous | identifiant court, unique dans le module (`lecon`, `tp1`, `sommatif`), jamais un nombre ; sert aux liens `@@CM:clé@@` (`style-html.md` § 3) |
| `role` | tous | `lecon`, `video`, `td`, `entrainement`, `tp`, `quiz-ia`, `sommatif`, `finale`, `page`, `label`, `resource`, `url` |
| `name` | tous | nom affiché dans Moodle (conventions : `anatomie-module.md`) |
| `file` | `lecon`, `page`, `label`, `video`, `resource` | fichier source relatif au dossier du module |
| `dir` | `tp` | dossier du TP (`tp1`, `tpe1`, `tpb`) |
| `questions` | `td`, `entrainement`, `quiz-ia`, `sommatif`, `finale` | `{ "file": "…xml", "category": "nom de catégorie", "draw": 10, "draw_ai": 1 }` ; `draw` (tirage aléatoire) seulement pour `entrainement` ; `draw_ai` = nombre de questions IA tirées d'une sous-catégorie à part ; les autres rôles prennent toutes les questions du fichier, dans l'ordre |
| `points` | `sommatif`, `finale`, `quiz-ia` | total attendu des points (20, 40, 10) : contrôlé |
| `timelimit_minutes` | `entrainement`, `quiz-ia` | durée |
| `submission` | `tp` | `{ "type": "file", "filetypes": ".pdf", "maxfiles": 1 }` ou `{ "type": "onlinetext" }` |
| `vision` | `tp` | `true` si la remise contient des images que la Correction IA doit lire (diagramme exporté, capture, image collée dans le texte en ligne) : la vision est alors imposée au devoir (case « remplacer » cochée), quel que soit le réglage du site ; absent : réglage du site |
| `tutor` | `tp` | `{ "websearch": "none" }` (ou `"web"`, `"material"` avec `"sites": […]`) |
| `efe` | `tp` évalué ou bonus, `quiz-ia`, `sommatif`, `finale` | `{ "competences": ["C04.3"], "ponctualite": ["C03.4.R4.T5"], "after_due": true }` — codes N2/N3 uniquement ; pas de ponctualité sur les tests ni sur un TP évalué ; jamais sur un TD ni un TP d'entraînement |
| `duedate` | `tp` (date du devoir), tests (date de rendu dans l'Avancement) | `"2026-11-03T17:40"` (heure de Paris) ; obligatoire avec `after_due` |
| `supervised` | `sommatif`, `finale`, `tp` évalué ou bonus | sommatif : `{ "planned": "2026-11-03T14:00", "sessioncode": true, "exammode": true }` ; TP évalué ou bonus : `{ "sessioncode": true, "exammode": false }` |
| `requires` | tous (TP bonus surtout) | activités à achever avant d'accéder à celle-ci (condition d'accès « achèvement ») : clés d'activités placées plus haut dans le manifeste, ou cmid d'activités existantes : `["tpe1", "tpe2"]`, `[2313, 2314]` |
| `before` | tous | créer l'activité **avant** une autre de la section (clé placée plus haut, ou cmid) au lieu de l'ajouter en fin de section : un TD ajouté après coup se place avant l'activité qui suit la leçon, un bonus recréé avant le sommatif |
| `minduration_minutes` | tests | durée minimale attendue d'une tentative (contrôle des tentatives), si l'enseignant la demande |
| `overrides` | tous | écarts voulus aux réglages du rôle, par nom de champ Moodle : `{ "delay2": 86400 }` ; TP évalué ou bonus : `{ "aichatenabled": 0, "assignfeedback_comments_enabled": 0, "assignfeedback_file_enabled": 0 }` |

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
   « <catégorie> — IA »), puis l'activité avec les réglages de son rôle, ses pages et leurs images
   (leçon) ou ses questions (fixes par idnumber, ou tirées au hasard), enfin la surveillance, les activités à
   achever d'abord (`requires`) et l'Avancement. Une
   activité du même nom déjà présente dans la section fait refuser le déploiement : supprime d'abord
   les brouillons (`moodle_delete_draft`). Les vidéos (`mod_videoqcm`) se créent encore à la main.
4. Le rapport d'écart que renvoie l'outil (manifeste comparé à ce que Moodle a enregistré) doit être
   vide ; sinon, explique chaque écart.

Ce qu'il faut savoir du pont :
- Il **ne modifie pas** une activité existante, sauf ses réglages hors formulaire
  (`moodle_set_module_extras` : surveillance, `requires`, durée minimale, Avancement). Changer un autre
  réglage (EFE, consigne, questions) = `moodle_delete_draft` puis redéploiement, tant que l'activité
  est un brouillon masqué du pont. Pour recréer :
  1. relève d'abord l'activité dans Moodle (`moodle_get_module`). L'enseignant a pu la renommer ou
     changer un réglage à la main : reporte-le dans le manifeste partiel (`<clé>.deploy.json`, une
     seule activité, `before` = l'activité qui la suit) ;
  2. supprime l'activité ;
  3. pour un test, supprime aussi sa catégorie de questions : `moodle_delete_draft` avec
     `questioncategory` et l'id lu par `moodle_get_questions`, sous-catégorie « — IA » comprise. Il faut
     le pont 0.3.4 au moins ; refusé si une question vient d'un autre compte ou sert encore. Sans ce
     pont, la catégorie pleine refuse le réimport ;
  4. redéploie (simulation d'abord), puis compare au relevé.
- Il ne déplace pas une activité après coup : donne la bonne place à la création (`before`), sinon
  l'enseignant la glisse dans Moodle.
- Il envoie les **images** d'une leçon citées en relatif (`src="images/<nom>.png"`, diagrammes
  PlantUML compris) avec ses pages : chacune est rangée dans la zone de fichiers de la page qui la
  cite, et son `src` devient `@@PLUGINFILE@@/<nom>.png`. Les fichiers sont lus **avant** la création
  de la leçon, simulation comprise : une image manquante, mal nommée ou trop lourde (2 Mo par image,
  4 Mo par leçon ; règles dans `style-html.md` § 3) arrête le déploiement avant qu'une leçon vide soit
  créée. Le rapport d'écart signale une image citée mais absente de sa page dans Moodle. Pont 0.3.3
  au moins : un pont plus ancien refuse le paramètre des images.
- Hors leçon (page, étiquette, consigne de TP), une image en relatif ne part pas : le rapport le
  signale dans ses avertissements, et l'enseignant la dépose dans l'éditeur (liste au § 5). Dans les
  questions (TD, tests), une image passe par `files` de `build-questions.mjs` : elle est embarquée
  dans le Moodle XML et part avec la question.
- Il remplace les liens **`@@CM:clé@@`** et **`@@CM:<cmid>@@`** par l'URL de l'activité (règles dans
  `style-html.md` § 3). Tout est contrôlé avant la première création : une clé absente du manifeste,
  un cmid absent du cours, ou un lien d'une page, d'une étiquette ou d'une consigne de TP vers une
  activité placée plus bas arrêtent le déploiement. Une leçon qui vise une activité créée après elle
  reçoit ses pages en fin de déploiement ; le résumé de la section, renvoyé à la fin, aussi. En
  simulation, rien n'est remplacé : le rapport liste les liens de chaque activité (`links`). Un lien
  vers une vidéo ou un fichier (non créés par le pont) reste tel quel et figure dans les
  avertissements. Les outils à l'unité (`moodle_create_module`, `moodle_set_lesson_pages`) ne
  remplacent pas ces liens.
- `avancement: null` dans le retour d'une création est transitoire : l'entrée du bloc Avancement
  apparaît quelques minutes plus tard (vérifie avec `moodle_get_course`).
- Ajouter un TD ou des TP à un module déjà déployé : un manifeste à part dans le dossier du module
  (`td.deploy.json`, `tp-evalues.deploy.json`) avec `before` pour la place ; contrôle-le avec
  `check-module.mjs --manifest`.

Sans les outils d'écriture (lecture seule, ou plugin pas encore à jour), produis pour l'enseignant
la liste de création pas à pas, activité par activité, avec la valeur de chaque champ, tirée du
manifeste et de `reglages-moodle.md`.

Sur un test ou une leçon, la date de rendu de l'Avancement n'est jamais recopiée dans l'échéance
native (`timeclose`, `deadline`) : le pont coupe cette synchronisation, sinon l'Avancement fermerait
le test sommatif à sa date de rendu.

## 4. Vérifier après création

- `moodle_get_course` : section, ordre des activités (leçon, TD, TP d'entraînement, QCM, TP
  évalués, bonus, sommatif), toutes masquées, extensions attendues (« Correction IA », « Tuteur IA
  (brief ready) » sur un TP d'entraînement, « EFE … », « Surveillée (code) » et « Heures de cours
  (auto EFE) » sur un TP évalué ou bonus, « Surveillée (code, examen) » sur un sommatif) ; le bonus a
  une condition d'achèvement par TP évalué.
- `moodle_get_module` sur chaque activité notée : réglages du rôle, codes EFE, dates, questions du
  test dans l'ordre, nombre de points.
- Le brief du Tuteur IA est généré en tâche de fond : `briefstatus` passe à `ready` après le cron.

## 5. Ce qui reste à faire à la main

À lister à l'enseignant après chaque déploiement :
- relire le **brief du Tuteur IA** de chaque TP d'entraînement (il ne doit rien divulguer) ;
- vérifier les solutions des questions **CodeRunner** (bouton « Check ») ;
- faire le **TD** une fois avec un compte élève : rendu des textes et des questions sur la page,
  bouton « Vérifier », indices ;
- tester l'**ouverture d'une séance surveillée** sur un TP évalué avec un compte élève (code de
  séance), puis vérifier que le bonus ne s'ouvre qu'après la remise des TP évalués ;
- faire les **captures suggérées** (encadrés 📸 `capture` des leçons nommées « … (TODO : Captures) »),
  les mettre à la place des encadrés, puis retirer la mention du nom ; ajouter la classe `capture` au
  SCSS du thème si ce n'est pas fait (`style-html.md` § 2) ;
- fournir les images marquées `IMAGE À FOURNIR` dans la leçon, et déposer dans l'éditeur les images
  en relatif d'une page, d'une étiquette ou d'une consigne de TP (avertissement « non envoyée(s) »
  du rapport) ; celles de la leçon sont déjà en place ;
- remplacer les liens `@@CM:…@@` restés tels quels (vers une vidéo ou un fichier créés à la main :
  avertissement du rapport) par l'URL de l'activité ;
- vérifier que le **cron** tourne (corrections IA, briefs, report EFE) ;
- rendre une copie de test sur un TP pour voir arriver la Correction IA ;
- publier (rendre visible) quand tout est relu ; après chaque sommatif, publier la correction avec le
  bloc Avancement quand tout le monde l'a passé.

## 6. Trace PDF des leçons

Les élèves gardent une trace des leçons, lisible même quand Moodle ne l'est pas :

- **Dans Moodle** (plugin `local_lessonexport`) : l'enseignant active « Leçons imprimables » pour le
  cours ; chaque leçon a alors un bouton « Version imprimable », et la page « Leçons imprimables »
  imprime tout le parcours (une leçon par page). L'élève choisit « Enregistrer au format PDF ».
- **En lot, par l'agent** : `moodle_export_lessons_pdf` (cours, dossier) produit un PDF par leçon et
  le parcours complet, avec la mise en page de Moodle. Utile en fin de formation, ou à distribuer avant
  une coupure prévue.

Le rendu suit le HTML de la leçon : un encadré trop long pour une page A4 est reporté entier à la page
suivante (il n'est jamais coupé) ; préfère donc des encadrés courts (voir `style-html.md`).
