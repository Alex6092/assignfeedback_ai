# Outils IA du site : champs, usages, limites

Extrait de `docs/prompt-parcours.md` (partie A), tenu à jour avec le code des plugins.
Pour les réglages appliqués par rôle, `reglages-moodle.md` fait foi.

### Vue d'ensemble

| Outil | Type Moodle | Ce qu'il produit | Quand l'utiliser dans un parcours |
|---|---|---|---|
| **Générateur de tests IA** (`local_aiquizgen`) | page de cours | un quiz complet + sa catégorie de questions | auto-évaluation après chaque module de cours |
| **Composition corrigée par IA** (`qtype_aiessay`) | type de question | note + carte de feedback par copie | question de synthèse, analyse, justification de choix |
| **Réponse courte corrigée par IA** (`qtype_aishortanswer`) | type de question | note + feedback court | vérification de compréhension (1 à 3 phrases) |
| **Correction IA** (`assignfeedback_ai`) | plugin de feedback de devoir | note + feedback structuré à la remise | TP, dossier, compte rendu, étude de cas |
| **Tuteur IA** (`local_aichat`) | section du formulaire de devoir | accompagnement conversationnel de l'élève | pendant un TP ou une recherche de matériel |
| **Missions client IA** (`local_aimissions`) | page de cours | un devoir « demande client » par groupe + client IA questionnable | projet en groupes, sprints successifs |
| **Report EFE** (`local_efenotes`) | section du formulaire de devoir | remontée de la note sur une compétence du référentiel | tout devoir noté à rattacher au référentiel |

Socle commun : **`local_aifeedback`** porte la connexion au LLM (URL, modèle, clé, vision,
pool de serveurs) et la file d'attente. **Un seul appel LLM à la fois sur tout le site**, par
tâches de fond : le **cron Moodle doit tourner**, et une correction n'est pas instantanée.
Chaque outil peut surcharger l'URL, le modèle, la clé et la vision, mais dans un parcours
normal on laisse les réglages globaux.

Conséquence à garder en tête pour la conception : **le coût se paie à la copie, pas à la
création**. 30 élèves × 1 composition = 30 appels LLM sérialisés. Doser les questions
corrigées par IA, et préférer les QCM standard pour le volume.

---

### 1. Générateur de tests IA — `local_aiquizgen`

**Accès** : dans le cours, menu **« Générer un test IA »** (capacité
`local/aiquizgen:generate`). Le résultat est mis en file : suivi dans « Génération de
tests — suivi ».

**Ce qu'il fait** : lit une source (leçon Moodle ou PDF), rédige les questions, les dépose
dans une **nouvelle catégorie de la banque de questions** et crée un **quiz prêt à l'emploi**
dans la section générale du cours.

**Champs du formulaire**

| Champ | Valeurs | À fournir dans un parcours |
|---|---|---|
| Type de source | `Document PDF (uploadé)` \| `Leçon Moodle du cours` | « Leçon Moodle » si le module de cours est une leçon |
| Leçon / Document PDF | liste des leçons du cours, ou un fichier | le nom exact de la leçon créée à l'étape précédente |
| QCM standard Moodle | 0 à 50 (défaut 10) | le volume : ces questions ne coûtent rien à la correction |
| Réponses courtes corrigées par IA | 0 à 50 (défaut 0) | 2 à 4 maximum : un appel LLM par copie |
| Compositions corrigées par IA | 0 à 10 (défaut 0) | 0 ou 1 : correction longue |
| QCM à pool aléatoire | 0 à 50 | seulement si `qtype_answersselect` est installé |
| Exercices CodeRunner | 0 à 20 | seulement si `qtype_coderunner` + serveur Jobe ; **relire chaque solution** (bouton « Check ») |
| Langage cible (CodeRunner) | Python 3, JavaScript/Node, C, C++, Java (fonction) ou C/C++ (programme complet stdin → stdout) | les variantes « fonction » sont plus adaptées au BTS |
| Mode de variation | `Quiz fixe` \| `Tirage aléatoire par tentative` | « aléatoire » pour l'entraînement, « fixe » pour une évaluation commune |
| Questions tirées par tentative | ≤ nombre total généré (défaut 10) | générer un pool plus grand que le tirage |
| Titre du quiz à créer | texte | titre final visible par l'élève |

**Ce que la spécification du parcours doit donner** : la leçon source, les nombres par type,
le mode de variation, le titre du quiz.

---

### 2. Composition corrigée par IA — `qtype_aiessay`

