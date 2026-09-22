# Créer un parcours Moodle avec l'IA — outils disponibles et prompt type

Deux parties :

1. **[Inventaire des outils IA](#partie-a--inventaire-des-outils-ia)** : ce que fait chaque
   outil, où il se branche, et **les champs exacts à remplir** dans Moodle. C'est ce bloc que
   tu colles dans ton prompt (ou que tu joins en fichier).
2. **[Prompt type](#partie-b--prompt-type-pour-claude-opus-5)** : ton modèle de demande,
   réécrit et complété pour Claude Opus 5.

Tenu à jour avec le code : si un champ change dans un plugin, il change ici.

---

## Partie A — Inventaire des outils IA

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
| Transmettre la consigne au tuteur | case (défaut oui) | envoie la **description** et les **« Instructions de l'activité »** au tuteur → elles ne doivent contenir **aucun élément de corrigé** |
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

---

## Partie B — Prompt type pour Claude Opus 5

Copie ce qui suit, remplace les `[...]`, joins le référentiel et le parcours de référence.
La partie A peut être collée à la place de « Inventaire des outils » ou jointe en fichier.

---

### Contexte

Je suis enseignant en **BTS CIEL option A (Informatique & Réseaux)** — Cybersécurité,
Informatique et réseaux, Électronique. Je construis mes parcours sur **Moodle 5.2**, enrichi
de plugins IA que j'ai développés. Je veux créer un nouveau parcours sur **[thème]**.

Public et cadre :
- **[1re / 2e]** année, **[nombre]** étudiants, en **[demi-groupe / classe entière]** ;
- volume visé : **[nombre]** séances de **[durée]**, soit environ **[total]** heures ;
- prérequis déjà acquis : **[...]** ;
- matériel et logiciels disponibles : **[...]** ;
- évaluations attendues : **[formatives seules / une note par module / une évaluation
  finale]**.

### Ta mission

Concevoir le parcours complet : progression, contenus de cours, activités, évaluations, et
**le paramétrage Moodle de chaque activité**, prêt à copier dans les champs des plugins.

### Inventaire des outils IA dont je dispose

[coller ici la partie A, ou : « voir le fichier prompt-parcours.md joint »]

### Contraintes de conception

1. **Compétences variées** : le référentiel est joint. Chaque activité notée cite les
   compétences travaillées, et le parcours en couvre un éventail large — pas la même
   compétence partout. Donne en fin de parcours un tableau de couverture
   compétence × activité.
2. **Doser les corrections IA** : un appel LLM par copie, sérialisé sur tout le site.
   Privilégie les QCM standard pour le volume, les réponses courtes IA pour la
   compréhension, la composition IA pour la synthèse (une par module au maximum).
3. **Le tuteur IA ne se branche que sur les devoirs.** Ne le propose pas ailleurs.
4. **Jamais de corrigé dans une description d'activité** : il va dans « Corrigé attendu /
   barème ». La description est transmise au tuteur, donc elle ne doit rien divulguer.
5. **Ne dépends pas de la recherche Web** : elle exige une clé personnelle de l'élève.
6. **Progression explicite** : chaque module annonce ses objectifs, et se termine par une
   auto-évaluation et une transition vers le suivant.

### Mise en page et style

- Contenus de cours en **HTML** destiné à des **pages de leçon Moodle** (`mod_lesson`),
  copiables telles quelles.
- **N'introduis pas de nouveau CSS**, sauf nécessité que tu justifies. Utilise les classes
  déjà présentes dans le thème (réglage « Code SCSS initial ») :

| Classe | Usage |
|---|---|
| `.objectif` | encadré vert : objectifs d'un module |
| `.astuce` | encadré jaune : conseil, méthode, raccourci |
| `.danger` | encadré rouge : erreur classique, risque, sécurité |
| `.suite` | encadré bleu : transition vers la suite |
| `.formule` | formule centrée mise en valeur |
| `.comp` | pastille verte : code de compétence en ligne |
| `.carte` + `.carte-item` (+ `.icon`) | rangée de cartes (panorama, comparaison) |
| `.cdc` | encadré bleu clair : extrait de cahier des charges |
| `.rendre` | encadré vert : ce que l'étudiant doit rendre |
| `.phase` | bloc de phase d'un TP (`<strong>` pour le titre de phase) |

- `h1` pour le titre de page, `h2` pour les sections, `pre > code` pour le code.
- **Ton** : direct, tutoiement de l'étudiant, phrases courtes, vocabulaire technique
  normalisé introduit puis réutilisé. Pas de remplissage, pas d'emphase décorative.
- **Homogénéité** : aligne-toi sur le parcours de référence joint (structure des pages,
  longueur, nommage des activités, forme des consignes).

### Livrables attendus

Dans cet ordre :

1. **Carte du parcours** : tableau des modules (titre, durée, objectifs, compétences,
   activités Moodle, type d'évaluation).
2. Pour chaque module :
   - le **contenu de cours** en HTML, découpé en pages de leçon ;
   - les **activités** avec, pour chacune, une **fiche de paramétrage Moodle** : nom de
     l'activité, plugin utilisé, et **la valeur de chaque champ** telle que je n'aie plus
     qu'à la coller (y compris « Corrigé attendu / barème », « Compétences évaluées »,
     « Consignes supplémentaires » du tuteur, nombres de questions du générateur, etc.) ;
   - les **critères d'évaluation** et la répartition des points.
3. **Tableau de couverture** compétences × activités.
4. **Liste de contrôle de déploiement** : ordre de création dans Moodle, points à vérifier
   (cron, relecture du brief du tuteur, vérification des solutions CodeRunner…).

### Méthode de travail

- **Commence par me poser tes questions** sur ce qui manque ou reste ambigu, et propose une
  **carte du parcours** que je valide **avant** de rédiger les contenus. Ne produis pas les
  modules tant que je n'ai pas validé la carte.
- Puis livre **un module à la fois**, dans un fichier par module, et attends mon retour.
- Si tu vois une meilleure structure, un manque, une activité mal choisie ou une compétence
  mal couverte : **dis-le et propose**, avant de produire.
- Signale explicitement ce dont tu n'es pas sûr (une norme, une version, une valeur
  technique) plutôt que de l'affirmer.

### Spécification de ce parcours

[insérer la spécification]

---

### Pourquoi ces changements

Par rapport à ta version :
- le **public, le volume et le cadre d'évaluation** sont donnés dès le début : sans eux, le
  modèle choisit un format au hasard ;
- les **contraintes techniques** deviennent des règles numérotées, vérifiables ;
- la **fiche de paramétrage** par activité est exigée explicitement : c'est ce qui transforme
  un beau plan en parcours réellement déployable ;
- la **livraison est mise en étapes** (questions → carte validée → un module à la fois) : sur
  un parcours entier, une livraison unique donne un document trop long, difficile à corriger,
  et qui part souvent dans une direction que tu n'avais pas voulue ;
- les classes CSS sont **listées avec leur sens**, au lieu du SCSS brut : le modèle les
  réutilise correctement au lieu d'en inventer ;
- l'invitation « si tu as des questions » devient une **obligation de poser les questions
  avant de produire**.
