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

---

## Tuteur IA — recherche Web

Le tuteur (`local_aichat`) peut chercher sur Internet quand une information est
récente ou externe (« quelle est la dernière version de Python ? »). **Le modèle
décide** d'appeler l'outil `web_search` (tool calling de l'API compatible OpenAI) ;
**le PHP exécute** la recherche. Le modèle n'a jamais accès à Internet, ni à l'URL,
ni à la clé : il ne fournit qu'un texte de requête.

### Architecture

```
stream.php (SSE, ticket du pool tenu pendant toute la réponse)
  └─ generator::run()
       tour 1 : api::stream(messages, tools=[web_search]) ──▶ LM Studio / Gemma
          ├─ delta.content     → SSE « delta » vers l'élève (inchangé)
          └─ delta.tool_calls  → accumulés (index, id, nom, arguments en morceaux)
       appel reçu → websearch\tool::execute()
          limite par réponse → requête valide → moteur suspendu ? → quota élève
          → plafond du site (réservation sous verrou) → brave_provider::search()
          → résultats compacts, ou WEB_SEARCH_UNAVAILABLE + reason
       SSE « search » (statut du widget) ; messages += assistant(tool_calls) + tool(résultat)
       tour 2 : api::stream(...) — sans tools si la limite est atteinte — réponse streamée
```

Une activité où la recherche n'est pas autorisée envoie **exactement la même
requête qu'avant** (aucun champ `tools`, aucun bloc de prompt).

### Configuration

1. **Brave Search API** : créer un compte sur <https://api-dashboard.search.brave.com>,
   générer une clé. Depuis février 2026, Brave offre 5 $ de crédits par mois (environ
   1 000 recherches), puis **facture** la carte enregistrée au lieu de refuser, et exige
   d'être cité comme source (le widget affiche « Recherche sur le Web (Brave Search) »).
   Vérifiez dans le tableau de bord s'il existe un plafond de dépense.
2. **Moodle**, *Administration > Plugins > Plugins locaux > Tuteur IA*, rubrique
   « Recherche Web » :

   | Réglage | Défaut | Rôle |
   |---|---|---|
   | Activer la recherche Web | non | interrupteur du site |
   | Moteur de recherche | Brave | fournisseur (abstraction `websearch\provider`) |
   | Clé API | — | chiffrée en base (`admin_setting_encryptedpassword`), jamais réaffichée |
   | Plafond du site | 900 | recherches sur **31 jours glissants** (0 = aucune) |
   | Recherches par élève | 10 | par fenêtre du quota élève (4 h), 0 = pas de limite |
   | Recherches par réponse | 2 | protection contre les boucles (1 à 5) |
   | Résultats par recherche | 5 | 1 à 10 |
   | Délai d'une recherche | 6 s | 2 à 20 |
   | Durée du cache des recherches | 7 jours | 0 à 90, 0 = pas de cache |

3. **Page « Tuteur IA : recherche Web »** (lien dans la rubrique) : lancer d'abord
   **« Tester l'appel d'outil »** sur chaque serveur du tuteur (aucun appel à Brave),
   puis **« Tester la recherche »** (une vraie recherche, décomptée).
4. **Par activité**, dans la section « Tuteur IA » des réglages de l'activité, le menu
   « Recherches du tuteur » propose trois choix :
   - **Aucune** (défaut) ;
   - **Recherche Web ponctuelle** ;
   - **Recherche de matériel** (voir la section suivante).

   Deux champs l'accompagnent :
   - le **plafond de recherches Web de l'activité** sur 31 jours (150 par défaut,
     0 = seul le plafond du site) ;
   - les **sites de référence**, pour le mode matériel.

### Quota

- **Plafond du site, fenêtre glissante de 31 jours.** Un cycle de facturation Brave
  dure au plus 31 jours, donc aucun cycle ne peut dépasser le plafond, quelle que
  soit sa date de début : pas de date de réinitialisation à caler. Les en-têtes
  `X-RateLimit-*` de Brave décrivent la limite du *plan*, pas les crédits gratuits.
  Ils sont affichés pour le diagnostic et servent à détecter la limite par seconde,
  mais **seul le plafond Moodle protège d'une facture**.
- **Réservation avant l'appel, sous verrou Moodle** : lecture du compteur et
  inscription dans le registre `local_aichat_wsledger` (aucune donnée personnelle,
  jamais effacé par une demande RGPD ni une réinitialisation de cours). Deux requêtes
  simultanées à 899/900 sont sérialisées : la seconde est refusée. La réservation
  est remboursée seulement si Brave répond une erreur HTTP (non facturée) ; un délai
  dépassé après connexion reste compté.
- **Par élève** : somme des recherches de ses réponses sur la fenêtre du quota élève.
- **Par réponse** : au-delà de la limite, le modèle reçoit `tool_call_limit`, puis le
  dernier tour est envoyé sans outil : la boucle se termine forcément.