**Accès** : banque de questions → nouvelle question → **« Composition (correction IA) »**,
puis ajout à un quiz. Générable en lot par `local_aiquizgen`.

**Champs propres à l'IA**

| Champ | Contenu attendu |
|---|---|
| Prompt système | facultatif. Vide = prompt du site. À remplir pour imposer un cadre (« corrige comme un professeur de BTS CIEL, exige le vocabulaire normalisé ») |
| Corrigé attendu / barème | **la référence de correction** : éléments-clés attendus, pondération, erreurs typiques. Jamais visible par l'élève |
| Compétences évaluées | **une par ligne**, reprises du référentiel |

**Champs de forme** (standard Moodle) : format de réponse (éditeur par défaut), nombre de
lignes (15), minimum et maximum de mots, pièces jointes autorisées.

**Résultat** : le LLM renvoie un score 0–100 converti en note par
`note = arrondi_au_quart_supérieur(score / 100 × note_max)`, un niveau de maîtrise et une
carte de feedback. Un appel LLM **par copie**, à la soumission du quiz.

---

### 3. Réponse courte corrigée par IA — `qtype_aishortanswer`

Même principe pour des réponses de 1 à 3 phrases. Champs : **prompt système** (facultatif),
**corrigé attendu / barème**, nombre de lignes du champ de saisie. Pas de liste de
compétences. Correction plus rapide et plus homogène que la composition : à préférer dès que
la question peut se répondre en quelques phrases.

---

### 4. Correction IA d'un devoir — `assignfeedback_ai`

**Accès** : dans un devoir (`mod_assign`), section **« Correction IA »** : cocher
**« Correction IA »** fait apparaître les champs. La correction part à la remise de l'élève
(événement de soumission), en tâche de fond.

**Champs**

