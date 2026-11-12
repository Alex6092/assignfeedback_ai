# Bilan de livraison — 11 novembre 2026

Version alpha destinée à une installation de test. Aucun déploiement sur le Moodle
de l’établissement ni sur Proxmox n’a été effectué pendant cette implémentation.

## Livrables

| Élément | Contenu |
|---|---|
| `local_notebook` 0.1.0 | Versions privées, connexion éphémère, autorisations, captures, génération IA, supervision, confidentialité |
| `assignsubmission_notebook` 0.1.0 | Type de remise, copies figées, consultation et téléchargement, sauvegarde/restauration |
| `assignfeedback_ai` 2.1.0 | Circuit tests puis IA, pondération, autograde existant, protection des notes manuelles et des anciennes corrections |
| `local_aibridge` 0.4.0 | Import avec simulation, validation asynchrone et lecture de rapport, description de l’activité |
| Services 0.1.0 | JupyterHub/DockerSpawner, extension JupyterLab, API privée, file persistante et correcteur jetable |
| Outils et gabarits | Serveur MCP actualisé, manifeste exemple, notebook nbgrader et documentation du skill versionné |

Les archives se créent depuis la racine du dépôt avec
`python tools/build-notebook-release.py`. Elles sont écrites dans `dist/notebooks-0.1.0`,
avec `manifest.json` et `SHA256SUMS.txt`. Les plugins Moodle ont chacun leur archive ;
l’archive de plateforme conserve les dossiers `services`, `tools` et `skills`.

## Vérifications exécutées sur le poste de développement

| Contrôle | Résultat |
|---|---|
| Tests Python, CPython 3.12 | 25 réussis ; 2 tests Docker non exécutés |
| Tests du serveur MCP, y compris les devoirs classiques | 48 réussis |
| Compilation de l’extension JupyterLab | Réussie avec le noyau de construction JupyterLab 4.6.6 |
| Syntaxe PHP des quatre composants Moodle concernés | 96 fichiers vérifiés |
| Contrat PHP de calcul de note | 8 cas réussis : pondération, décimales, valeurs non finies et hors bornes |
| Gabarit pédagogique | Structure valide, 4 cellules, 10 points de tests |
| Diff des fichiers déjà suivis | Aucune erreur d’espacement détectée |

Les tests Python vérifient notamment le retrait des solutions et tests cachés, la
reconstruction depuis le maître, le rejet des chemins dangereux, l’idempotence de la
file, la reprise après interruption, le rejet d’un ancien worker, la séparation des
emplacements, le choix d’une image approuvée, les traits JupyterHub et les restrictions
du proxy. Les tests MCP vérifient aussi qu’un notebook invalide bloque le déploiement
avant toute écriture et qu’une simulation ne lance pas de validation distante.

Le PHP disponible sur ce poste est 7.2 : les vérifications ci-dessus couvrent la syntaxe
et le calcul isolé, pas l’exécution dans Moodle 5.2. Les tests PHPUnit Moodle fournis
dans `local/notebook/tests/grading_test.php` n’ont pas été exécutés faute d’installation
Moodle de test. Un avertissement de dépréciation Starlette/httpx est présent dans les
tests Python ; aucun test n’échoue.

## Réception à effectuer sur les VM de test

| Critère | Vérification restante |
|---|---|
| Installation Moodle | Mise à jour des quatre plugins, cron, droits enseignant/élève, tests PHPUnit |
| Image d’exécution | Construction Linux, tests Docker fournis, Python/NumPy/pandas/Matplotlib, première ouverture |
| Remise | Enregistrement, capture, confirmation native, double clic, nouvelle tentative, consultation Hub arrêté |
| Notation | Copie correcte, réponse erronée, boucle infinie, cellules supprimées/réordonnées, sorties falsifiées |
| Autograde et EFE | Autograde activé/désactivé, EFE désactivé/immédiat/différé, note manuelle concurrente |
| Isolement | Aucune lecture entre élèves, aucun corrigé distribué, réseau/DNS fermé, proxy limité aux API approuvées |
| Fermeture | Retrait d’autorisation avec navigateur déjà ouvert : arrêt effectif sous une minute, panne Hub comprise |
| Persistance | Redémarrage des services, quota XFS de 2 Go, arrêt à 30 minutes d’inactivité |
| Cycle de vie | Sauvegarde/restauration Moodle et volumes, export/suppression utilisateur, réinitialisation et suppression de cours |
| Charge | 35 comptes distincts simultanés, objectif d’ouverture < 60 secondes avec images préchargées, quatre corrections maximum |

Le tableau Moodle et l’API fournissent l’état des traitements, les erreurs et des
compteurs. L’alerte d’exploitation sur les ressources et les sauvegardes reste à raccorder
au dispositif de supervision de l’établissement. Les sauvegardes doivent inclure les
volumes de travail et les états des services, en complément des sauvegardes Moodle.

Le gabarit fourni est un test minimal du circuit ; il ne remplace pas le TP complet
du pilote ni sa relecture pédagogique. Node.js, C++, la collaboration en direct, les GPU
et l’espace personnel indépendant d’un devoir ne sont pas livrés dans cette version.

Consulter [le guide d’installation](README.md) pour préparer les VM, les quotas, le
réseau, les secrets et l’ordre de mise à jour. Ne figer les images de production qu’après
la réception réelle : aucun résultat local ne démontre à lui seul la capacité de 35 élèves.
