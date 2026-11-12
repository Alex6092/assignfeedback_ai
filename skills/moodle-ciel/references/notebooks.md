# TP Python en notebooks locaux

À utiliser pour un TP individuel exécuté dans le navigateur, sur les serveurs du lycée.
Le devoir Moodle conserve la copie, la note et les réglages EFE habituels.

## Dossier pédagogique

Partir de `templates/notebook/bundle.json`. Ce JSON contient :

- `master` : notebook nbformat 4 avec corrigé et métadonnées nbgrader ;
- `rubric` : critères de l’IA (méthode, explications, lisibilité), indépendants des tests ;
- `tests_weight` : pourcentage des tests, 70 par défaut ;
- `profile` : `python` ;
- `files` : objet nom relatif → contenu base64 pour les données annexes.

Chaque réponse et chaque test porte un `metadata.nbgrader.grade_id` unique et stable.
Les réponses de code utilisent `solution: true` et des marqueurs `### BEGIN SOLUTION` /
`### END SOLUTION`. Les tests privés utilisent `### BEGIN HIDDEN TESTS` /
`### END HIDDEN TESTS`. Les points sont définis dans les cellules de tests.
Une cellule de réponse Markdown est entièrement remplacée dans la version élève :
placer sa consigne dans une cellule séparée.

Les exercices doivent fonctionner dans un noyau neuf, dans l’ordre du notebook, sans
Internet. Une réponse complète au barème IA vaut 100 sur cette seule composante.
Ne pas demander à l’IA de calculer elle-même la note 70/30.

Le correcteur conserve l’ordre du maître : les réponses doivent être écrites dans les
cellules prévues. Tout code nécessaire doit donc se trouver dans une cellule de réponse
identifiée ; une cellule libre ajoutée par l’élève n’est pas une nouvelle réponse notée.

## Contrôler puis envoyer

1. `node scripts/check-notebook.mjs <bundle.json>` vérifie la structure.
2. Créer le devoir masqué avec les conventions de son rôle (entraînement, évalué, bonus).
3. `moodle_set_notebook_assignment(cmid, bundle_file, dry_run: true)` vérifie les droits.
4. Importer réellement, puis `moodle_validate_notebook_assignment(versionid)`.
5. Lire `moodle_get_notebook_validation(versionid)` jusqu’à `valid`, `invalid` ou `failed`.
6. Si invalide : corriger le dossier, envoyer une nouvelle version, valider à nouveau.
   Après deux corrections infructueuses, présenter les erreurs à l’enseignant.
7. Relire `moodle_get_module`. L’enseignant sélectionne la version validée et publie
   l’activité depuis Moodle. Le MCP ne publie ni le devoir ni une version automatiquement.

Une simulation ne lance aucun code. Une validation exécute le corrigé et une réponse
vide dans le service isolé ; un succès de validation ne remplace pas la relecture du sujet.
Les solutions, tests privés et données de référence restent exclusivement dans le dossier
enseignant : ne pas les copier dans `consigne.html`, les instructions ni le Tuteur IA.

## Manifeste

Le dossier `templates/notebook` fournit aussi `module.deploy.json` et les quatre fichiers
du TP minimal. Renseigner l’identifiant du cours bac à sable (initialement 0, donc refusé),
la section et le nom. Cet exemple de contrôle n’active ni EFE ni Tuteur IA ; appliquer
les conventions pédagogiques habituelles au vrai TP avant de le déployer.

Sur une activité `role: "tp"`, ajouter :

```json
"notebook": { "bundle_file": "tp1/notebook.json" }
```

Conserver les fichiers habituels du TP (`consigne.html`, `enonce.txt`, `corrige.txt`,
`competences.txt`). Le déploiement active la remise notebook individuelle, les brouillons,
importe le dossier et lance sa validation. EFE et surveillance suivent le rôle du TP.