| Champ | Contenu attendu |
|---|---|
| Correction IA | la case d'activation |
| Prompt système | pré-rempli avec le prompt du site. À adapter pour un TP particulier |
| Énoncé de l'exercice | le texte de l'exercice **tel que l'élève l'a reçu** |
| Corrigé attendu / barème | la référence de correction, avec la répartition des points. **Ne jamais le mettre dans la description du devoir** |
| Compétences évaluées | une par ligne (référentiel) |
| Surcharges (URL de l'API, modèle, clé, vision) | à laisser décochées en usage normal ; vision = images des remises envoyées au modèle |

**Ce que l'IA renvoie** (schéma JSON strict, utile pour rédiger le barème) :
`niveau` (Maîtrise insuffisante 0–24, fragile 25–49, satisfaisante 50–79, très bonne 80–100),
`score` 0–100, `points_forts[]`, `points_a_ameliorer[]`, `feedback`, `competences_evaluees[]`
(compétence + niveau + commentaire), `signalement` (rempli seulement si la copie contient des
pressions sur le correcteur ou un contenu inapproprié — visible de l'enseignant seul).

**Réglage du site** : « Afficher le score /100 aux étudiants » est **désactivé par défaut** :
l'élève voit le niveau et le feedback, pas le chiffre.

**Formats de remise acceptés** : texte en ligne, PDF, DOCX, ZIP, images (avec la vision
activée et `poppler-utils` côté serveur).

---

### 5. Tuteur IA — `local_aichat`

**Accès** : dans un devoir, section **« Tuteur IA »**. **Limite actuelle : uniquement sur les
devoirs** (`mod_assign`) — pas sur les leçons ni les quiz. Les conversations sont
consultables par l'enseignant.

**Champs**

| Champ | Valeurs | Remarques |
|---|---|---|
| Activer le tuteur IA | case | ajoute un bouton de discussion sur la page du devoir |
| Transmettre la consigne au tuteur | case (défaut **non**, réglage du site « Transmettre la consigne au tuteur, par défaut » ; **oui** pour les devoirs créés par Missions IA, dont la consigne est la demande du client) | envoie la **description** et les **« Instructions de l'activité »** au tuteur. Avec elles, le tuteur peut juger la réponse de l'élève et lui dire si elle est juste ; si on la coche, elles ne doivent contenir **aucun élément de corrigé** |
| Transmettre le brief pédagogique | case (défaut oui) | un brief (critères, points d'attention) est **généré automatiquement** à partir de l'énoncé et du corrigé de la Correction IA, puis relisible par l'enseignant. Le corrigé lui-même n'est jamais transmis |
| Consignes supplémentaires | texte libre | « Ne suggère aucune bibliothèque externe », « exige le vocabulaire normalisé »… |
| Recherches du tuteur | `Aucune` \| `Recherche Web ponctuelle` \| `Recherche de matériel` | voir ci-dessous |
| Sites de référence | un domaine par ligne | ex. `teracomsystems.com`, `hw-group.com`, `icpdas-europe.com`, `gotronic.fr`, `raspberrypi.com` |

**Modes de recherche** :
- *Aucune* : le tuteur répond de ses seules connaissances. À choisir pour une évaluation ou un
  exercice dont la solution se trouve en ligne.
- *Recherche Web ponctuelle* : il cherche quand l'information est récente ou externe, et cite
  ses sources.
- *Recherche de matériel* : il cherche des références, **lit les pages des fabricants et les
  datasheets** pour en relever les caractéristiques, propose au plus 3 pistes par réponse, et
  ne remplit jamais l'étude comparative à la place de l'élève.

**Prérequis élève pour la recherche** : chaque élève doit avoir déposé **sa propre clé d'API**
(Tavily gratuite, Brave en secours) dans ses préférences. Sans clé, le tuteur fonctionne mais
ne va pas sur Internet. Un parcours ne doit donc **pas dépendre** de la recherche Web pour
progresser : elle enrichit, elle ne débloque pas.

---

### 6. Missions client IA — `local_aimissions`

**Accès** : dans le cours, **« Générer des missions client IA »**, puis **« + Nouveau sprint »**.
Un projet (entreprise fictive) est créé **par groupe**, avec des sprints successifs. Chaque
sprint devient un **devoir caché**, corrigé automatiquement à la remise ; l'enseignant relit
puis publie depuis **« Afficher les Sprints générés »**. Les élèves peuvent **poser des questions
au client IA** (fil de tickets).

**Champs**

| Champ | Valeurs |
|---|---|
| Contexte pédagogique | texte libre (4 000 caractères) : module, notions, contraintes pédagogiques, **choix technologiques imposés** (ils apparaissent comme une contrainte du client, jamais comme la solution). Prérempli avec celui du dernier sprint |
| Compétences évaluées (EFE) | **une ou plusieurs** compétences du référentiel EFE (ou libellé libre si EFE absent) |
| Niveau | BTS CIEL 1re / 2e année |
| Complexité | Découverte \| Intermédiaire \| Avancé |
| Nombre de contraintes | 1 à 5 (défaut 3) |
| Profil du client | Neutre/coopératif, Exigeant, Imprécis/flou, Change souvent d'avis, Lent à répondre, Non technique (fixé au premier sprint du groupe) |
| Tuteur IA | activer sur ce sprint (oui/non) et mode de recherche (aucune, Web, matériel) |
| Groupes cibles | cases, un projet distinct par groupe (anti-triche) ; **création de groupes** possible dans le formulaire (un nom par ligne) |

**Devoir créé** : barème **« Barème officiel »** (réglable), bouton « Envoyer » obligatoire,
**2 tentatives accordées automatiquement**, achèvement « Remettre un travail ». La demande client
est dans les **« Instructions de l'activité »** ; la description ne porte que le bloc de
compétence EFE (affiché sur la page de cours).

**Duplication d'un sprint** (« Dupliquer », dans « Sprints générés ») vers d'autres groupes :
- **copie à l'identique**, sans IA, vers un groupe au même point (sans projet, ou même
  entreprise au sprint précédent) ;
- **adaptation par l'IA** à l'entreprise de chaque groupe : mêmes besoins, même grille,
  textes différents.

Des **événements** peuvent survenir en cours de projet : changement de besoin, bug critique,
RGPD, réduction de budget.

---

### 7. Report des compétences EFE — `local_efenotes`

Section **EFE** du formulaire de devoir : activation, **une ou plusieurs compétences**,
libellé de devoir de remplacement, enseignant responsable, seuils de couleur. À la notation,
la note est reportée sur la compétence. Utile pour garantir la **variété des compétences**
couvertes par le parcours : chaque devoir noté porte son code.

---

### 8. Limites à connaître avant de concevoir

- **Cron obligatoire**, corrections en tâche de fond, **un appel LLM à la fois** sur le site.
- Le **tuteur IA n'existe que sur les devoirs**.
- Les questions IA coûtent **un appel par copie** : QCM standard pour le volume, IA pour ce
  qui a besoin d'un jugement.
- Les **solutions générées par CodeRunner doivent être vérifiées** (bouton « Check »).
- La **recherche Web du tuteur dépend d'une clé personnelle** de l'élève.
- Le **corrigé ne va jamais** dans la description d'une activité : il va dans le champ
  « Corrigé attendu / barème », que l'élève ne voit pas et que le tuteur ne reçoit pas.
