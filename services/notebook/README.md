# Notebooks Moodle — version 0.1.0 alpha

Cette implémentation ajoute un environnement Python individuel aux devoirs Moodle 5.2.
Elle comprend les sources des deux plugins, le service de travail, le correcteur,
l’adaptateur JupyterHub, l’extension JupyterLab et les outils MCP. Le pilote sur VM est
une étape de réception : les tests locaux ne démontrent pas la capacité de 35 sessions,
le fonctionnement réseau réel ni le report vers EFE.
Le [bilan de livraison](VALIDATION.md) précise les tests exécutés et les essais restant à faire.

## Installer les plugins

Installer ou mettre à jour, dans cet ordre :

1. `local_aifeedback` existant, puis `mod/assign/feedback/ai` (2.1.0).
2. `local/notebook`, puis `mod/assign/submission/notebook`.
3. `local/aibridge` et le serveur `tools/moodle-mcp`.

Les chemins sont relatifs au répertoire `public/` de Moodle 5.2. Exécuter la mise à jour
Moodle et son cron toutes les minutes. Les plugins n’exécutent jamais le code Python
dans PHP. Les autres types de remise gardent leur circuit de correction existant.

Pour produire les archives, lancer `python tools/build-notebook-release.py` à la racine
du dépôt. Le dossier `dist/notebooks-0.1.0` contient les quatre ZIP Moodle, les sources
de la plateforme et leurs empreintes SHA256. Les deux archives nommées `notebook` à
l’intérieur s’installent à des emplacements différents : `local/notebook` et
`mod/assign/submission/notebook`.

Dans `config.php`, définir trois secrets distincts, chacun créé avec `openssl rand -hex 32` :

```php
$CFG->local_notebook_workspacetoken = 'secret du service des espaces';
$CFG->local_notebook_gradertoken = 'secret du service de correction';
$CFG->local_notebook_moodletoken = 'secret du service vers Moodle';
```

Dans Administration → Plugins locaux → Notebooks Python, renseigner les URL **HTTPS**
des deux API privées et l’URL publique du Hub. Les secrets restent hors des réglages
exportés par MCP. Autoriser le compte agent à `local/notebook:manage` dans le bac à sable.

## Préparer les VM

Base proposée : Linux avec Docker Engine et Compose v2. VM de travail : 16 vCPU,
96 Go de RAM, disque SSD 300 Go. VM correcteur : 4–8 vCPU, 16 Go de RAM, SSD 100 Go.
Le code du projet ne crée ni ne modifie automatiquement vos VM Proxmox.

Avant de créer les réseaux Docker de la VM de travail, adapter `/etc/docker/daemon.json`
à `daemon.json.example`, avec une plage sans conflit avec les réseaux de l’établissement.
Le découpage proposé en /28 réserve suffisamment de réseaux distincts pour 35 élèves
et leurs enseignants. Ne pas conserver un pool par défaut trop petit pour un réseau
par utilisateur. Redémarrer Docker après cette préparation, avant de lancer les services.

Sur la VM de travail, monter un volume **XFS dédié** à `/srv/notebooks`, avec l’option
`prjquota`. Il ne contient que les espaces notebook. Exécuter :

```sh
sudo sh provision-slots.sh /srv/notebooks 128
```

Chaque emplacement a un quota disque dur de 2 Go. Le service refuse de créer un espace
sans emplacement préparé. Les 128 emplacements concernent les espaces persistants
(élève × devoir × version), pas seulement les sessions simultanées. Étendre le stockage
et le nombre d’emplacements lorsque les cours s’accumulent. Ne pas déplacer ces dossiers
sur un système sans quotas : le marqueur `.quota` atteste une préparation, il ne remplace
pas la vérification régulière par `xfs_quota -x -c 'report -p -h' /srv/notebooks`.

Depuis ce dossier sur la VM :

```sh
docker build -f images/Dockerfile -t moodle-notebook-python:0.1.0 .
docker build -f images/Dockerfile.proxy -t moodle-notebook-proxy:0.1.0 .
cp .env.example .env
# Compléter les valeurs, dont cinq secrets indépendants.
docker compose -f compose.workspace.yml up -d --build
```

Transférer **la même image Python** sur la VM correcteur (registre interne ou
`docker save` / `docker load`), puis :

```sh
docker compose -f compose.grader.yml up -d --build
```

