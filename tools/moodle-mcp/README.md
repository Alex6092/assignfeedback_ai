# moodle-mcp — relier Claude Code et Codex à Moodle

Serveur MCP local (stdio) qui donne à un agent IA un accès **en lecture** à un site Moodle
équipé du plugin [`local_aibridge`](../../local/aibridge). L'agent voit un cours dans le détail,
réglages de nos plugins compris : Correction IA, Tuteur IA et son brief, EFE, activités
surveillées, verrous, durée minimale, questions IA, etc.

```
Claude Code / Codex ──stdio──> moodle-mcp (Node ≥ 20) ──HTTPS POST + jeton──> Moodle : local_aibridge
```

Le pont ne renvoie **aucune donnée nominative d'élève** : ni noms, ni copies, ni notes, ni
tiers-temps individuels. Il ne renvoie **aucun secret** non plus : les clés d'API et les mots de
passe de test sont masqués. Chaque appel est inscrit au journal du pont, dans Moodle.

## Outils

| Outil | Rôle |
|---|---|
| `moodle_site_info` | Version de Moodle, plugins du projet et leur version, réglages utiles, thème et classes CSS des parcours, état du pont. À appeler en premier. |
| `moodle_list_courses` | Cours lisibles par le pont, avec filtre texte. |
| `moodle_get_course` | Réglages du cours, heures de cours, groupes, blocs, plan (sections et activités) avec le résumé des extensions de chaque activité. |
| `moodle_get_module` | Détail complet d'une activité : instance, note, achèvement, accès, contenu (pages de leçon, structure du test, plugins du devoir, grille…), extensions. |
| `moodle_get_questions` | Banques, catégories, questions, export Moodle XML (options IA comprises, sans clé). |
| `moodle_get_efe_competences` | Référentiel EFE (N1, N2, N3) proposé par `local_efenotes`. |
| `moodle_snapshot_course` | Écrit tout un cours sur le disque (plan `README.md`, JSON, HTML bruts, XML, SCSS du thème) pour le comparer aux fichiers sources d'un parcours. |

Les contenus HTML longs sont tronqués par défaut (`html: "truncate"`), pour ne pas dépasser la
taille maximale d'une réponse d'outil. Pour le texte intégral, passer `html: "full"`, ou faire un
snapshot.

## 1. Côté Moodle (une fois, par l'administrateur)

1. Copier `local/aibridge` du dépôt dans `public/local/aibridge` du site (Moodle 5.x), puis
   **Administration du site → Notifications**.
2. **Administration du site → Serveur → Services web → Vue d'ensemble** : activer les services web
   et le protocole **REST**.
3. Créer un compte de service, par exemple `claude.pont` (authentification manuelle, mot de passe
   fort). L'inscrire comme **enseignant non éditeur** dans les cours à lire. La capacité
   `local/aibridge:read` est donnée par défaut aux enseignants.
4. **Services externes** : activer le service **« Pont IA (Claude Code, Codex) »**, puis dans
   **Utilisateurs autorisés**, ajouter `claude.pont`. Le compte a aussi besoin de la capacité
   `webservice/rest:use`, par exemple via un rôle système dédié.
5. **Gérer les jetons** : créer un jeton pour `claude.pont`, sur ce service, avec une **date
   d'expiration**. Restreindre l'IP si possible.
6. Vérifier : **Administration du site → Plugins → Plugins locaux → Journal du pont IA**. Les
   appels y apparaissent dès le premier usage.

## 2. Installer le serveur (une fois, sur le poste)

```bash
cd tools/moodle-mcp
npm install
npm test
```

## 3. Déclarer le serveur

Variables d'environnement :

| Variable | Rôle |
|---|---|
| `MOODLE_URL` | Adresse du site, en `https` (par exemple `https://moodle.lycee.fr`). |
| `MOODLE_TOKEN` | Jeton du service `local_aibridge`. **Ne jamais le committer.** |
| `MOODLE_TIMEOUT_MS` | Délai maximal d'un appel, par défaut `120000`. |
| `MOODLE_ALLOW_HTTP` | `1` pour accepter `http` sur un réseau de confiance. Sinon, `http` n'est accepté que pour `localhost`. |
| `MOODLE_MCP_READONLY` | Lecture seule par défaut. Les outils d'écriture (phase 3) ne seront proposés qu'avec `0`. |

### Claude Code

```bash
claude mcp add moodle --scope user -e MOODLE_URL=https://moodle.lycee.fr -e MOODLE_TOKEN=VOTRE_JETON -- node "D:/MoodleAIFeedback/v2/assignfeedback_ai/tools/moodle-mcp/src/index.js"
```

Puis redémarrer la session et vérifier avec `/mcp` que `moodle` est connecté.

### Codex

Dans `~/.codex/config.toml` :

```toml
[mcp_servers.moodle]
command = "node"
args = ["D:/MoodleAIFeedback/v2/assignfeedback_ai/tools/moodle-mcp/src/index.js"]
env = { MOODLE_URL = "https://moodle.lycee.fr", MOODLE_TOKEN = "VOTRE_JETON" }
```

Ou, en une commande :

```bash
codex mcp add moodle --env MOODLE_URL=https://moodle.lycee.fr --env MOODLE_TOKEN=VOTRE_JETON -- node "D:/MoodleAIFeedback/v2/assignfeedback_ai/tools/moodle-mcp/src/index.js"
```

## 4. Premier essai

Demander à l'agent :

> Avec le serveur moodle, donne-moi les informations du site, puis photographie le cours
> « Parcours Cyber Kali » dans `C:\Users\alexg\Desktop\Nouveaux cours\_snapshots`.

Le snapshot crée un dossier au nom abrégé du cours :

```
README.md                plan du cours, extensions de chaque activité, liens
site.json, course.json   site et cours
theme/                   « Code SCSS initial » et « SCSS brut » du thème
modules/*.json           détail de chaque activité
contenus/*/*.html        HTML brut : pages de leçon, pages, consignes (@@PLUGINFILE@@ conservé)
questions/*.xml          questions de chaque test (ordre des emplacements) et de chaque banque
efe-competences.json     référentiel EFE
```

## Dépannage

| Message | Cause probable |
|---|---|
| `Jeton invalide ou expiré` | Jeton supprimé, expiré, ou créé pour un autre service. |
| `Accès refusé : le service « local_aibridge » doit être activé…` | Service désactivé, ou compte absent des utilisateurs autorisés. |
| `Droit manquant…` / `n'a pas accès à ce cours` | Le compte n'est pas inscrit au cours, ou n'a pas `local/aibridge:read`. |
| `Réponse non JSON` | Site en maintenance, ou URL qui ne pointe pas sur la racine de Moodle. |
| `Moodle injoignable` | Réseau, VPN, certificat, ou `MOODLE_URL` erronée. |
