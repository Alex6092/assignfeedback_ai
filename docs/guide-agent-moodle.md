# Guide : construire ses parcours Moodle avec Claude ou Codex

Ce guide explique comment utiliser au quotidien l'ensemble « agent IA + Moodle » de ce dépôt :
- **ce qu'il fait** ;
- **comment l'installer** ;
- **quoi demander** à l'agent, avec des phrases toutes prêtes ;
- **quoi faire quand ça coince**.

Les README de chaque composant donnent le détail technique.

## Sommaire

1. [Les quatre pièces, en une minute](#1-les-quatre-pièces-en-une-minute)
2. [Installation (une fois)](#2-installation-une-fois)
3. [Vérifier que tout marche](#3-vérifier-que-tout-marche)
4. [Utilisation au quotidien](#4-utilisation-au-quotidien)
5. [Les règles de sécurité](#5-les-règles-de-sécurité)
6. [Mettre à jour](#6-mettre-à-jour)
7. [Dépannage](#7-dépannage)
8. [Aide-mémoire](#8-aide-mémoire)

---

## 1. Les quatre pièces, en une minute

```
Vous ──> Claude Code / Codex ──(skill moodle-ciel : la méthode)
                 │
                 └──(serveur MCP « moodle », sur votre PC)──HTTPS + jeton──> Moodle : plugin local_aibridge
                                                                                 └── local_lessonexport (PDF)
```

| Pièce | Où | À quoi elle sert |
|---|---|---|
| **Skill `moodle-ciel`** | `skills/moodle-ciel`, installé dans `~/.claude/skills` et `~/.agents/skills` | Votre façon de construire un parcours BTS CIEL : carte, leçons, QCM, TP, tests sommatifs, EFE, contrôles. L'agent s'en sert tout seul dès que vous parlez de parcours, de module ou de test. |
| **Serveur MCP « moodle »** | `tools/moodle-mcp`, lancé par l'agent sur votre PC | Les « mains » de l'agent dans Moodle : lire un cours, créer des brouillons, exporter en PDF. |
| **Pont `local_aibridge`** | plugin Moodle | Les services web que le serveur MCP appelle. Il applique les garde-fous : bac à sable, tout est masqué, et seuls les brouillons du pont peuvent être supprimés. |
| **Leçons imprimables `local_lessonexport`** | plugin Moodle | Version imprimable de chaque leçon, que l'élève enregistre en PDF. |

L'agent ne crée **jamais rien de visible** pour les élèves, et ne crée que dans la catégorie bac à
sable. C'est toujours vous qui relisez et publiez.

---

## 2. Installation (une fois)

### 2.1 Côté Moodle (administrateur)

1. **Plugins.** Installez les ZIP du dossier `dist/` (*Administration du site → Plugins → Installer
   un plugin*), puis passez par **Notifications** :
   - `local_aibridge.zip` : le pont ;
   - `local_lessonexport.zip` : les leçons imprimables.
2. **Services web, compte de service et jeton.** Suivez pas à pas
   [`local/aibridge/README.md`](../local/aibridge/README.md), étapes 1 à 7.
   Sur l'instance de test, c'est déjà fait : le compte s'appelle `agentia`.
3. **Écriture en bac à sable** (étape 9 du même README) :
   - *Plugins locaux → Pont IA* : cocher **Autoriser l'écriture** et la catégorie **Bac à sable - IA**.
   - Donner le rôle **Gestionnaire** à `agentia` **dans la catégorie** bac à sable :
     1. Ouvrez la page de la catégorie (`course/index.php?categoryid=9`).
     2. Cliquez sur l'onglet **Permissions**.
     3. Dans la liste déroulante en haut, choisissez **Attribuer les rôles**.
     4. Cliquez sur **Gestionnaire**, sélectionnez `agentia`, puis cliquez sur **◄ Ajouter**.

### 2.2 Côté PC (vous)

**a) Le jeton dans l'environnement Windows.** Le jeton n'est écrit dans aucun fichier : le serveur le
lit dans vos variables d'environnement. Dans un terminal :

```bash
setx MOODLE_URL https://moodle.tspro.fr
```

```bash
setx MOODLE_TOKEN VOTRE_JETON
```

```bash
setx MOODLE_MCP_READONLY 0
```

`MOODLE_MCP_READONLY=0` active les outils d'écriture. Avec `1`, l'agent peut seulement lire.
**Redémarrez ensuite l'application Claude**, sinon elle ne voit pas les nouvelles variables.

**b) Node.js 20 ou plus** doit être installé. Ensuite, une seule fois, dans le dossier
`tools/moodle-mcp` :

```bash
npm install
```

**c) Déclarer le serveur MCP.**
- **Dans le dossier du dépôt**, rien à faire : le fichier `.mcp.json` du dépôt le déclare déjà.
- **Dans toutes vos autres sessions (Claude Code, application de bureau)**, ajoutez une section
  `mcpServers` dans `C:\Users\<vous>\.claude.json`. Sur ce PC, c'est fait ; une sauvegarde du
  fichier d'avant est dans `.claude.json.sauvegarde`.

  ```json
  "mcpServers": {
    "moodle": {
      "type": "stdio",
      "command": "node",
      "args": ["D:/MoodleAIFeedback/v2/assignfeedback_ai/tools/moodle-mcp/src/index.js"],
      "env": { "MOODLE_URL": "https://moodle.tspro.fr", "MOODLE_MCP_READONLY": "0" }
    }
  }
  ```

  Si vous avez la commande `claude` en ligne de commande, celle-ci fait la même chose :
  `claude mcp add moodle --scope user -e MOODLE_URL=… -e MOODLE_MCP_READONLY=0 -- node "…/src/index.js"`.
- **Codex** : voir [`tools/moodle-mcp/README.md`](../tools/moodle-mcp/README.md) (`~/.codex/config.toml`).

**d) Le skill.** Il est installé par des liens vers le dépôt, donc une mise à jour du dépôt le met à
jour partout :

```bash
powershell -ExecutionPolicy Bypass -File tools\install-skill.ps1
```

**e) Redémarrez l'application Claude**, puis ouvrez une nouvelle session.

---

## 3. Vérifier que tout marche

Dans une nouvelle session, demandez :

> Donne-moi les informations du site Moodle.

La réponse doit contenir :
- `local_aibridge` dans sa version attendue (0.3.1 ou plus) ;
- `bridge.enablewrites: 1` ;
- `canwrite: true` sur la catégorie bac à sable.

Dans Claude Code, la commande `/mcp` affiche aussi `moodle` comme **connecté**.

| Ce que vous voyez | Ce que ça veut dire |
|---|---|
| L'agent ne connaît aucun outil `moodle_…` | Serveur MCP non déclaré, ou application pas redémarrée (§ 2.2 c et e). |
| Seulement les outils de lecture | `MOODLE_MCP_READONLY` n'est pas à `0`, ou l'application n'a pas été redémarrée après le `setx`. |
| `canwrite: false` | Rôle Gestionnaire absent dans la catégorie (§ 2.1, étape 3). |
| `Jeton invalide` | Jeton expiré ou recopié de travers : régénérez-le dans Moodle. |

---

## 4. Utilisation au quotidien

Parlez normalement : le skill se déclenche tout seul. Vous pouvez aussi l'appeler explicitement
avec `/moodle-ciel` dans Claude Code, ou `$moodle-ciel` dans Codex.

### 4.1 Concevoir un parcours

> Je veux un parcours de 6 modules sur l'administration Linux pour les 1re année, environ 18 h.
> Cours Moodle cible : le bac à sable.

L'agent procède dans l'ordre suivant :
1. il pose les questions de cadrage : postes, format des TP, compétences visées, dates ;
2. il propose une **carte du parcours** (`00-carte.md`) : modules, compétences, codes EFE, tests, planning ;
3. **vous validez la carte**, puis il produit **un module à la fois**, dans un dossier `Mx-sujet/` :
   - leçon ;
   - QCM d'entraînement ;
   - TP (consigne, corrigé, tuteur) ;
   - test sommatif ;
   - fiche enseignant ;
   - manifeste `module.deploy.json` ;
4. il **contrôle** chaque module avec `scripts/check-module.mjs` ;
5. il vous présente le module et attend votre retour avant le suivant.

Pour gagner du temps, précisez dès le début :
- le **format des TP** : TP de labo en phases, ou exercices courts ;
- le **temps d'exécution** sur les postes de la salle ;
- les notions **en bonus**, à ne pas évaluer ;
- l'**identifiant Moodle de l'enseignant** à désigner dans EFE (`course.teacher_id`).

### 4.2 Regarder ou imiter un cours existant

> Regarde comment est réglé le test sommatif du module 3 dans mon cours IA.

> Photographie le cours IA dans `C:\…\_snapshots` pour qu'on le compare aux fichiers.

L'agent lit les réglages réels, extensions comprises : Correction IA, tuteur, EFE, surveillance.
Il ne voit jamais de donnée d'élève ni de secret.

### 4.3 Mettre un module dans Moodle

> Déploie le module M2 (dossier `…\M2-neurone`) dans le cours bac à sable 74.

L'agent :
1. lance une **simulation**, et vous montre ce qui sera créé ;
2. crée tout **masqué**, dans l'ordre du manifeste :
   - la section ;
   - la leçon et ses pages ;
   - les questions (une catégorie par test) ;
   - le QCM avec tirage au sort ;
   - les TP, avec Correction IA, Tuteur IA et EFE ;
   - le test sommatif, avec surveillance, code de séance et mode examen ;
   - l'Avancement ;
3. **relit** chaque activité dans Moodle et vous donne le **rapport d'écart**, qui doit être vide.

Pour créer d'abord un cours bac à sable neuf :

> Crée un cours masqué « Essai parcours Linux » dans la catégorie bac à sable, puis déploie-y M1.

Pour recommencer un module :

> Supprime les brouillons du module M2 dans le cours 74, puis redéploie-le.

Le pont ne supprime que ses propres brouillons, et seulement tant qu'ils sont masqués.

### 4.4 Ce qui reste à faire à la main après un déploiement

L'agent vous donne cette liste à la fin. En résumé :
- **relire** dans Moodle la leçon, les consignes, les tests et le **brief du Tuteur IA** de chaque TP ;
- vérifier les solutions des questions **CodeRunner** (bouton « Check ») ;
- s'assurer que le **cron** tourne : corrections IA, briefs, reports EFE ;
- rendre une **copie de test** sur un TP pour voir arriver la Correction IA ;
- **publier** : rendre visibles la section et les activités ;
- après chaque test sommatif, **publier la correction** avec le bouton « Correction » du bloc Avancement, une fois que tout le monde l'a passé.

Les vidéos (`mod_videoqcm`) se créent encore à la main.

### 4.5 Les leçons en PDF

**Pour les élèves, dans Moodle :**
1. Ouvrez la page **🖨 Leçons imprimables** du cours, sous la page du cours ou dans son menu
   « Plus » (`local/lessonexport/index.php?id=COURS`).
2. Cliquez sur **Proposer les leçons imprimables aux élèves**. Vous seul voyez ce bouton, et vous
   voyez toujours la page, même avant l'activation.
3. Chaque leçon a alors un bouton **Version imprimable**. L'élève fait **Imprimer → Enregistrer au
   format PDF**, en cochant « Graphiques d'arrière-plan » pour garder les couleurs.
   **Imprimer toutes les leçons** met une leçon par page.

Une leçon fermée, restreinte ou protégée par un mot de passe ne s'imprime pas. Pendant un test en
mode examen, l'élève est renvoyé vers le test.

**Pour vous, en lot :**

> Exporte les leçons du cours 57 en PDF dans `C:\Users\alexg\Desktop\PDF-IA`.

Vous obtenez un PDF par leçon et `00-parcours-complet.pdf`, avec la mise en page de Moodle, images
comprises. Edge les imprime en arrière-plan, sans ouvrir de fenêtre.

---

## 5. Les règles de sécurité

Ces règles sont vérifiées par Moodle : l'agent ne peut pas les contourner.

| Règle | Conséquence |
|---|---|
| L'écriture est **désactivée** par défaut | Il faut la cocher dans *Plugins locaux → Pont IA*. Décochée, l'agent ne peut que lire. |
| Écriture seulement dans les **catégories bac à sable** | Un vrai cours ne peut pas être modifié, même par erreur. |
| Tout est créé **masqué** | Les élèves ne voient rien tant que vous n'avez pas publié. |
| Suppression limitée aux **brouillons du pont encore masqués** | Rien de ce que vous avez créé ou publié ne peut être supprimé. |
| **Aucune donnée d'élève** lue, **aucun secret** transmis | L'agent ne voit ni noms, ni copies, ni notes, ni clés d'API. |
| **Journal** de chaque appel | *Plugins locaux → Journal du pont IA* : qui, quoi, quand, avec quel résultat. |

Pour couper l'écriture d'un coup : décochez **Autoriser l'écriture**, ou passez
`MOODLE_MCP_READONLY` à `1` sur le PC.

---

## 6. Mettre à jour

1. **Récupérer le dépôt à jour** (branche `main`). Le skill et le serveur MCP sont mis à jour en même
   temps, car ils sont lus directement dans le dépôt.
2. **Si un plugin Moodle a changé**, réinstaller son ZIP. Pour reconstruire les ZIP depuis le dépôt :

   ```bash
   git archive --format=zip --prefix=aibridge/ -o dist/local_aibridge.zip HEAD:local/aibridge
   ```

   ```bash
   git archive --format=zip --prefix=lessonexport/ -o dist/local_lessonexport.zip HEAD:local/lessonexport
   ```

   Puis passer par **Notifications** dans Moodle.
3. **Ouvrir une nouvelle session** : le serveur MCP n'est relancé qu'au démarrage d'une session.

---

## 7. Dépannage

| Problème | Solution |
|---|---|
| « L'écriture par le pont est désactivée » | Cocher **Autoriser l'écriture** (§ 2.1, étape 3). |
| « … n'est pas un bac à sable du pont » | Le cours visé n'est pas dans la catégorie bac à sable : c'est voulu. Créez un cours bac à sable. |
| « Déjà présent dans la section » au déploiement | Des activités du même nom existent déjà : demandez à l'agent de supprimer les brouillons d'abord. |
| « La catégorie … contient déjà des questions » | Un import a déjà eu lieu : changez le nom de catégorie dans le manifeste, ou videz la catégorie dans Moodle. |
| « Cet objet n'a pas été créé par le pont » | Normal : le pont ne supprime que ses propres brouillons. Supprimez à la main dans Moodle. |
| Brief du Tuteur IA resté en `pending` | Le cron ne tourne pas, ou le LLM est injoignable. |
| PDF sans couleurs (impression par l'élève) | Cocher « Graphiques d'arrière-plan » dans la fenêtre d'impression. |
| Export en lot : « Ni Edge ni Chrome trouvé » | Définir `MOODLE_PDF_BROWSER` avec le chemin du navigateur. |
| Encadré d'un autre plugin sur une impression | Signalez-le : la page imprimable masque déjà ceux qu'on connaît (accès réseau, notifications). |

Le journal du pont (*Plugins locaux → Journal du pont IA*) donne le message d'erreur exact de chaque
appel refusé.

---

## 8. Aide-mémoire

**Phrases utiles**

| Vous voulez… | Dites |
|---|---|
| Vérifier la connexion | « Donne-moi les informations du site Moodle. » |
| Voir un cours | « Montre-moi le plan du cours 57. » |
| Concevoir | « Prépare la carte d'un parcours sur … » puis « Produis le module M1. » |
| Contrôler | « Contrôle le module du dossier … » |
| Déployer | « Déploie le module du dossier … dans le cours 74 (simulation d'abord). » |
| Recommencer | « Supprime les brouillons du module M2 dans le cours 74. » |
| PDF | « Exporte les leçons du cours 57 en PDF dans … » |

**Outils du serveur MCP**

| Lecture | Écriture (bac à sable, `MOODLE_MCP_READONLY=0`) |
|---|---|
| `moodle_site_info`, `moodle_list_courses`, `moodle_get_course`, `moodle_get_module`, `moodle_get_questions`, `moodle_get_efe_competences`, `moodle_snapshot_course`, `moodle_export_lessons_pdf` | `moodle_deploy_module`, `moodle_create_course`, `moodle_upsert_section`, `moodle_create_module`, `moodle_set_lesson_pages`, `moodle_import_questions`, `moodle_set_quiz_layout`, `moodle_set_module_extras`, `moodle_delete_draft` |

**Pour aller plus loin**

| Document | Contenu |
|---|---|
| [`skills/moodle-ciel/SKILL.md`](../skills/moodle-ciel/SKILL.md) | La méthode, les règles non négociables. |
| [`skills/moodle-ciel/references/reglages-moodle.md`](../skills/moodle-ciel/references/reglages-moodle.md) | Les réglages de chaque type d'activité. |
| [`skills/moodle-ciel/references/deploiement.md`](../skills/moodle-ciel/references/deploiement.md) | Le manifeste `module.deploy.json`, champ par champ. |
| [`local/aibridge/README.md`](../local/aibridge/README.md) | Installation et fonctions du pont. |
| [`tools/moodle-mcp/README.md`](../tools/moodle-mcp/README.md) | Le serveur MCP (Claude Code, Codex). |
| [`local/lessonexport/README.md`](../local/lessonexport/README.md) | Les leçons imprimables. |