- **Cache des recherches** (cache Moodle `searchcache`, 7 jours par défaut) : une requête
  déjà faite par n'importe quel élève est resservie sans appeler Brave. La casse et les
  espaces sont ignorés, les opérateurs `site:` et `filetype:` conservés.
  - Le cache est consulté **avant** le budget. Une recherche servie par le cache est
    gratuite et instantanée : elle ne consomme ni le plafond du site ni le quota de l'élève,
    et elle reste disponible si Brave est en panne.
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

| Situation | Outil proposé ? | Brave appelé ? | Le modèle reçoit |
|---|---|---|---|
| Désactivée (site ou activité) | non | non | rien |
| Clé absente, moteur suspendu, plafond du site ou de l'élève atteint | non | non | une note « recherche indisponible » dans le prompt |
| Plafond atteint pendant la génération | oui | non | `quota_exhausted` / `user_quota_exhausted` |
| Limite par réponse atteinte | puis non | non | `tool_call_limit` |
| Requête vide ou invalide, outil inconnu | oui | non | `invalid_query` / `unknown_tool` |
| Verrou du budget indisponible (3 s) | oui | non | `budget_busy` |
| Brave : 429, 5xx, réseau, délai, clé refusée | oui | oui | `rate_limited`, `provider_error`, `timeout`, `auth_error`… |

Coupe-circuit : panne ou délai dépassé → moteur suspendu 60 s ; clé refusée (401/403)
→ suspendu jusqu'à « Remettre en service » ; 429 avec quota mensuel du plan à 0 →
suspendu jusqu'à la réinitialisation annoncée. Pendant une suspension, l'outil n'est
pas proposé et le tuteur répond avec ses connaissances.

### Sécurité et vie privée

- Paramètres Brave fixés par le PHP : `safesearch=strict`, `result_filter=web`,
  `extra_snippets=true`, nombre de résultats ; le modèle ne fournit que `query`
  (200 caractères max, JSON borné, autres clés ignorées). Si Brave refuse
  `extra_snippets`, la requête est relancée sans lui (une erreur n'est pas facturée).
- Nom, prénom, identifiant, e-mail et téléphone de l'élève sont retirés de la requête
  avant l'envoi à Brave.
- Ce que le modèle reçoit : **uniquement la réponse de Brave**, aucune page n'est
  téléchargée. Par résultat : titre (≤ 120), URL http/https (≤ 300), date, extrait
  principal (≤ 400) et jusqu'à 5 extraits supplémentaires (≤ 300 chacun). Le total est
  borné à 6 000 caractères par recherche et réparti équitablement entre les résultats.
  L'ensemble est présenté comme des **données non fiables**, jamais comme des
  instructions.
- **Sources ajoutées par le PHP** en fin de réponse : les pages transmises au modèle
  (8 liens au plus), ce qui vaut aussi mention de Brave Search. Le modèle a la
  consigne de ne pas écrire lui-même de liste de sources ni d'URL, et de ne donner
  aucun chiffre ou caractéristique absent des résultats.
- Le prompt interdit de chercher la solution de l'activité ; les règles d'intégrité du
  tuteur restent en dernier et inchangées.
- Les requêtes figurent dans les transcriptions enseignant et dans l'export RGPD.

### Fichiers

- **Créés** (`local/aichat`) :
  - `classes/generator.php` (boucle de tours) ;
  - `classes/websearch/` : `provider` (interface), `brave_provider`, `result`, `budget`,
    `tool`, `manager`, `searchcache`, `diagnostic` ;
  - `websearch.php` (page d'état et de tests).
- **Modifiés** :
  - `local/aifeedback/classes/api.php` : `tools` / `tool_choice` transmis, `tool_calls`
    reconstitués dans le flux ;
  - `local/aichat` : `stream.php`, `tutor.php` (bloc de prompt, date du jour),
    `quota.php`, `conversation.php`, `lib.php` et `activity.php` (case par activité),
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
- cache des recherches (requête identique d'un autre élève, Brave en panne, expiration, échec jamais gardé).

Sur le site :
1. page de diagnostic, sur chaque serveur ;
2. « dernière version de Python ? » → statut de recherche et sources citées ;
3. « explique une boucle for » → aucune recherche ;
4. plafond réglé au nombre déjà utilisé + 1, deux élèves en même temps → une seule
   recherche ;
5. clé erronée → réponse sans le Web, suspension affichée.

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
| `web_search` | Brave (budget) | trouver la page du fabricant ou la datasheet (`site:`, `filetype:pdf`) |
| `read_page` | gratuit | lire cette page ou ce PDF et relever les caractéristiques |

```
stream.php → toolbox (limite globale d'appels par réponse, 5 par défaut)
  ├─ websearch\tool  → Brave (plafonds du site et de l'activité, cache)
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
- le plafond de recherches **par activité** est vérifié sous le même verrou que celui
  du site, et les recherches sont rattachées à l'activité dans le registre ;
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
├── classes/{job_handler,observer}.php
├── questiontype.php / question.php / edit_aiessay_form.php / renderer.php
└── db/{events.php, install.xml}
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
