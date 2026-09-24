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
