# Correction IA pour Moodle

Écosystème de plugins Moodle pour la **correction automatique par IA** (LLM local
type LM Studio / API compatible OpenAI). Les devoirs et les questions de composition
sont notés et commentés automatiquement par un modèle de langage, avec un feedback
structuré (note, niveau de maîtrise, points forts, points à améliorer, évaluation par
compétences).

> **Statut :** alpha. Développé et testé pour un usage en BTS Informatique / CIEL.

---

## Sommaire

- [Architecture](#architecture)
- [Plugins](#plugins)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Fonctionnement](#fonctionnement)
- [Support de la vision (images)](#support-de-la-vision-images)
- [Surcharges par devoir / par question](#surcharges-par-devoir--par-question)
- [Tuteur IA — recherche Web](#tuteur-ia--recherche-web)
  (dont [mode « Recherche de matériel »](#mode--recherche-de-matériel-))
- [MoodleSearch — moteur de recherche Web sans IA](#moodlesearch--moteur-de-recherche-web-sans-ia)
- [Heures de cours — activités accessibles seulement en classe](#heures-de-cours--activités-accessibles-seulement-en-classe)
- [Activités surveillées — ouvertes par l'enseignant, en classe](#activités-surveillées--ouvertes-par-lenseignant-en-classe)
- [Contrôle des tentatives — hors créneau et tentatives suspectes](#contrôle-des-tentatives--hors-créneau-et-tentatives-suspectes)
- [Structure du dépôt](#structure-du-dépôt)
- [Feuille de route](#feuille-de-route)

---

## Architecture

Le projet est volontairement découpé en **trois plugins**. Toute l'infrastructure
partagée (appel API, file d'attente, chiffrement de la clé, calcul de note) vit dans
une bibliothèque `local_aifeedback` ; les plugins « consommateurs » (feedback de
devoir, type de question) ne font qu'implémenter une interface et déléguer.

```
                ┌─────────────────────────────────────────────┐
                │            local_aifeedback                  │
                │  (bibliothèque partagée — aucune dépendance) │
                │                                              │
                │  • api::call()      appel OpenAI/JSON schema │
                │  • task\run_job     file d'attente + lock    │
                │  • secret           chiffrement clé API      │
                │  • math             arrondi note 0,25 sup.   │
                │  • job_handler      interface des handlers   │
                └───────────────┬──────────────┬───────────────┘
                                │              │
              implémente        │              │   implémente
        job_handler             │              │            job_handler
                ┌───────────────┴───┐    ┌─────┴──────────────────┐
                │ assignfeedback_ai │    │     qtype_aiessay       │
                │ (feedback devoir) │    │ (question composition)  │
                └───────────────────┘    └─────────────────────────┘
```

**Principes clés :**

- **Un seul appel LLM à la fois sur tout le site.** Toutes les corrections
  (devoirs *et* questions) passent par la **même file d'attente** (`local_aifeedback\task\run_job`),
  protégée par un verrou global (`\core\lock`). Quand un tick tient le verrou, il
  *draine* la file tant qu'il reste du travail, dans la limite d'un budget de temps.
- **Sortie LLM contrainte par JSON Schema strict** : le modèle est forcé de renvoyer
  une structure exploitable (niveau, score, points forts, etc.).
- **Notation** : le LLM renvoie un score 0–100, converti en note sur le barème de
  l'item via `note = arrondi_au_quart_supérieur(score / 100 × note_max)`.
- **Découplage appel LLM / application de note** : le résultat du LLM est persisté
  *avant* d'appliquer la note. Si l'application de note échoue, seule celle-ci est
  rejouée — le modèle n'est jamais rappelé inutilement.

### Niveaux de maîtrise

Le feedback s'articule autour de 4 niveaux :

| Niveau | Plage de score indicative |
|---|---|
| Maîtrise insuffisante | 0–24 |
| Maîtrise fragile | 25–49 |
| Maîtrise satisfaisante | 50–79 |
| Très bonne maîtrise | 80–100 |

---

## Plugins

| Plugin | Type | Rôle |
|---|---|---|
| **`local_aifeedback`** | `local` | Bibliothèque partagée : appel API, file d'attente, chiffrement, réglages globaux. **À installer en premier.** |
| **`assignfeedback_ai`** | `assignfeedback` | Génère automatiquement un feedback IA à la remise d'un devoir (`mod_assign`). |
| **`qtype_aiessay`** | `qtype` | Type de question « composition » corrigé automatiquement par le LLM dans un quiz (`mod_quiz`). |

---

## Prérequis

- **Moodle 4.2+** (`requires = 2023042400`). Testé jusqu'à **Moodle 5.2**.
- **Le cron Moodle doit tourner** (les corrections sont des tâches ad-hoc en arrière-plan).
- Un **serveur d'inférence compatible OpenAI** exposant `/v1/chat/completions`, par
  exemple [LM Studio](https://lmstudio.ai/), avec un modèle supportant le **JSON
  Schema / structured output** (et la **vision** si vous activez l'envoi d'images).
- **Optionnel (vision sur PDF)** : [`poppler-utils`](https://poppler.freedesktop.org/)
  côté serveur (`pdftotext`, `pdftoppm`, `pdfimages`) pour extraire texte et images des PDF.

---

## Installation

L'ordre est important : `local_aifeedback` doit être présent avant les deux autres
(dépendance déclarée).

1. Copiez chaque plugin à son emplacement Moodle :

   | Source dans ce dépôt | Destination Moodle |
   |---|---|
   | `local/aifeedback/` | `local/aifeedback/` |
   | `mod/assign/feedback/ai/` | `mod/assign/feedback/ai/` |
   | `question/type/aiessay/` | `question/type/aiessay/` |

2. Connectez-vous en administrateur et suivez **Administration du site → Notifications**
   pour déclencher l'installation/mise à jour de la base de données.

3. Configurez la bibliothèque (voir ci-dessous).

> **Déploiement par ZIP :** Moodle n'accepte qu'un plugin par archive. Installez
> `local_aifeedback` en premier, puis les deux autres. (Les `*.zip` de build sont
> ignorés par `.gitignore`.)

---

## Configuration

Tous les réglages globaux sont centralisés dans
**Administration du site → Plugins → Plugins locaux → Correction IA**
(`local_aifeedback`) :

### API
- **`apiurl`** — URL du endpoint chat completions (défaut `http://localhost:1234/v1/chat/completions`).
- **`model`** — nom du modèle (défaut `qwen3.5-9b-instruct`).
- **`apikey`** — clé API, **stockée chiffrée** (préfixe `__enc1__:`, via `\core\encryption`).
  Laisser vide si le serveur n'exige pas d'authentification.
- **`defaultsystemprompt`** — prompt système par défaut (un prompt pédagogique est
  fourni si laissé vide).

### Vision
- **`vision_enabled`** — autoriser l'envoi d'images au modèle.
- **`maximagespersubmission`** — nombre max d'images par remise (défaut 5).
- **`imagemindimension`** — dimension minimale (px) pour retenir une image (filtre les
  pictos/icônes ; défaut 200).

### Binaires externes (poppler-utils)
- **`pdftotextpath`**, **`pdftoppmpath`**, **`pdfimagespath`** — chemins des exécutables.
  Laisser vide pour autodétection (`command -v`).

### Quiz / questions
- **`max_attempts_to_grade`** — nombre maximal de tentatives notées par l'IA pour une
  même question/utilisateur (défaut 3), afin de ne pas solliciter le LLM à l'infini sur
  les quiz à tentatives illimitées.

---

## Fonctionnement

### Devoir (`assignfeedback_ai`)

1. L'étudiant remet son devoir → l'événement `assessable_submitted` est capté.
2. Un job est mis en file ; le cron l'exécute (un seul appel LLM à la fois).
3. Le texte en ligne, les PDF et (si activé) les images sont extraits et envoyés au LLM.
4. Le feedback structuré est enregistré et **publié automatiquement** à l'étudiant ;
   la note est appliquée selon le barème/échelle du devoir.

#### Note appliquée automatiquement, ou saisie par l'enseignant

Chaque devoir porte la case **« Appliquer automatiquement la note proposée par
l'IA »** (section Feedback IA), **cochée par défaut** : c'est le fonctionnement décrit
ci-dessus.

Décochée, l'IA évalue toujours la remise et rédige son feedback, mais **la note n'est
pas reportée** : c'est l'enseignant qui la saisit.

- **Enseignant** : il voit le feedback complet, niveau et score compris, et un encart
  « Note proposée par l'IA : 14,4 / 20 » (ou l'échelon du barème) pour s'en servir. La
  grille de notation marque ces copies d'un badge « note à saisir ».
- **Élève** : il voit le feedback formatif (points forts, points à améliorer,
  commentaire détaillé, commentaire par compétence), mais **ni le niveau global, ni le
  score, ni les niveaux par compétence** proposés par l'IA. C'est la note de
  l'enseignant qui fait foi, et un niveau affiché par l'IA pourrait la contredire.

Le **report EFE** ne dépend pas de cette case : il suit l'attribution de la note,
qu'elle vienne de l'IA ou de l'enseignant.

L'option suit le devoir à la duplication, la sauvegarde et la restauration. Les devoirs
existants, et ceux créés par les Missions IA, restent en mode automatique.

### Question de composition (`qtype_aiessay`)

1. L'enseignant crée une question « Composition (correction IA) » dans la banque de
   questions et l'ajoute à un quiz. Elle force le comportement **`manualgraded`**
   (la note arrive *a posteriori*, comme une notation manuelle).
2. À la soumission de la tentative (`\mod_quiz\event\attempt_submitted`), une ligne
   `pending` est créée dans `qtype_aiessay_grading` et un job est mis en file.
3. Le cron exécute le job : appel LLM → calcul de la note → `manual_grade()` sur la
   tentative → recalcul de la note du quiz et **propagation au carnet de notes**
   (via `\mod_quiz\grade_calculator`).
4. À la relecture de la tentative, l'étudiant voit une **carte de feedback** complète
   (score, niveau, points forts/faibles, tableau par compétences).

> Tant que le job n'est pas traité, l'étudiant voit un message « feedback en cours de
> génération ». En cas d'échec après plusieurs tentatives, la copie reste en
> « à noter manuellement » côté enseignant.

---

## Support de la vision (images)

Lorsque `vision_enabled` est actif et que le modèle est multimodal, les images sont
transmises au LLM en blocs `image_url` (base64) aux côtés du texte :

- **Images jointes** directement à la remise.
- **Images extraites des PDF** et des **archives ZIP**.
- **Images intégrées** dans les PDF.

Des garde-fous limitent le coût : nombre maximal d'images par remise, dimension
minimale pour écarter les pictogrammes.

---

## Surcharges par devoir / par question

Chaque devoir et chaque question peut **surcharger** la configuration globale via des
cases à cocher dédiées :

- URL de l'API
- Nom du modèle
- Clé API (stockée chiffrée par item)
- Activation de la vision

Pratique pour router certaines activités vers un modèle plus puissant ou un endpoint
différent.

La clé API propre à une **question** ne la suit pas : la duplication d'un test, la
sauvegarde/restauration, l'import ou la copie de cours et l'export XML reprennent tous
ses autres réglages (prompt, corrigé, compétences, URL, modèle…), mais pas la clé,
chiffrée avec la clé du site. La question recréée utilise la clé du site ; ressaisissez
sa clé propre si besoin.

---

## Tuteur IA — consigne transmise ou non

La case **« Transmettre la consigne au tuteur »** de la section Tuteur IA d'une activité
ajoute la description et les « Instructions de l'activité » au contexte du tuteur. Elle est
**décochée par défaut** : avec la consigne, le modèle peut juger la réponse de l'élève et
lui confirmer qu'elle est juste. Sans elle, le tuteur s'appuie sur le titre, les
compétences, le brief pédagogique et ce que l'élève lui explique.

- Le défaut vient du réglage du site *Transmettre la consigne au tuteur, par défaut*
  (décoché), appliqué dans le formulaire de l'activité.
- **Missions IA** : la case est **cochée** sur les devoirs qu'il crée. Leur consigne est
  la demande du client ; sans elle, le tuteur ne sait pas ce que l'équipe doit livrer. Un
  choix fait ensuite sur le devoir n'est plus écrasé par Missions.
- La mise à jour 0.5.8 a décoché la case sur les activités existantes, sauf les devoirs
  créés par Missions IA : la recocher là où la consigne est utile au tuteur et ne contient
  aucun élément de réponse.

---

## Tuteur IA — duplication, sauvegarde, réinitialisation

La section « Tuteur IA » d'un devoir suit l'activité :

- **Duplication**, **importation** (« Réutilisation de cours »), **copie de cours**,
  **sauvegarde et restauration** : tous les réglages de la section sont recopiés
  (`backup/moodle2/*_local_aichat_plugin.class.php`). Cela inclut l'activation, l'énoncé,
  le brief, les consignes, le mode de recherche et les sites de référence. Le brief est
  repris s'il est prêt et correspond toujours au corrigé restauré ; sinon, il est
  régénéré en file.
- **Réinitialisation du cours** : la configuration et le brief sont conservés. Seules les
  conversations sont effacées, et seulement si l'enseignant efface les remises des
  devoirs.
- Les **conversations** des élèves ne sont jamais sauvegardées : ce sont des échanges
  personnels, liés à une cohorte.

À la **création** d'un devoir, le brief part automatiquement, une fois le corrigé de la
correction IA enregistré (observateur `course_module_created`, qui passe après celui de
`assignfeedback_ai`).

---

## Tuteur IA — affichage du chat

- **Fenêtre redimensionnable** sur ordinateur et tablette : une poignée dans le coin
  haut-gauche (la fenêtre est ancrée en bas à droite). La taille est bornée par l'écran,
  retenue dans le navigateur de l'élève et commune à toutes les activités. Au clavier :
  flèches pour ajuster, **Origine** pour revenir à la taille par défaut (double-clic à la
  souris). En dessous de 600 px de large, la fenêtre occupe l'écran et la poignée disparaît.
- **Markdown réparé avant affichage** (`content::tidy_markdown`, côté serveur, donc pour le
  chat comme pour la transcription enseignant). Les petits modèles produisent deux défauts
  que le widget ne sait pas montrer :
  - **formules LaTeX** (`$\pm 500\text{ mV}$`) → texte lisible (`±500 mV`). Les délimiteurs
    `$…$`, `$$…$$`, `\(…\)`, `\[…\]`, `\text{}`, `\frac{}{}` et les symboles courants sont
    convertis. Un prix (`50 $`), une variable de shell entre accents graves et les blocs de
    code ne sont jamais touchés ;
  - **puces enchaînées sur une même ligne** (`plages : * Tension… * Courant…`) → une puce par
    ligne, précédée d'une ligne vide. Il faut au moins deux puces, entourées d'espaces et
    précédées d'autre chose qu'un chiffre : `2 * 3 * 4` et `**gras**` restent intacts.

  Le texte brut enregistré n'est pas modifié, et le prompt demande en plus au modèle de ne
  pas produire ces deux formes (`tutor::format_rules()`, ajouté même quand l'administrateur a
  remplacé le prompt de base).

---

## Tuteur IA — recherche Web

Le tuteur (`local_aichat`) peut chercher sur Internet quand une information est
récente ou externe (« quelle est la dernière version de Python ? »). **Le modèle
décide** d'appeler l'outil `web_search` (tool calling de l'API compatible OpenAI) ;
**le PHP exécute** la recherche. Le modèle n'a jamais accès à Internet, ni à l'URL,
ni à la clé : il ne fournit qu'un texte de requête.

**Chaque élève cherche avec SES propres clés d'API**, dans l'ordre :
1. **Tavily** : 1 000 recherches gratuites par mois, **sans carte bancaire** ;
2. **Brave Search** en secours, si l'élève a aussi une clé Brave.

Sans clé, le tuteur ne va pas sur Internet pour cet élève (ni recherche, ni lecture de
pages). Le lycée ne paie rien. Les clés du plugin ne servent qu'aux tests de la page de
diagnostic.

### Architecture

```
Préférences > « Tuteur IA : mes clés de recherche » (mykeys.php)
   clés Tavily / Brave chiffrées dans les préférences, jamais réaffichées, « Tester »

stream.php (SSE, ticket du pool tenu pendant toute la réponse)
  └─ generator::run()
       tour 1 : api::stream(messages, tools=[web_search]) ──▶ LM Studio / Gemma
          ├─ delta.content     → SSE « delta » vers l'élève (inchangé)
          └─ delta.tool_calls  → accumulés (index, id, nom, arguments en morceaux)
       appel reçu → websearch\tool::execute()
          limite par réponse → requête valide → cache commun → quota élève
          → pour chaque moteur de l'élève (Tavily, puis Brave) :
              moteur en panne ? clé suspendue ? plafond de la clé (réservation sous verrou)
              → appel ; échec du moteur ou de la clé → moteur suivant
          → résultats compacts, ou WEB_SEARCH_UNAVAILABLE + reason
       SSE « search » (statut du widget) ; messages += assistant(tool_calls) + tool(résultat)
       tour 2 : api::stream(...) — sans tools si la limite est atteinte — réponse streamée
```

Une activité où la recherche n'est pas autorisée, ou un élève sans clé, envoie
**exactement la même requête qu'avant** (aucun champ `tools`, aucun bloc de prompt).
Dans le second cas, le widget indique à l'élève où ajouter sa clé.

#### Appel imposé quand l'élève le demande

C'est le modèle qui décide d'appeler l'outil, et les modèles locaux annoncent volontiers
une recherche sans la faire (« je lance la recherche… », puis une réponse de mémoire).
Deux garde-fous :

- **Consignes** : il est interdit d'annoncer une recherche (l'interface le signale déjà),
  de dire qu'on a cherché sans résultat reçu, de renvoyer l'élève vers un moteur de
  recherche, et — en mode matériel — de citer une référence de produit qui ne vient pas
  d'un résultat ou d'une page lue.
- **Forçage** : quand le message de l'élève demande explicitement une recherche (« lance
  une recherche », « peux-tu chercher… », « trouve la datasheet ») ou contient une adresse,
  `toolbox::requested_tool()` le détecte et `generator::run()` **impose** l'appel au premier
  tour (`tool_choice`). Le modèle ne peut alors plus se contenter d'en parler. Si le serveur
  ne connaît pas `tool_choice`, un nouvel essai part sans lui : l'élève a toujours sa
  réponse. Les tours suivants restent libres, sinon le modèle ne pourrait pas répondre à
  partir des résultats.

En mode matériel, le forçage ne s'applique pas au tout premier message de la conversation :
le tuteur garde ce tour pour demander à l'élève les critères tirés du cahier des charges.
Une adresse collée, elle, est lue tout de suite.

### Configuration

1. **Moodle**, *Administration > Plugins > Plugins locaux > Tuteur IA*, rubrique
   « Recherche Web » :

   | Réglage | Défaut | Rôle |
   |---|---|---|
   | Activer la recherche Web | non | interrupteur du site |
   | Clé Tavily de test / clé Brave de test | — | **page de diagnostic uniquement**, chiffrées, jamais réaffichées |
   | Plafond par clé Tavily | 1 000 | sur 31 jours glissants ; 0 = pas de plafond local (Tavily sans carte ne facture jamais) |
   | Plafond par clé Brave | 900 | sur 31 jours glissants ; 0 = Brave jamais utilisé (il débite la carte au-delà des crédits) |
   | Recherches par élève | 10 | par fenêtre du quota élève (4 h), 0 = pas de limite |
   | Recherches par réponse | 2 | protection contre les boucles (1 à 5) |
   | Résultats par recherche | 5 | 1 à 10 |
   | Délai d'une recherche | 6 s | 2 à 20 |
   | Durée du cache des recherches | 7 jours | 0 à 90, 0 = pas de cache |

2. **Page « Tuteur IA : recherche Web »** (lien dans la rubrique) :
   - lancer d'abord **« Tester l'appel d'outil »** sur chaque serveur du tuteur (aucun
     appel aux moteurs) ;
   - puis **« Tester Tavily »** et **« Tester Brave »**, avec les clés de test ;
   - la page indique aussi le nombre d'élèves ayant une clé et les recherches par
     moteur.
3. **Élèves**, dans *Préférences > Compte utilisateur > « Tuteur IA : mes clés de
   recherche »* :
   - créer un compte gratuit sur <https://app.tavily.com> (sans carte), coller la clé
     (`tvly-…`), puis « Tester ma clé » : les crédits utilisés s'affichent, sans
     consommer de recherche ;
   - clé Brave facultative : Brave exige une carte et facture au-delà de 5 $ de
     crédits par mois, mais le tuteur s'arrête au plafond par clé.
4. **Par activité**, dans la section « Tuteur IA » des réglages de l'activité, le menu
   « Recherches du tuteur » propose trois choix :
   - **Aucune** (défaut) ;
   - **Recherche Web ponctuelle** ;
   - **Recherche de matériel** (voir plus bas), avec des **sites de référence**
     facultatifs.

### Quota

- **Plafond par clé, fenêtre glissante de 31 jours.** Un cycle de facturation dure au
  plus 31 jours, donc aucun cycle ne peut dépasser le plafond d'une clé, quelle que
  soit sa date de début. C'est vital pour Brave, qui facture la carte de l'élève
  au-delà de ses crédits au lieu de refuser ; ses en-têtes `X-RateLimit-*` décrivent le
  plan, pas les crédits. Tavily (sans carte) refuse au lieu de facturer (HTTP 432) :
  le tuteur passe alors à Brave.
- **Réservation avant l'appel, sous verrou Moodle.** Le registre
  `local_aichat_wsledger` contient le moteur, une **empreinte** de la clé (`sha1`,
  jamais la clé ni l'élève), l'activité et la date. Il n'est jamais effacé par une
  demande RGPD ni par une réinitialisation de cours. Deux réponses simultanées avec la
  même clé à 899/900 sont sérialisées : la seconde est refusée. La réservation est
  remboursée seulement si le moteur répond une erreur HTTP (non décomptée) ; un délai
  dépassé après connexion reste compté.
- **Suspensions :**
  - **clé refusée** (401/403) : la clé de l'élève est suspendue jusqu'à ce qu'il la
    modifie ou la teste avec succès ;
  - **crédits épuisés** : Tavily 24 h, Brave jusqu'à la réinitialisation annoncée ;
  - **panne d'un moteur** (5xx, réseau, délai) : le moteur est suspendu 60 s pour tout
    le monde.
- **Par élève** : somme des recherches de ses réponses sur la fenêtre du quota élève.
- **Par réponse** : au-delà de la limite, le modèle reçoit `tool_call_limit`, puis le
  dernier tour est envoyé sans outil : la boucle se termine forcément.
- **Cache des recherches** (cache Moodle `searchcache`, 7 jours par défaut) : une requête
  déjà faite par n'importe quel élève, **avec n'importe quel moteur**, est resservie sans
  appel. La casse et les espaces sont ignorés, les opérateurs `site:` et `filetype:`
  conservés.
  - Le cache est consulté **avant** le budget. Une recherche servie par le cache est
    gratuite et instantanée : elle ne consomme ni la clé de l'élève ni son quota, et elle
    reste disponible si les moteurs sont en panne.
  - Elle compte toujours dans la limite par réponse, et le modèle voit la date de mise en
    cache.
  - Seules les recherches réussies sont gardées. La clé est un hachage ; on ne stocke que
    les résultats publics, jamais de donnée d'élève.
  - Chaque recherche servie par le cache est inscrite au registre avec le statut `cached`,
    hors des compteurs de budget. La page de diagnostic affiche les recherches économisées.
  - Une purge des caches Moodle, qui a lieu à chaque mise à jour, vide ce cache.

### Mode dégradé

La recherche est optionnelle : **aucune situation ne transforme une indisponibilité
en erreur pour l'élève**.

| Situation | Outil proposé ? | Moteur appelé ? | Le modèle reçoit |
|---|---|---|---|
| Désactivée (site ou activité), ou élève sans clé | non | non | rien |
| Clés présentes mais toutes suspendues, en panne ou à leur plafond ; quota de l'élève atteint | non | non | une note « recherche indisponible » dans le prompt |
| Tavily refuse (crédits, clé) ou est en panne, l'élève a une clé Brave | oui | Tavily, puis Brave | les résultats de Brave (secours invisible) |
| Plafond atteint pendant la génération | oui | non | `quota_exhausted` / `user_quota_exhausted` |
| Limite par réponse atteinte | puis non | non | `tool_call_limit` |
| Requête vide ou invalide, outil inconnu | oui | non | `invalid_query` / `unknown_tool` |
| Verrou du budget indisponible (3 s) | oui | non | `budget_busy` |
| Tous les moteurs de l'élève échouent | oui | oui | `rate_limited`, `provider_error`, `timeout`, `auth_error`… |

Pendant une suspension, l'outil n'est pas proposé et le tuteur répond avec ses
connaissances. L'administrateur peut remettre un moteur en service depuis la page de
diagnostic ; l'élève débloque sa clé en la modifiant ou en la testant.

### Sécurité et vie privée

- Paramètres fixés par le PHP ; le modèle ne fournit que `query` (200 caractères max,
  JSON borné, autres clés ignorées) :
  - **Tavily** : `safe_search: true`, `search_depth: basic` (1 crédit), nombre de
    résultats ; les opérateurs `site:` deviennent `include_domains` ;
  - **Brave** : `safesearch=strict`, `result_filter=web`, `extra_snippets=true` ; si
    Brave refuse `extra_snippets`, la requête est relancée sans lui (une erreur n'est
    pas facturée).
- **Clés des élèves** :
  - chiffrées dans les préférences (`\core\encryption`) ;
  - jamais réaffichées (4 derniers caractères seulement), jamais envoyées au navigateur
    ni au modèle, jamais écrites dans un journal ;
  - l'export RGPD indique « clé configurée (…a1b2) ».

  Les recherches partent sous le compte de l'élève chez le moteur, ce que la page des
  clés lui explique.
- Nom, prénom, identifiant, e-mail et téléphone de l'élève sont retirés de la requête
  avant l'envoi au moteur.
- Ce que le modèle reçoit : **uniquement la réponse du moteur**, aucune page n'est
  téléchargée. Par résultat : titre (≤ 120), URL http/https (≤ 300), date, extrait
  principal (≤ 400) et jusqu'à 5 extraits supplémentaires (≤ 300 chacun). Le total est
  borné à 6 000 caractères par recherche et réparti équitablement entre les résultats.
  L'ensemble est présenté comme des **données non fiables**, jamais comme des
  instructions.
- **Sources ajoutées par le PHP** en fin de réponse : les pages transmises au modèle
  (8 liens au plus), avec le nom du ou des moteurs utilisés (ce qui vaut mention de
  Brave Search quand il a servi). Le modèle a la
  consigne de ne pas écrire lui-même de liste de sources ni d'URL, et de ne donner
  aucun chiffre ou caractéristique absent des résultats.
- Le prompt interdit de chercher la solution de l'activité ; les règles d'intégrité du
  tuteur restent en dernier et inchangées.
- Les requêtes figurent dans les transcriptions enseignant et dans l'export RGPD.

### Fichiers

- **Créés** (`local/aichat`) :
  - `classes/generator.php` (boucle de tours) ;
  - `classes/websearch/` : `provider` (interface), `tavily_provider`, `brave_provider`,
    `userkeys` (clés des élèves), `result`, `budget`, `tool`, `manager`, `searchcache`,
    `diagnostic` ;
  - `mykeys.php` et `classes/form/mykeys_form.php` (page « mes clés de recherche ») ;
  - `websearch.php` (page d'état et de tests).
- **Modifiés** :
  - `local/aifeedback/classes/api.php` : `tools` / `tool_choice` transmis, `tool_calls`
    reconstitués dans le flux ;
  - `local/aichat` : `stream.php`, `tutor.php` (bloc de prompt, date du jour),
    `quota.php`, `conversation.php`, `lib.php` et `activity.php` (menu par activité, lien
    « mes clés » dans les Préférences),
    `manage.php`, `js/chat.js` (statut de recherche), `settings.php`,
    `privacy/provider.php`, `task/purge.php`, `db/*`, chaînes fr/en.
- **Aucune nouvelle dépendance** : HTTP par la classe `curl` de Moodle (proxy du site,
  hôtes bloqués respectés), verrous et chiffrement de Moodle.

### Tests

Hors Moodle, sur le vrai code (base SQLite, réseau simulé) :
- réponse sans outil, identique à avant ;
- recherche puis second tour streamé ;
- appel d'outil fragmenté, sans index ni id, arguments en objet, appels simultanés ;
- plafond à 0 ou atteint ;
- 899/900 avec **deux processus PHP réels** : 900 exactement avec le verrou, 901 sans
  (contre-épreuve) ;
- erreurs 500, 401, 422, 429 et délai dépassé ;
- clé absente ;
- boucle d'appels ;
- nettoyage des requêtes ;
- clé jamais exposée ;
- cache des recherches (requête identique d'un autre élève, même avec un autre moteur,
  moteurs en panne, expiration, échec jamais gardé) ;
- clés personnelles : stockage chiffré, ordre Tavily → Brave, secours dans le même appel
  (Tavily 432 → Brave), clé refusée ou crédits épuisés (clé suspendue), panne (moteur
  suspendu), élève sans clé (aucune recherche), clés de test jamais utilisées pour un
  élève, export RGPD sans la clé ;
- **deux processus PHP réels** sur la même clé à 899/900 → une seule réservation ; clés
  différentes → plafonds indépendants.

Sur le site :
1. page de diagnostic : appel d'outil sur chaque serveur, « Tester Tavily » ;
2. avec un compte élève : ajouter une clé Tavily dans ses Préférences, puis « Tester ma
   clé » ;
3. « dernière version de Python ? » → statut de recherche, sources citées (Tavily) ;
4. « explique une boucle for » → aucune recherche ;
5. compte élève sans clé → le widget propose d'ajouter une clé, le tuteur ne cherche pas ;
6. clé erronée → réponse sans le Web, clé signalée suspendue sur la page des clés.

### Limites connues

- Si LM Studio ne reconnaît pas l'appel d'outil d'un modèle, il peut apparaître en
  texte brut : le test de la page de diagnostic le détecte. N'activez pas la
  recherche tant qu'un serveur du tuteur échoue à ce test. Cette version n'a pas
  d'analyseur de secours.
- Chaque recherche ajoute un tour de génération : réponse plus lente, quota de tokens
  de l'élève davantage consommé.
- Un texte écrit avant l'appel (« Je vérifie… ») reste dans la réponse.
- En mode ponctuel : extraits seulement (principal + supplémentaires), pas de lecture de
  pages. La lecture de pages n'existe que dans le mode « Recherche de matériel ».
- Le retrait de l'identité peut effacer un mot identique au nom de l'élève (« Martin »).
- Clés personnelles :
  - les conditions de Tavily et de Brave imposent en général d'avoir 18 ans ;
  - le plafond par clé ne compte que les recherches faites **via Moodle** ;
  - Tavily ne documente pas les opérateurs de recherche : `site:` est converti,
    `filetype:` reste dans le texte.

### Mode « Recherche de matériel »

Pour les activités où l'élève choisit un matériel d'après un cahier des charges :
modules d'E/S Ethernet (HW group Poseidon2, Teracom TCW241, ICP DAS ET-7052…), Arduino,
Raspberry Pi, capteurs et actionneurs. **L'élève rédige lui-même l'étude comparative.**

Pourquoi pas un catalogue de distributeur :
- DigiKey ne référence qu'une partie de ce matériel (ICP DAS, mais ni Teracom ni HW group) ;
- RS et Gotronic n'ont pas d'API.

Les caractéristiques fines se trouvent en revanche sur les pages des fabricants et dans
leurs datasheets. Le tuteur dispose donc de deux outils :

| Outil | Coût | Rôle |
|---|---|---|
| `web_search` | clé de l'élève (Tavily, puis Brave) | trouver la page du fabricant ou la datasheet (`site:`, `filetype:pdf`) |
| `read_page` | gratuit | lire cette page ou ce PDF et relever les caractéristiques |

Comme pour la recherche ponctuelle, un élève **sans clé** n'a ni recherche ni lecture.

```
stream.php → toolbox (limite globale d'appels par réponse, 5 par défaut)
  ├─ websearch\tool  → Tavily, puis Brave (clés de l'élève, plafond par clé, cache)
  └─ reader\tool     → reader\fetcher (curl Moodle) → reader\extractor
                        HTML : texte visible + liens de documents (datasheet, manuel)
                        PDF  : pdftotext (content_extractor de local_aifeedback)
                        sélection des passages pertinents pour « focus » (≤ 6 000 car.)
```

**Contrat pédagogique** (bloc ajouté au prompt, règles d'intégrité inchangées et en
dernier) :
- le tuteur fait d'abord expliciter à l'élève les critères du cahier des charges
  (nombre d'E/S, répartition analogique/numérique, compteurs et fronts, tensions,
  protocole, alimentation, environnement, budget) ;
- il propose **au plus 3 références par réponse**, avec les seules caractéristiques
  lues sur les sources, « à vérifier sur la datasheet » ;
- il **ne remplit jamais le comparatif**, ne classe pas les produits et ne dit pas
  lequel convient ;
- il enseigne la démarche : où trouver la datasheet, quels mots-clés utiliser ;
- la référence fabricant permet de retrouver le produit chez RS ou Gotronic.

**Sécurité de la lecture :**
- seules sont lisibles les adresses trouvées par une recherche de la même réponse, les
  documents liés d'une page déjà lue et les adresses collées par l'élève ;
- http/https uniquement, sans identifiants dans l'URL ;
- le « security helper » de la classe `curl` de Moodle refuse les hôtes internes et les
  ports non autorisés, à chaque redirection ;
- le téléchargement est borné en taille (8 Mo, coupure même sans `Content-Length`) et en
  délai (10 s) ;
- le texte lu est présenté au modèle comme des données, jamais comme des instructions.

**Budget :**
- les recherches consomment la clé de l'élève (plafond par clé) et sont rattachées à
  l'activité dans le registre, pour les statistiques de la page de diagnostic ;
- les lectures sont gratuites, mais limitées par élève (30 par fenêtre de 4 h) ;
- le **cache des pages** (24 h) évite de retélécharger une datasheet que toute la
  classe lit ;
- le quota de tokens de l'élève compte le **prompt du dernier tour** (le contexte réel,
  résultats compris) **plus tout le texte généré** : LM Studio garde en cache le début
  commun des tours.

**Réglages** (rubrique « Recherche de matériel ») :

| Réglage | Défaut |
|---|---|
| Appels d'outils par réponse | 5 |
| Lectures par élève et par fenêtre | 30 |
| Taille maximale d'un document | 8 Mo |
| Délai de lecture | 10 s |
| Durée du cache des pages | 24 h |

**Diagnostic** :
- « Tester la lecture d'une page » (adresse + mots-clés) affiche exactement le texte que
  le modèle recevrait, et signale l'absence de `pdftotext` ;
- la page indique aussi les activités qui consomment le plus de recherches.

**Tests** hors Moodle, sur le vrai code et deux PDF réels (datasheet ICP DAS, manuel
Teracom de 100 000 caractères), 51 tests :
- parcours recherche → page → datasheet liée → réponse ;
- sélection :
  - « DI/Counter: 8 Channels » et « 32-bit counter » retenus sur la datasheet ICP DAS ;
  - section « Specifications » retenue sur le manuel Teracom ;
- sécurité : adresses refusées, délai, taille, type, page produite en JavaScript,
  `pdftotext` absent ;
- cache des pages ;
- limites : globale, lectures de l'élève, plafond d'activité ;
- prompt ;
- concurrence : 2 processus réels à 49/50 sur la même activité → une seule
  réservation.

**Limites :**
- pages produites en JavaScript et PDF scannés illisibles (pas de reconnaissance de
  caractères) ;
- la sélection par mots-clés peut manquer une ligne formulée autrement ;
- chaque lecture ajoute un tour et du contexte : vérifier la **longueur de contexte**
  chargée dans LM Studio (16k ou plus conseillés) et la VRAM disponible.

---

## MoodleSearch — moteur de recherche Web sans IA

`local_moodlesearch` est un moteur de recherche Web intégré à Moodle qui **n'affiche que
des résultats**, comme les moteurs d'avant : aucune réponse générée par une IA au-dessus
de la liste (Tavily est toujours appelé avec `include_answer=false`).

- **Clé Tavily personnelle** de la personne qui cherche : ce sont les clés du Tuteur IA
  (*Préférences > mes clés de recherche*). Sans clé, pas de recherche : la page invite à
  en ajouter une.
- **Page du site** (entrée « MoodleSearch » du menu principal), **activable ou désactivable
  par cohorte**. Autorisé par défaut ; une cohorte désactivée l'emporte. Un mode examen
  OPNsense ferme aussi MoodleSearch à la classe concernée.
- **Recherches et clics enregistrés**, consultables par les enseignants des cours de l'élève
  (lien « Recherches MoodleSearch » dans la navigation du cours) et, pour tout le site, par
  les gestionnaires. La page le rappelle en permanence.
- **Clic sur un résultat** : demande au bloc OPNsense l'ouverture du site **pour toute la
  classe**, par le mécanisme existant (ouverture automatique pour la durée réglée, ou
  demande en attente de l'enseignant), **sauf s'il est en liste noire**. Les résultats vers
  un site en liste noire sont affichés avec un badge « Bloqué par l'établissement ».

### Fonctionnement

```
index.php?q=&tab=web|news&period=any|day|week|month|year[&more=1]
  └─ searcher::search()
       accès (site activé, capacité, cohorte, mode examen, clé)
       → cache commun du Tuteur IA (même requête + mêmes options : 0 crédit)
       → limite par personne et par heure
       → plafond de la clé, réservation sous verrou (registre commun au tuteur)
       → Tavily /search : basic (1 crédit), include_answer=false, safe_search=true,
         topic, time_range, country, exclude_domains
       → journal local_moodlesearch_search (ok | cached | error | refused)
  └─ badge « Bloqué par l'établissement » : opnsense_bridge::blocked()

go.php?search=&rank=&sesskey=      (jamais d'URL en paramètre)
  └─ résultat relu dans le journal de l'utilisateur → clic enregistré
       ├─ pas de bloc OPNsense, ou personne sans classe → redirection vers le site
       └─ page d'attente → ajax_open.php → site_request::request_for_user()
            opened / already → redirection ; pending → lien ; blocked / exam → rien
            nomac → lien « Déclarer mon poste » (bloc OPNsense)
```

- **Cache** : celui du Tuteur IA (`searchcache`, durée *websearch_cachedays*). La clé de
  cache intègre les options (onglet, période, pays, domaines exclus, nombre de résultats).
  Une recherche déjà faite par n'importe qui ne reconsomme aucun crédit ; elle ne compte
  pas dans la limite horaire, mais reste inscrite au journal (statut `cached`). Actualités
  et recherches filtrées par date : 1 h au plus.
- **Consommation** : partagée avec le tuteur (même compte Tavily, même plafond par clé sur
  31 jours). Le pied de page indique le nombre de recherches faites avec la clé.
- **Plus de résultats** : Tavily n'a pas de pagination ; le lien relance une recherche de
  20 résultats (1 crédit, sauf si elle est en cache).

### Configuration

1. Déployer `local_aichat` 0.5.5 ou plus récent, puis `local_moodlesearch`.
2. Pour l'ouverture des sites : `block_opnsenseaccess` 0.1.1 ou plus récent (API
   `site_request`). Sans lui, MoodleSearch fonctionne, et un clic mène directement au site.
3. *Administration > Plugins > Plugins locaux > MoodleSearch* :

| Réglage | Défaut | Rôle |
|---|---|---|
| Activer MoodleSearch | non | entrée du menu principal |
| Accès par défaut | autorisé | décoché : réservé aux cohortes activées |
| Résultats par page | 10 | 5, 10, 15 ou 20 |
| Recherches par personne et par heure | 30 | celles servies par le cache ne comptent pas |
| Pays privilégié | `france` | nom anglais en minuscules ; vide : aucun |
| Domaines exclus des résultats | — | un par ligne, 150 au plus |
| Conservation des traces (jours) | 365 | purge chaque nuit ; 0 : sans limite |

4. *MoodleSearch : accès par cohorte* : Activer / Désactiver / Par défaut pour chaque
   cohorte, effet immédiat (pour couper une classe pendant une évaluation).

**Capacités** : `local/moodlesearch:use` (utilisateur authentifié), `viewreport`
(enseignants, contexte cours), `viewsitereport` et `manageaccess` (gestionnaires, système).

### Sécurité et vie privée

- Aucune réponse IA : `include_answer=false` et `safe_search=true` sont imposés par le
  client Tavily du tuteur, quelles que soient les options.
- La clé n'est jamais envoyée au navigateur ni écrite dans le journal.
- Les liens de résultats ne portent que le numéro de la recherche et le rang : l'URL est
  relue dans le journal de l'utilisateur (pas de redirection ouverte, pas d'ouverture
  forgée pour un autre site). Clic et ouverture exigent la `sesskey`.
- Un site en liste noire n'est **jamais** ouvert, et le clic n'ajoute pas de demande dans
  la liste de l'enseignant (il reste visible dans le rapport MoodleSearch).
- Titres et extraits des résultats sont échappés : ce sont des données non fiables.
- API de vie privée : recherches et clics (export, suppression), destinations externes
  Tavily (la requête) et OPNsense (le domaine).

### Limites connues

- L'ouverture vaut pour toute la classe, pour la durée réglée dans OPNsense : c'est le
  comportement des demandes de site existantes.
- Un élève qui n'a pas déclaré son poste (MAC) dans le bloc OPNsense ne peut pas faire
  ouvrir de site : un lien l'y invite.
- 20 résultats au plus par recherche (pas de pagination Tavily).
- La clé est partagée avec le Tuteur IA : 1 000 recherches par mois sur un compte gratuit.

---

## Heures de cours — activités accessibles seulement en classe

Certains tests et devoirs ne sont accessibles aux élèves **que pendant leurs heures de
cours**. L'emploi du temps se règle **par cours**, et un créneau vaut pour tout le cours ou
pour un groupe Moodle (demi-groupes de TP). Hors créneau, l'activité est grisée :
*« Pas disponible sauf : Pendant les heures de cours : lun. 08:00–10:00 — prochain
créneau : lundi 5 octobre à 08:00 »*.

Trois plugins, imposés par les points d'accroche de Moodle :

| Plugin | Emplacement | Rôle |
|---|---|---|
| `local_classhours` | `local/classhours/` | Emploi du temps, calcul des créneaux, page « Heures de cours », option EFE, sauvegarde/restauration |
| `availability_classhours` | `availability/condition/classhours/` | Condition « Pendant les heures de cours » de la restriction d'accès standard |
| `quizaccess_classhours` | `mod/quiz/accessrule/classhours/` | Test : compte à rebours jusqu'à la fin du créneau, envoi automatique |

### Fonctionnement

- **Créneaux** : l'élève a accès pendant les créneaux hebdomadaires de ses groupes (et de
  « tout le cours »), sauf pendant les **périodes fermées** (vacances, stage), plus les
  **ouvertures exceptionnelles** (rattrapage), qui valent même pendant une période fermée.
  Deux créneaux qui se touchent (8h–10h puis 10h–12h) n'en font qu'un.
- **Périodes de fermeture globales** : les vacances communes à tout l'établissement se
  saisissent une fois pour le site (*Administration > Plugins > Plugins locaux > Heures de
  cours : périodes de fermeture globales*). Chaque cours les applique avec la case
  « Utiliser les périodes de fermeture globales » (cochée par défaut, réglable pour le
  site) et peut ajouter ses propres périodes.
- **Heure de l'établissement** : les horaires sont ceux du fuseau du serveur, jamais celui
  du profil de l'élève. Les changements d'heure sont gérés.
- **Tolérance** (5 min par défaut) : l'accès reste ouvert quelques minutes après la fin du
  créneau, pour que l'envoi automatique d'un test et le dernier enregistrement d'un devoir
  passent.
- **Tests** : une tentative se termine à la fin du créneau dans lequel elle a commencé. Le
  compte à rebours s'affiche et le test est envoyé automatiquement (réglage conseillé du
  test : *Quand le temps est écoulé → envoi automatique*). Aucune tentative ne commence
  pendant la tolérance. Une tentative laissée ouverte est close par une tâche planifiée.
- **Enseignants** : ils passent outre la restriction (capacité standard
  `moodle/course:ignoreavailabilityrestrictions`).
- **Cours sans créneau** : une activité restreinte à la main reste fermée (en cas de doute,
  on ferme).

### Relire son travail hors créneau

Hors créneau, un élève qui a **déjà de quoi lire** peut rouvrir le devoir ou le test pour
consulter son feedback :
- **devoir** : une note, un commentaire de l'enseignant ou un feedback IA généré. Si le
  suivi d'évaluation est actif, seulement une fois la note publiée ;
- **test** : au moins une tentative terminée.

Il ne peut pas pour autant y travailler :
- un **verrou de remise** refuse, hors créneau, toute modification ou tout envoi de remise,
  sur le site comme par l'application mobile (services web). L'élève est renvoyé sur la
  page du devoir avec un message ;
- la règle d'accès du test empêche toujours de commencer une tentative ;
- le **Tuteur IA** reste fermé.

Un élève qui n'a rien remis ne voit toujours rien hors créneau : les consignes restent
réservées à la classe.

### Accès ponctuel sur demande

Pour qu'un élève puisse rendre un devoir fermé, avec l'accord de l'enseignant.

- **Élève** : devant un devoir ou un test fermé, le message de restriction propose
  « **Demander un accès exceptionnel** », avec un message facultatif. Le lien devient
  « Demande d'accès en attente » tant que l'enseignant n'a pas répondu.
- **Enseignant** : il est prévenu par notification. La page **« Demandes d'accès »** (lien
  dans la navigation du cours, avec le nombre de demandes en attente) permet de :
  - **accepter**, en choisissant la durée : 5 min (défaut), 15 min, 30 min, 1 h, 2 h,
    jusqu'à ce soir ;
  - **refuser**, avec un commentaire facultatif ;
  - **retirer** un accès en cours ;
  - **accorder** un accès sans demande.

  Dans un cours en groupes séparés, seuls les enseignants du groupe de l'élève sont
  prévenus. Si deux enseignants répondent en même temps, la première décision l'emporte.
- **Pendant la fenêtre** : l'activité est ouverte à l'élève comme pendant un créneau. Il
  peut remettre, le Tuteur IA est disponible, et un test commencé se termine à la fin de
  la fenêtre. Elle se referme d'elle-même.
- L'élève est prévenu de la décision : accès accordé jusqu'à telle heure, ou refus avec le
  commentaire.
- Capacités : `local/classhours:requestaccess` (élèves) et `local/classhours:grantaccess`
  (enseignants, enseignants non éditeurs, gestionnaires).

### Page « Heures de cours »

Lien dans la navigation du cours (capacité `local/classhours:manage`, enseignants
éditeurs) :

- **En ce moment** : ouvert ou fermé pour chaque groupe, et le prochain créneau ;
- **Emploi du temps de la semaine**, **ouvertures exceptionnelles** ;
- **Périodes fermées** : case « Utiliser les périodes de fermeture globales » et leur liste
  (lecture seule), puis les périodes propres au cours ;
- **Activités restreintes** : case « Restreindre » pour chaque test ou devoir (ou toute
  activité qui porte déjà la condition). On peut aussi ajouter la condition « Heures de
  cours » depuis la restriction d'accès de n'importe quelle activité ou section.

### Option EFE

Case **« Restreindre automatiquement les activités avec remontée EFE »** sur la page
Heures de cours (valeur par défaut réglable pour le site). Toute activité dont la remontée
EFE est active (`local_efenotes_activity` : activée, avec au moins une compétence de note
ou de ponctualité) est alors restreinte aux heures de cours.

- La condition posée est **marquée** (`{"type":"classhours","efe":1}`) : l'option ne retire
  jamais que ses propres conditions, une restriction posée à la main reste.
- **Exclure** : une case par activité EFE (devoir maison). Une activité exclue n'est jamais
  restreinte par l'option. Retirer la condition marquée depuis la restriction d'accès ne
  suffit pas : elle revient à la synchronisation suivante.
- **Sans créneau, pas d'effet** : l'option ne fait rien dans un cours sans aucun créneau,
  le défaut du site ne peut donc pas bloquer un cours qui n'utilise pas les heures de cours.
- **Synchronisation** : à l'enregistrement d'une activité (en fin de requête, après
  l'écriture de la configuration EFE), à chaque modification de la page Heures de cours,
  et toutes les 10 minutes par la tâche `sync_efe` (activités configurées par programme
  comme les sprints de `local_aimissions`, restaurations, duplications).
- `local_efenotes` n'est **pas modifié** et n'est pas une dépendance : il est détecté à
  l'exécution. Sans lui, l'option n'apparaît pas.

### Configuration

1. Installer, dans cet ordre, `local_classhours`, `availability_classhours`, puis
   `quizaccess_classhours` (un zip par plugin).
2. Vérifier que les restrictions d'accès sont activées (*Administration > Fonctions
   avancées > Activer les restrictions d'accès*).
3. *Administration > Plugins > Plugins locaux > Heures de cours* :

| Réglage | Défaut | Rôle |
|---|---|---|
| Tolérance après la fin d'un créneau | 5 min | l'activité reste accessible ; aucune tentative ne commence |
| Option EFE cochée par défaut | non | pour les cours qui ne l'ont jamais réglée |
| Périodes globales appliquées par défaut | oui | case « Utiliser les périodes de fermeture globales » des cours qui ne l'ont jamais réglée |

4. *Heures de cours : périodes de fermeture globales* : saisir les vacances de l'année.

**Sauvegarde et restauration** : l'emploi du temps, l'option EFE et la case des périodes
globales suivent le cours (dates décalées, groupes remappés : un créneau dont le groupe
n'est pas restauré est ignoré) ; l'exclusion EFE suit l'activité, y compris en cas de
duplication. Les périodes globales appartiennent au site : elles ne sont pas sauvegardées
avec un cours.

### Limites connues

- Seule une condition placée à la **racine** de la restriction (« toutes les conditions »)
  déclenche l'envoi automatique du test et est gérée par la page Heures de cours. Une
  condition placée dans un « OU » restreint l'accès mais ne coupe pas la tentative.
- La relecture hors créneau et l'accès ponctuel ne concernent que les **devoirs** et les
  **tests**, et une condition posée sur une **section** reste bloquante : Moodle vérifie la
  section avant l'activité.
- Le lien de demande n'apparaît que là où Moodle affiche le message de restriction (page du
  cours, page « activité restreinte »). Un accès accordé ouvre l'activité entière à l'élève
  pendant la fenêtre, pas seulement la remise.
- Un test commencé une minute avant la fin du créneau est envoyé une minute plus tard.

### Tuteur IA fermé sur un devoir remis

Indépendamment des heures de cours, le Tuteur IA n'est plus proposé sur un devoir **remis et
plus modifiable** : brouillons exigés et devoir envoyé, ou date limite passée. Il revient
si l'enseignant rouvre une tentative. L'enseignant n'est jamais concerné.

---

## Activités surveillées — ouvertes par l'enseignant, en classe

Certaines activités notées se font **en classe uniquement, au moment choisi par
l'enseignant**, pour qu'il puisse les surveiller. Une activité surveillée reste fermée aux
élèves (grisée : *« Ouverte seulement quand l'enseignant la lance en classe »*) tant que
l'enseignant ne l'a pas ouverte.

| Plugin | Emplacement | Rôle |
|---|---|---|
| `availability_supervised` | `availability/condition/supervised/` | Condition « Activité surveillée » de la restriction d'accès |
| `block_supervised` | `blocks/supervised/` | Bloc : l'élève y trouve l'activité ouverte ; l'enseignant y ouvre et ferme |
| `local_classhours` | `local/classhours/` | Ouvertures, page « Activités surveillées », ramassage, service web |
| `quizaccess_classhours` | `mod/quiz/accessrule/classhours/` | Test : pas de tentative tant que ce n'est pas ouvert, compte à rebours |

Une activité peut être à la fois surveillée **et** restreinte aux heures de cours : elle
n'est alors ouverte que pendant un créneau et une fois lancée par l'enseignant.

### Page « Activités surveillées »

Lien dans la navigation du cours.

- **En direct** : une carte par activité surveillée, avec son état (**Ouverte** / **Fermée**,
  pour qui, jusqu'à quand) et, pour un test ou un devoir, le nombre de tentatives ou
  brouillons en cours et de copies rendues depuis l'ouverture. La page se met à jour seule
  toutes les 20 s.
  - **Ouvrir** : pour **tout le cours**, un **groupe** ou un **élève** (rattrapage d'un
    absent) ; durée : jusqu'à ce que je ferme, 15 min, 30 min, 1 h, 2 h, ou jusqu'à la fin
    du créneau des Heures de cours s'il y en a un en cours. Rouvrir la même cible prolonge
    l'ouverture.
  - **Fermer** une ouverture, **Fermer l'activité**, ou **Tout fermer** en haut de page.
  - Les cartes sont rangées par **date prévue** ; **Afficher en grand** ouvre une page pour le
    vidéoprojecteur (code de séance, compte à rebours, compteurs).
  - **À rattraper** : les élèves pour qui la séance a eu lieu sans qu'ils aient rendu, avec
    leurs demandes de rattrapage et un bouton **Ouvrir pour lui**.
- **Réglages** de chaque activité (sous la carte) :
  - **date prévue**, et une date par groupe si le cours en a ;
  - **code de séance** et **mode examen** (voir plus bas) ;
  - **activités fermées pendant cette activité** : leçons, pages ou autres activités à ne
    pas consulter pendant la séance. Elles sont fermées aux élèves pour qui l'activité est
    ouverte (le groupe, ou l'élève en rattrapage), puis rouvertes à la fermeture. Techniquement,
    la même condition avec un paramètre : `{"type":"supervised","lock":<cmid>}`, suivie à la
    sauvegarde et à la restauration.
- **Tiers-temps** : les élèves concernés et leur majoration (33 % par défaut, réglage du site).
- **Choisir les activités surveillées** : une case par devoir ou test (ou toute activité qui
  porte déjà la condition). Cocher pose la condition, fermée ; décocher la retire et
  l'activité redevient libre. La condition s'ajoute aussi depuis la restriction d'accès d'une
  activité.
- **Historique** : les 20 dernières ouvertures.

### Code de séance, tiers-temps, mode examen

- **Code de séance** (option de l'activité) : chaque ouverture tire un code de 4 caractères,
  affiché sur la carte et en grand. L'élève le saisit pour commencer : un absent ne peut pas
  composer de chez lui quand l'activité est ouverte pour tout le cours. Le code saisi vaut
  présence (compteur « présents »). Cinq essais faux imposent une minute d'attente.
- **Tiers-temps** : la durée est majorée pour l'élève. Ouverte pour 30 min, l'activité reste
  ouverte 40 min pour lui (33 %). Fermée à la main, il garde la même proportion du temps
  réellement écoulé ; **Fermer aussi pour le tiers-temps** ferme tout de suite pour tous.
  Le compte à rebours du test suit sa fin propre, et son travail est ramassé à sa fin.
- **Mode examen** (option de l'activité) : pendant la séance, toute autre page du cours
  (activités, page du cours, fichiers) renvoie l'élève qui compose vers l'activité, et le
  **Tuteur IA** est coupé dans tout le cours.

Capacités : `local/classhours:supervise` pour ouvrir et fermer (enseignants, enseignants non
éditeurs, gestionnaires) ; `local/classhours:manage` pour choisir les activités.

### À la fermeture : le travail est ramassé

Comme un ramassage de copies, dans la minute qui suit (tâche `collect_supervised`, ou
`close_supervised` pour une fermeture à l'heure prévue) :
- **test** : les tentatives en cours sont envoyées, datées de la fermeture ;
- **devoir** avec brouillons exigés : les brouillons sont remis par l'API du devoir
  (notifications, achèvement, ponctualité EFE). Un brouillon vide est laissé tel quel.

Seuls les élèves qui **perdent** l'accès sont concernés : fermer un groupe ne ramasse rien
chez un élève encore ouvert individuellement. L'activité, elle, est fermée aux élèves
immédiatement, et à l'heure prévue même avant le passage de la tâche.

### Après la fermeture

Mêmes règles que les heures de cours : un élève déjà noté (note, commentaire, feedback IA
publié, ou tentative de test terminée) peut rouvrir l'activité pour **lire**. Le verrou de
remise (site et application mobile), la règle d'accès du test et le **Tuteur IA** empêchent
tout nouveau travail tant que l'activité n'est pas rouverte pour lui. Un accès ponctuel des
Heures de cours n'ouvre pas une activité surveillée.

### Bloc « Activités surveillées »

- **Élève**, tableau de bord (une frise par cours) ou page du cours : une **frise**
  chronologique, à la manière du carrousel des QCM vidéo :
  - **faite** (grisée) ;
  - **à rattraper** (rouge), avec **Demander un rattrapage** : les enseignants sont prévenus ;
  - **ouverte** (verte), avec l'heure de fermeture et **Commencer** (ou **Saisir le code**) ;
  - **à venir**, avec la date prévue, la **prochaine** en jaune.

  La carte ouverte, sinon la prochaine, est amenée à l'écran. Le bloc se rafraîchit toutes
  les 30 s : une activité que l'enseignant vient d'ouvrir apparaît sans recharger la page.
- **Enseignant**, page du cours : chaque activité surveillée avec sa date prévue, **Ouvrir**
  (tout le cours, jusqu'à fermeture) ou **Fermer** en un clic, et « Plus d'options » vers la
  page de pilotage.
- **Enseignant**, tableau de bord : pour ses cours, les activités ouvertes (**Fermer**),
  celles prévues aujourd'hui (**Ouvrir**) et le nombre de demandes de rattrapage.

Pour le proposer à tous les élèves : *Administration > Apparence > Tableau de bord par
défaut*, ajouter le bloc, puis « Réinitialiser le tableau de bord pour tous les
utilisateurs ».

### Configuration

1. Installer `local_classhours`, puis `availability_supervised`, `block_supervised` et
   `quizaccess_classhours`.
2. Vérifier que la condition « Activité surveillée » est activée (*Administration > Plugins >
   Restrictions d'accès*) et que la cron tourne (ramassage, fermeture à l'heure prévue).

### Limites connues

- À la fermeture, les réponses saisies depuis le dernier enregistrement automatique du test
  (réglage du site, 60 s par défaut) ou depuis le dernier changement de page sont perdues,
  comme lorsqu'un temps limite expire.
- Seuls les devoirs et les tests sont ramassés et relisibles après fermeture ; les autres
  activités peuvent être surveillées (ouvrir, fermer), sans ramassage.
- La condition n'est proposée que sur une activité, pas sur une section. Les ouvertures ne
  sont pas sauvegardées avec le cours : une activité restaurée est fermée (ses options et
  dates prévues, elles, suivent l'activité ; le tiers-temps, donnée personnelle, non).
- « À rattraper » n'existe que pour les devoirs, les tests et les activités à achèvement
  suivi ; les groupes sont ceux du moment présent.
- Mode examen : limité au cours de l'activité (les autres cours et Internet restent
  accessibles), et l'application mobile peut avoir du contenu du cours hors ligne.
- Le code de séance prouve la présence tant qu'il n'est pas transmis à un absent ; il change à
  chaque ouverture.

---

## Contrôle des tentatives — hors créneau et tentatives suspectes

`local_attemptcheck` repère les **tentatives de test** et **remises de devoir** à examiner, et
permet de **supprimer** celles que l'enseignant juge non légitimes. Utilisable dans tous les
cours (lien « Contrôle des tentatives » de la navigation du cours) ; l'indicateur « hors
créneau » s'ajoute quand les heures de cours sont configurées (lien direct depuis la page
Heures de cours).

> Un indicateur **n'est pas une preuve** : un élève peut être rapide, avoir préparé son texte
> ou le dicter. Chaque indicateur affiche ses chiffres pour juger sur pièce.

### Indicateurs

| Indicateur | Test | Devoir | Référence |
|---|---|---|---|
| **Hors créneau** | commencé ou terminé hors des créneaux de l'élève | remis hors créneau | emploi du temps actuel, appliqué aussi aux tentatives d'avant sa mise en place |
| **Rapide** | durée < 40 % de la médiane | premier accès → remise < 40 % de la médiane | 1re tentative terminée des **autres** élèves, 5 au moins |
| **Question rapide** | question rédigée traitée en < 25 % du temps médian | — | idem, par question |
| **Écriture** | texte apparu à plus de 70 mots/min (ajout ≥ 40 mots) | idem (texte en ligne) | **aucune** : un collage se voit sans la classe |
| **Durée minimale** | durée < minimum fixé par l'enseignant | idem | aucune (réglée par activité) |

- **Questions rédigées** : composition IA, réponse courte IA, composition et réponse courte
  (réglage `textqtypes`). Le temps d'une question va de l'arrivée sur sa page (enregistrement
  de la page précédente, ou affichage de la page d'après le journal) à l'apparition de la
  réponse finale. Moodle ne garde qu'un enregistrement automatique à la fois : la frappe se
  mesure d'enregistrement de page en enregistrement de page.
- **Devoirs** : premier accès = première consultation de l'activité (journal standard ; sinon
  création de la remise). Le nombre de mots du texte en ligne à chaque enregistrement vient du
  journal (`onlinetextwordcount`).
- Tous les seuils sont réglables (*Administration > Plugins > Plugins locaux > Contrôle des
  tentatives*).

### Rapport et décisions

- Filtres : activité, indicateur (ou toutes les tentatives), hors créneau sur les activités
  restreintes seulement ou toutes, tentatives jugées légitimes.
- Par tentative : **Voir** (relecture du test, correcteur du devoir), **Légitime** (la ligne est
  masquée ; « À revoir » annule), **Supprimer**. Actions groupées sur la sélection.
- **Durée minimale attendue** : se règle dans la vue d'une activité.

### Suppression

Page de confirmation obligatoire. Droits : `local/attemptcheck:delete` (enseignants éditeurs)
**et** la capacité de l'activité.

- **Test** (`mod/quiz:deleteattempts`) : suppression standard de la tentative, note recalculée.
  Les corrections IA en attente de la tentative sont annulées.
- **Devoir** (`mod/assign:grade`) : contenu de la remise effacé (statut « nouveau », ou
  « rouvert »), feedback IA et note effacés ; l'élève peut redéposer. Même effet que
  « Supprimer la remise » de Moodle, qui exige une capacité que les enseignants n'ont pas par
  défaut.
- La note effacée part au carnet de notes : **EFE reçoit une note grise**.

### Notification

À chaque remise, une tâche ad hoc analyse la tentative ; si un indicateur se déclenche, les
enseignants du cours (`local/attemptcheck:notify`) reçoivent une notification Moodle avec le
lien vers le rapport. Réglage *Notifier les enseignants* pour la couper.

### Limites connues

- Remises de devoir de groupe non prises en charge.
- Le premier accès à un devoir est la première consultation : un élève qui a regardé le sujet
  une semaine avant ne sera pas signalé comme rapide.
- Sans journal standard, premier accès et vitesse d'écriture des devoirs sont estimés plus
  grossièrement.
- Le mode de groupe du cours n'est pas appliqué au rapport : un enseignant qui peut le
  consulter voit tous les élèves.

---

## Structure du dépôt

```
local/aifeedback/                  Bibliothèque partagée
├── classes/
│   ├── api.php                    Appel HTTP OpenAI + JSON Schema + surcharges
│   ├── content_extractor.php      Extraction texte/PDF/DOCX/ZIP + images (vision)
│   ├── task/run_job.php           File d'attente ad-hoc + verrou global + drainage
│   ├── secret.php                 Chiffrement/déchiffrement de la clé API
│   ├── math.php                   round_up_quarter()
│   ├── job_handler.php            Interface des handlers
│   ├── quiz_grader.php            Base partagée des correcteurs IA pour quiz
│   ├── feedback_card.php          Rendu HTML des cartes de feedback
│   ├── prompt.php                 Consignes injectées (accessibilité dys, etc.)
│   ├── observer.php               Observer partagé des soumissions de quiz
│   └── admin/encrypted_password.php
├── settings.php                   Tous les réglages globaux
├── retry.php                      Relance manuelle d'une correction IA
└── version.php

mod/assign/feedback/ai/            Feedback IA des devoirs
├── classes/{job_handler,observer}.php
├── locallib.php / lib.php         Pilotage de la correction (délègue l'extraction à local_aifeedback)
├── db/{events,install,upgrade,access}.xml/php
└── settings.php

question/type/aiessay/             Question « composition » corrigée par IA
├── classes/job_handler.php        Schéma JSON et prompt par défaut (base : local_aifeedback\quiz_grader)
├── questiontype.php               Options, export/import Moodle XML sans la clé API
├── question.php / edit_aiessay_form.php / renderer.php
├── backup/moodle2/                Sauvegarde/restauration des options (duplication de test, import,
│                                  copie de cours) sans la clé API ; options par défaut si absentes
└── db/{install.xml, upgrade.php}

question/type/aishortanswer/       Question « réponse courte » corrigée par IA (même organisation)
├── classes/job_handler.php
├── questiontype.php / question.php / edit_aishortanswer_form.php / renderer.php
├── backup/moodle2/
└── db/install.xml

local/moodlesearch/                MoodleSearch : moteur de recherche Web sans IA
├── classes/
│   ├── searcher.php               Recherche : accès, cache, limites, Tavily, journal
│   ├── access.php                 Site activé, capacité, cohortes, mode examen, clé
│   ├── opnsense_bridge.php        Liste noire et ouverture via block_opnsenseaccess (facultatif)
│   ├── clicks.php                 Clics : résultat relu dans le journal, statut d'ouverture
│   ├── report.php / output.php    Rapports enseignant / site, rendu des résultats
│   └── task/purge_logs.php        Purge des traces anciennes
├── index.php                      Page de recherche
├── go.php / ajax_open.php / js/open.js   Clic, page d'attente, ouverture du site
├── report.php / cohorts.php       Traces des élèves, accès par cohorte
└── db/{install.xml,access.php,hooks.php,tasks.php}

local/classhours/                  Heures de cours : emploi du temps par cours
├── classes/
│   ├── schedule.php               Calcul des créneaux (groupes, fusion, périodes, fuseau serveur)
│   ├── store.php                  Écritures (créneaux, périodes, option EFE, exclusions)
│   ├── availability_json.php      Pose / retrait de la condition à la racine du JSON d'accès
│   ├── efe_bridge.php / efe_sync.php   Option EFE (local_efenotes facultatif)
│   ├── form/                      Formulaires d'ajout (créneau, ouverture, période fermée)
│   ├── gate.php                   Activités surveillées : ouvertures (cours, groupe, élève, durée, tiers-temps, code)
│   ├── plan.php / extratime.php / catchup.php   Dates prévues et options, tiers-temps, rattrapage
│   ├── gate_collector.php         Ramassage à la fermeture (tentatives, brouillons)
│   ├── supervised_view.php        Affichage partagé : page de pilotage, bloc, rafraîchissement
│   ├── external/supervised_refresh.php   Service web de rafraîchissement
│   └── task/                      sync_efe, collect_supervised, close_supervised
├── manage.php                     Page « Heures de cours » du cours
├── supervised.php                 Page « Activités surveillées » du cours (et affichage en grand)
├── code.php / catchup.php         Élève : saisie du code de séance, demande de rattrapage
└── backup/moodle2/                Sauvegarde : niveau cours et niveau activité

availability/condition/classhours/ Condition « Pendant les heures de cours »
├── classes/{condition,frontend}.php
└── yui/                           Formulaire (build = copie de src, pas d'étape de build)

availability/condition/supervised/ Condition « Activité surveillée »
├── classes/{condition,frontend}.php
└── yui/

blocks/supervised/                 Bloc « Activités surveillées » (élève, enseignant)

mod/quiz/accessrule/classhours/    Règle de test : fin de tentative à la fin du créneau (et tests surveillés)
├── rule.php
└── classes/task/close_expired_attempts.php

local/attemptcheck/                Contrôle des tentatives : hors créneau, tentatives suspectes
├── classes/
│   ├── collector.php              Tentatives, étapes des questions rédigées, journal
│   ├── analyser.php               Indicateurs (calcul pur : médianes, sauts de texte)
│   ├── checker.php                Collecte + indicateurs, activité par activité
│   ├── offslot.php                Hors créneau via local_classhours (facultatif)
│   ├── remover.php                Suppression d'une tentative / effacement d'une remise
│   ├── review.php                 Décisions « légitime », durée minimale par activité
│   ├── notifier.php / task/analyse_item.php   Analyse après remise et notification
│   └── logs.php                   Lecture du journal standard
└── report.php                     Rapport du cours, confirmation de suppression
```

---

## Feuille de route

- [x] **Phase 0** — Feedback IA automatique des devoirs, file d'attente, publication
- [x] **Phase 1** — Surcharges par devoir + clé API chiffrée
- [x] **Phase 2** — Support de la vision (PDF / ZIP / images intégrées)
- [x] **Phase 3.A** — Extraction de l'infrastructure partagée dans `local_aifeedback`
- [x] **Phase 3.B** — Type de question `qtype_aiessay`
- [x] **Phase 3.C** — Type de question `qtype_aishortanswer` (réponse courte)
- [ ] **Phase 4** — Générateur d'exercices

---

## Licence

GPL v3 ou ultérieure, conformément à [Moodle](https://moodle.org/).
