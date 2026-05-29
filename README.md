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
