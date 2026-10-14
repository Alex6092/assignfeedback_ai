# local_aibridge — Pont IA (Claude Code, Codex)

Ce plugin expose des services web pour qu'un agent IA (Claude Code, Codex) **lise** un cours Moodle
dans le détail, réglages de nos plugins compris, et y **crée des brouillons** dans un bac à sable.
L'agent y accède par le serveur MCP local [`tools/moodle-mcp`](../../tools/moodle-mcp/README.md).

L'écriture est désactivée par défaut. Une fois activée, elle reste limitée aux catégories bac à
sable, tout y est créé **masqué**, et le pont ne supprime que ses propres brouillons encore masqués.

Le pont ne renvoie **aucune donnée nominative d'élève** et **aucun secret** : les clés d'API et les
mots de passe sont masqués. Chaque appel est inscrit au journal.

---

## Configuration pas à pas (administrateur)

Les menus cités sont ceux de Moodle 5.2 en français.

### 1. Installer le plugin

1. *Administration du site → Plugins → Installer des plugins* : déposer `local_aibridge.zip`.
   À défaut, copier le dossier dans `public/local/aibridge` sur le serveur.
2. *Administration du site → Notifications* : valider l'installation.

### 2. Activer les services web

*Administration du site → Serveur → Services web → Vue d'ensemble* :

1. **Activer les services web** : *Oui*.
2. **Activer les protocoles** : activer **REST**.

### 3. Créer le compte de service

*Administration du site → Utilisateurs → Comptes → Ajouter un utilisateur* :

- Nom d'utilisateur : par exemple `claude.pont`.
- Authentification : comptes manuels, avec un mot de passe fort. Ce mot de passe ne sert jamais à
  l'agent : il utilise le jeton.

Inscrire ce compte comme **enseignant non éditeur** dans chaque cours à lire (par exemple les
parcours Kali et IA). La capacité `local/aibridge:read` est donnée par défaut aux rôles
enseignant, enseignant éditeur et gestionnaire.

### 4. Donner la capacité `webservice/rest:use` (rôle système)

1. *Administration du site → Utilisateurs → Permissions → Définition des rôles* → **Ajouter un rôle**.
2. « Utiliser un rôle ou archétype » : **Aucun**, puis **Continuer**.
3. Remplir :
   - Nom abrégé : `pontia` ;
   - Nom complet : « Pont IA (services web) » ;
   - Types de contexte : **Système** uniquement.
4. Filtrer sur `webservice/rest:use` et cocher **Autoriser**, puis **Créer ce rôle**.
5. *Administration du site → Utilisateurs → Permissions → Attribution des rôles système* :
   choisir « Pont IA (services web) », puis ajouter `claude.pont`.

Ce rôle ne donne accès à aucun cours : il autorise seulement le protocole REST.

### 5. Activer le service et y autoriser le compte

*Administration du site → Serveur → Services web → Services externes* :

1. Ligne « Pont IA (Claude Code, Codex) » → **Modifier** :
   - cocher **Activé** ;
   - laisser **Utilisateurs autorisés seulement** coché ;
   - **Enregistrer les modifications**.
2. Même ligne → lien **Utilisateurs autorisés** : sélectionner `claude.pont`, puis **Ajouter**.
   Si une capacité manquante s'affiche sous le compte, revoir l'étape 4.

### 6. Créer le jeton

*Administration du site → Serveur → Services web → Gérer les jetons* → **Créer un jeton** :

| Champ | Valeur |
|---|---|
| Utilisateur | `claude.pont` |
| Service | Pont IA (Claude Code, Codex) |
| Restriction IP | Facultative : l'IP publique du poste ou de l'établissement |
| Valide jusqu'au | Cocher, et choisir une date (fin d'année scolaire par exemple) |

Copier le jeton **immédiatement** : Moodle ne l'affiche plus en entier ensuite. Ne jamais l'écrire
dans un fichier du dépôt.

### 7. Vérifier

*Administration du site → Serveur → Services web → Vue d'ensemble*, étape **« Vérifier les
capacités de l'utilisateur »** : choisir `claude.pont` et le service. Moodle signale tout droit
manquant.

