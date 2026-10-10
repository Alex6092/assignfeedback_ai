# local_aibridge — Pont IA (Claude Code, Codex)

Ce plugin expose des services web **en lecture** pour qu'un agent IA (Claude Code, Codex) lise un
cours Moodle dans le détail, réglages de nos plugins compris. L'agent y accède par le serveur MCP
local [`tools/moodle-mcp`](../../tools/moodle-mcp/README.md).

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

---

## Réglages du plugin

*Administration du site → Plugins → Plugins locaux → Pont IA (Claude Code, Codex)* :

| Réglage | Rôle |
|---|---|
| Autoriser l'écriture | Désactivé par défaut (création d'activités : phase suivante). |
| Catégories bac à sable | Les seules catégories où le pont pourra créer ou modifier. |
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

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `Jeton invalide ou expiré` | Jeton supprimé, expiré ou créé pour un autre service. |
| `Accès refusé` (accessexception) | Service désactivé, ou compte absent des utilisateurs autorisés. |
| `webservice/rest:use` manquant | Rôle système de l'étape 4 non attribué. |
| `Droit manquant` / pas d'accès au cours | Compte non inscrit au cours. |
| Aucune ligne au journal | L'appel n'atteint pas le plugin : vérifier l'URL et les étapes 2 et 5. |