Le correcteur lance au maximum quatre conteneurs simultanés. Ses entrées sont privées,
le noyau de l’élève utilise l’UID 1000 et les résultats sont écrits par un contrôleur
distinct. Aucun réseau, secret d’API ou socket Docker n’est fourni au noyau.
Le socket Docker monté dans les services de contrôle leur donne un pouvoir important :
les deux VM sont dédiées et leurs API ne doivent pas être exposées publiquement.

Après le pilote, publier les images validées dans le registre interne et remplacer les
tags par leurs digests dans `.env`. Conserver les digests des trois images et les
versions des plugins ensemble ; une mise à jour d’image n’est pas appliquée pendant un TP.
La validation inscrit l’identifiant immuable de l’image dans la version du sujet. Conserver
les anciennes images sur les deux VM et leurs identifiants dans `PYTHON_IMAGES` ; un TP
ancien refuse de s’exécuter si son environnement a été retiré, sans basculer silencieusement
sur le nouvel environnement.

## Réseau et connexion

- DNS public et interne : domaine du Hub et wildcard des sous-domaines utilisateurs.
- Certificat TLS couvrant le domaine et le wildcard ; domaine séparé du Moodle.
- Reverse proxy HTTPS/WebSockets : adapter `nginx.conf.example` sur la VM de travail.
- Deux virtual hosts API **privés**, protégés par les ACL réseau, dont celui du correcteur
  relaie `127.0.0.1:8083`. Autoriser Moodle à les joindre et le service de travail à joindre Moodle.
- Ne publier ni Docker, ni les ports internes du Hub, ni l’administration Proxmox.

Un POST depuis Moodle remet un code de 60 secondes à usage unique au Hub. Le Hub échange
ce code côté serveur et ouvre uniquement le volume autorisé. Les sessions sont vérifiées
toutes les 30 secondes ; en cas de refus ou d’indisponibilité Moodle, le service arrête
le conteneur, y compris directement via Docker si le Hub ne répond plus. Les fichiers
restent persistants. Vérifier la coupure en moins d’une minute pendant le pilote.