### 8. Déclarer le serveur MCP sur le poste

Voir [`tools/moodle-mcp/README.md`](../../tools/moodle-mcp/README.md). Pour Claude Code :

```bash
claude mcp add moodle --scope user -e MOODLE_URL=https://VOTRE-MOODLE -e MOODLE_TOKEN=VOTRE_JETON -- node "D:/MoodleAIFeedback/v2/assignfeedback_ai/tools/moodle-mcp/src/index.js"
```

Ouvrir ensuite une nouvelle session : l'outil `moodle_site_info` doit répondre.

### 9. Activer l'écriture en bac à sable (facultatif)

1. *Plugins locaux → Pont IA* : cocher **Autoriser l'écriture** et la (ou les) **catégorie(s) bac à
   sable**. Leurs sous-catégories en font partie.
2. Donner au compte de service le rôle **Gestionnaire** dans la catégorie bac à sable : *Cours →
   Gérer les cours et catégories → la catégorie → Attribuer les rôles*. Ce rôle porte
   `local/aibridge:write`, la création de cours et la gestion des activités, dans cette catégorie
   seulement.
3. Sur le poste, passer `MOODLE_MCP_READONLY=0` au serveur MCP (voir son README) et rouvrir la
   session : les outils `moodle_create_course`, `moodle_upsert_section`, `moodle_create_module` et
   `moodle_delete_draft` apparaissent.

Vérification : `moodle_site_info` indique `bridge.enablewrites = 1` et `canwrite: true` pour la
catégorie.

---

## Réglages du plugin

*Administration du site → Plugins → Plugins locaux → Pont IA (Claude Code, Codex)* :

| Réglage | Rôle |
|---|---|
| Autoriser l'écriture | Désactivé par défaut. Création de cours, de sections et d'activités masqués. |
| Catégories bac à sable | Les seules catégories (et sous-catégories) où le pont peut créer ou modifier. |
| Conservation du journal | 90 jours par défaut ; 0 = illimitée. |

Le **journal** est dans *Plugins locaux → Journal du pont IA* : compte, fonction, cours, durée,
résultat et message d'erreur de chaque appel.

## Fonctions