Chaque utilisateur possède un réseau Docker interne distinct. Aucun accès Internet
n’est disponible par défaut ; le DNS externe est aussi fermé (résolveur de secours sur
la boucle locale du conteneur, voir [réseau Docker](https://docs.docker.com/engine/network/)).
Pour un TP utilisant une API, l’administrateur ajoute ses
noms DNS exacts à `API_ALLOWLIST`, puis l’enseignant les sélectionne dans `egress_hosts`
du dossier pédagogique. Un proxy propre à cet espace autorise uniquement HTTP/HTTPS
vers ces domaines publics et refuse les plages privées. La correction reste hors réseau :
prévoir des données d’essai locales pour les tests. Ne pas ajouter de suffixe générique
ou d’API permettant de relayer arbitrairement d’autres destinations.

## Préparer un TP

1. Créer un devoir individuel, activer « Notebook Jupyter », les brouillons et « Correction IA ».
2. Ouvrir « Préparer le TP notebook » depuis les réglages de remise.
3. Importer le JSON de `skills/moodle-ciel/templates/notebook/bundle.json`, ou demander
   « Générer un TP notebook ». Le JSON conserve un notebook maître et un barème IA.
4. Ouvrir la version enseignante dans JupyterLab, éditer les cellules nbgrader, enregistrer
   puis utiliser « Version Moodle ». Cela crée une nouvelle version privée.
5. Vérifier le corrigé et les tests. Le cron récupère le résultat ; la référence doit
   obtenir 100 % et la réponse vide doit perdre des points.
6. Sélectionner « Utiliser cette version pour le devoir », puis publier le devoir.

Les sujets commencés restent attachés à leur version initiale. Les modifications du
maître ne modifient pas les espaces déjà distribués. Les réponses doivent rester dans
les cellules identifiées : la correction rétablit l’ordre du maître et ses tests. Les
cellules libres supplémentaires ne remplacent pas les cellules de réponse.

L’élève sauvegarde, clique sur « Remettre dans Moodle », confirme la capture puis la remise
native du devoir. La capture arrête le noyau pour figer les fichiers. Une capture n’est
pas une remise ; seule la remise native déclenche la correction. La déclaration de
soumission Moodle n’est jamais acceptée automatiquement par le plugin.

## Note, incidents et supervision

Le score est `tests × poids / 100 + IA × (100 − poids) / 100`, avec un poids par défaut
de 70. Il est converti par le plugin IA selon le barème du devoir. Le réglage `autograde`
existant décide du report automatique ; les réglages EFE existants restent applicables.
La proposition détaillée garde les deux scores dans son champ `notebook` et dans le
suivi du notebook. Une correction ancienne est écartée si la tentative ou la note a changé.

Une panne technique laisse la note en attente. Une erreur de code est notée par les tests.
Limites : 60 secondes par cellule, cinq minutes par copie, 2 Go RAM, 1 vCPU, 128 processus.
L’administrateur règle `CELL_TIMEOUT` (1–600 secondes) et `COPY_TIMEOUT` (1–3600 secondes)
dans l’environnement du correcteur. Une validation exécute deux copies successives.
Au redémarrage du correcteur, les anciens conteneurs de correction sont supprimés avant
la reprise des jobs interrompus. Ne pas lancer plusieurs instances du service correcteur
sur le même démon Docker : la concurrence de quatre travaux est gérée dans le service.
Une erreur d’application de note conserve le résultat et le cron réessaie sans rappeler
l’IA. Pour une erreur définitive de tests ou d’IA, utiliser la relance de Correction IA ;
cela crée une nouvelle génération de correction. Aucun bouton ne redémarre toutes les
copies d’un cours silencieusement.

La page de préparation liste les états et erreurs. `GET /v1/health` (authentifié) fournit
les sessions enregistrées, les espaces persistants, les travaux par état et l’espace
libre du volume d’état. Utiliser aussi `docker stats`, les journaux Compose et le rapport
de quotas ; alerter sur manque de disque, travaux bloqués et échec des sauvegardes.

## Sauvegardes et données personnelles

La sauvegarde Moodle inclut sujets, versions et copies remises. La restauration ne
réactive ni tickets ni traitements distants. Elle ne restaure pas les espaces de travail
non remis : sauvegarder également `/srv/notebooks`, les volumes `workspace-state`,
`hub-state`, `grader-state`, les secrets et les digests des images.
Une réinitialisation de cours conserve les sujets et efface les travaux ; après suppression
de l’activité, le cron nettoie les versions orphelines et réessaie l’effacement distant si nécessaire.

Arrêter les services pour une sauvegarde cohérente des bases SQLite et des volumes, ou
employer une procédure de snapshot cohérente documentée par l’exploitant. Tester une
restauration sur des URL de test ; ne pas laisser la copie restaurée appeler le vrai Moodle.

Le fournisseur de confidentialité exporte les copies Moodle et les fichiers externes.
Les fichiers externes sont exportés par blocs JSON de 4 Mio : regrouper par `workspace`
et `path`, trier par `offset`, décoder `base64` puis concaténer. Les liens symboliques sont
décrits sans suivre leur cible. La suppression efface les espaces et les jobs des deux
services ; une panne externe ou une correction encore en cours demande de réessayer.
Les sujets restent du matériel de cours avec auteur anonymisé. Appliquer séparément
la durée de conservation décidée par l’établissement aux sauvegardes.

## Vérification avant ouverture aux élèves

Tests locaux :

```sh
python -m venv .venv
. .venv/bin/activate
pip install -c constraints.txt -e '.[test]'
pytest -q
php tests/grade_contract.php
cd ../../tools/moodle-mcp && npm ci && npm test
```

Sur la VM de test avec l’image construite : `NOTEBOOK_DOCKER_TESTS=1 PYTHON_IMAGE=... pytest -q`.
Dans un Moodle de test, lancer PHPUnit pour `local/notebook/tests/grading_test.php`, puis
vérifier : remise et nouvelle tentative ; report automatique activé/désactivé ; note manuelle
préservée ; EFE désactivé/immédiat/différé ; coupure d’une séance ouverte ; perte du Hub ;
interdiction entre utilisateurs ; réponse avec sorties falsifiées ; erreur Python et boucle
infinie ; quota disque ; copie ZIP de sauvegarde/restauration ; export et suppression utilisateur.

Enfin, lancer 35 utilisateurs de test distincts avec images préchargées, mesurer leur
temps d’ouverture (objectif inférieur à une minute), exécuter un TP représentatif et
déclencher leurs remises simultanément. Ne pas extrapoler la capacité depuis un seul utilisateur.

Les tests ne démontrent pas une protection absolue contre un code conçu pour tromper
le noyau Python lui-même : les tests fonctionnels sont une composante de l’évaluation,
complétée par les explications, la relecture et les conditions de surveillance habituelles.