| Fonction | Contenu |
|---|---|
| `local_aibridge_get_site_info` | Version, plugins du projet, réglages utiles, thème et classes CSS des parcours. |
| `local_aibridge_list_courses` | Cours lisibles par le compte. |
| `local_aibridge_get_course` | Réglages, heures de cours, groupes (effectifs), blocs, plan avec extensions. |
| `local_aibridge_get_module` | Détail complet d'une activité et de ses extensions (Correction IA, tuteur, EFE, surveillée…). |
| `local_aibridge_get_questions` | Banques, catégories, questions, export Moodle XML. |
| `local_aibridge_get_efe_competences` | Référentiel EFE (N1, N2, N3). |
| `local_aibridge_create_course` | Écriture : cours masqué dans une catégorie bac à sable (format Thématique, achèvement activé). |
| `local_aibridge_upsert_section` | Écriture : section créée si elle manque (masquée), nom et résumé. |
| `local_aibridge_create_module` | Écriture : activité masquée (page, étiquette, URL, leçon, test, devoir) par `create_module()` du cœur, avec les champs de son formulaire ; Tuteur IA et EFE par leurs propres champs, Correction IA écrite après coup. |
| `local_aibridge_set_lesson_pages` | Écriture : remplace les pages d'une leçon du pont (boutons Suivant / Précédent / Terminer), avec les images qu'elles citent en relatif (`files`, voir ci-dessous). |
| `local_aibridge_import_questions` | Écriture : Moodle XML dans une catégorie de la banque du cours (banque « système » créée masquée si besoin ; catégorie refusée si elle contient déjà des questions). |
| `local_aibridge_set_quiz_layout` | Écriture : remplit un test vide du pont (questions fixes par idnumber, tirages aléatoires par catégorie, mélange). |
| `local_aibridge_set_module_extras` | Écriture : activité surveillée (code de séance, mode examen, date prévue), activités à achever d'abord (`requires` : conditions d'accès « achèvement », ajoutées aux conditions existantes), durée minimale, Avancement (date de rendu ; jamais recopiée dans la fermeture d'un test). |
| `local_aibridge_delete_draft` | Écriture : supprime une activité, une section vide ou un cours créés par le pont et encore masqués ; ou (0.3.4) une catégorie de questions créée par le pont (`questioncategory`), avec ses sous-catégories et leurs questions, si toutes viennent du compte du pont et ne servent plus (supprimer d’abord le test), pour réimporter au même nom. |

Chaque fonction d'écriture accepte `dryrun` : Moodle vérifie les droits, le bac à sable et les
réglages, puis renvoie ce qu'il aurait fait, sans rien écrire. Garde-fous, vérifiés côté serveur :
écriture activée, catégorie bac à sable, capacité `local/aibridge:write` sur la catégorie,
`visible = 0` imposé à la création et vérifié après, EFE refusé sans compétence ou en code N1.

### Images des leçons (0.3.3)

`local_aibridge_set_lesson_pages` reçoit, à côté des pages, `files` : une liste JSON
`[{"filename": "schema.png", "content": "<base64>"}]`. Pour chaque page, le pont remplace les `src`
relatifs des balises `<img>` (`images/schema.png`, `./schema.png`) dont le dernier segment est le nom
d'un fichier envoyé par `@@PLUGINFILE@@/schema.png`, et range le fichier dans la zone de fichiers de
la page (`mod_lesson`, `page_contents`, `itemid` = id de la page) ; une image citée par deux pages est
rangée dans les deux. Les commentaires HTML et les `src` absolus (`http:`, `data:`, `/…`,
`@@PLUGINFILE@@`) ne sont pas touchés. Le retour liste les images de chaque page, et avertit d'un
`src` relatif sans fichier envoyé (laissé tel quel) ou d'un fichier cité par aucune page (ignoré).

Garde-fous, en plus de ceux de toute écriture (brouillon du pont encore masqué) :

| Contrôle | Règle |
|---|---|
| Nom | lettres, chiffres, point, tiret, tiret bas ; extension `png`, `jpg`, `jpeg`, `gif` ou `svg` ; unique dans l'appel |
| Contenu | base64 valide ; PNG, JPEG et GIF reconnus par `getimagesize` et conformes à l'extension ; SVG en UTF-8 avec une balise `<svg>`, sans script, gestionnaire `on…`, `javascript:`, objet incorporé ni entité XML |
| Poids | 2 Mo par image (ou moins si la limite du site, du cours ou de PHP est plus basse), 4 Mo et 40 images par appel : la requête, en base64, reste sous un `post_max_size` de 8 Mo |

Le journal ne garde que le nombre d'images, jamais leur contenu.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `Jeton invalide ou expiré` | Jeton supprimé, expiré ou créé pour un autre service. |
| `Accès refusé` (accessexception) | Service désactivé, ou compte absent des utilisateurs autorisés. |
| `webservice/rest:use` manquant | Rôle système de l'étape 4 non attribué. |
| `Droit manquant` / pas d'accès au cours | Compte non inscrit au cours. |
| Aucune ligne au journal | L'appel n'atteint pas le plugin : vérifier l'URL et les étapes 2 et 5. |
| `L'écriture par le pont est désactivée` | Réglage « Autoriser l'écriture » décoché (étape 9). |
| `n'est pas un bac à sable du pont` | Le cours ou la catégorie visés sont hors des catégories bac à sable. |
| `local/aibridge:write` manquant | Rôle Gestionnaire non attribué au compte dans la catégorie bac à sable. |
| `Unexpected keys (files)` sur les pages d'une leçon | Pont antérieur à 0.3.3 sur le site : le mettre à jour pour envoyer les images. |
| `Image … trop lourde` / `illisible` | Réduire ou compresser l'image (une capture passe souvent mieux en JPG) ; vérifier que l'extension correspond au format. |
| Outils d'écriture absents dans l'agent | `MOODLE_MCP_READONLY` n'est pas à `0` sur le poste, ou la session n'a pas été rouverte. |
