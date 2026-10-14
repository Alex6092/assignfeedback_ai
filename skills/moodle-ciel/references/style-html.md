# HTML des contenus : leçons, consignes, page d'accueil

Le HTML est collé (ou déployé) dans l'éditeur de Moodle. Il prend la mise en forme du **thème**
(réglage « Code SCSS initial » du thème Boost). Une classe absente du thème ne s'affiche pas ;
un `<style>` ou un `<script>` dans le contenu est supprimé ou ignoré.

## Sommaire

1. Fichiers et marqueurs
2. Classes du thème
3. Balises, styles en ligne, images, liens
4. Anatomie d'une page de leçon
5. Ton et écriture
6. Consigne de TP et page d'accueil

## 1. Fichiers et marqueurs

- `lecon.html` est un document complet, ouvrable dans un navigateur : il contient le bloc `<style>`
  d'**aperçu** du gabarit (copie des règles du thème), qui n'est jamais déployé.
- Le contenu déployé est **uniquement** ce qui est entre les marqueurs :
  ```html
  <!-- ▶ DÉBUT CONTENU MOODLE ◀ -->
  …une page de leçon…
  <!-- ▶ FIN CONTENU MOODLE ◀ -->
  ```
  Un bloc de marqueurs = une page de leçon. Les blocs sont placés dans
  `<div class="contents"><div class="no-overflow">` pour que l'aperçu ressemble à Moodle ; ces
  conteneurs, les commentaires enseignant et le `<style>` restent hors des marqueurs.
- Le **titre de la page** dans Moodle est le texte du `<h1>` de la page, sans l'emoji de tête ni le
  préfixe « Module n — ». Exemple : `<h1>🗺️ Module 2 — Reconnaissance &amp; cartographie (Nmap)</h1>`
  donne la page « Reconnaissance & cartographie (Nmap) ».

## 2. Classes du thème

Vérifie la liste avec `moodle_site_info` (`theme.cielclasses` et `theme.missingclasses`).

| Classe | Rendu | Usage |
|---|---|---|
| `objectif` | encadré vert | objectifs du module (page 1), récapitulatif « ✅ À retenir » |
| `astuce` | encadré jaune | conseil, méthode, analogie, raccourci (💡) |
| `danger` | encadré rouge | piège classique, risque, sécurité, cadre légal (⚠️) |
| `suite` | encadré bleu | transition vers la page suivante ou le TP (➡️), exercice « À toi ! » |
| `formule` | bloc centré, gras | formule, règle à retenir, commande clé |
| `comp` | pastille verte en ligne | code de compétence : `<span class="comp">C04</span>` |
| `carte` + `carte-item` + `icon` | rangée de cartes centrées | panorama, comparaison, étapes |
| `cdc` | encadré bleu clair | extrait de cahier des charges, périmètre, contexte client (📄) |
| `rendre` | encadré vert | ce que l'élève doit rendre (📤) |
| `phase` | bloc à bord vert, titre en gras vert | étape d'un TP (`<strong>Phase 1 — …</strong>`) |
| `install` | encadré indigo | procédure d'installation ou de mise en place du labo |
| `sev-crit`, `sev-high`, `sev-med`, `sev-low` | texte coloré gras | gravité (CVSS, risque) |
| `modcode` | code de module en vert | `M2` dans la carte du parcours (page d'accueil) |
| `capture` | encadré gris à bord pointillé | **capture d'écran suggérée**, à faire par l'enseignant (📸, voir § 3) |

`install`, `sev-*` et `modcode` ont été ajoutées au SCSS du thème en octobre 2026. Si
`missingclasses` les liste encore, ne les utilise pas et signale-le.

`capture` (reprise des leçons du parcours C++) n'est pas encore dans le SCSS du thème : sans elle,
l'encadré s'affiche sans mise en forme mais reste lisible. Règle à ajouter au SCSS (Administration
du site → Présentation → Thèmes → Boost → Réglages avancés), à rappeler à l'enseignant :

```css
.capture{background:#f8fafc;border:2px dashed #94a3b8;border-radius:8px;padding:.7rem 1rem;margin:1rem 0;color:#475569;font-size:.92em}
.capture strong{color:#334155}
```

Classes Moodle/Bootstrap utilisables : `img-fluid` (image adaptative), `table` n'est pas
nécessaire (le thème met en forme les tableaux des contenus).

**Cartes** : l'icône est un `div` à part, le texte dans un `p` :
```html
<div class="carte">
  <div class="carte-item"><div class="icon">🔍</div><p><strong>Analyste</strong><br>tu cartographies la cible</p></div>
  <div class="carte-item"><div class="icon">💥</div><p><strong>Pentester</strong><br>tu démontres les failles</p></div>
</div>
```
Pas de `<center>` ni de `<br>` autour de l'icône : le thème la centre et l'espace.

## 3. Balises, styles en ligne, images, liens

- **Balises** : `h1` (un seul par page), `h2` (sections), `h3`, `p`, `ul`/`ol`/`li`, `strong`,
  `em`, `code`, `pre><code`, `table`/`thead`/`tbody`/`tr`/`th`/`td`, `br` (avec parcimonie), `a`,
  `img`, `div` et `span` porteurs des classes du thème.
- **Interdit** : `<script>`, `<style>`, `<iframe>`, `<center>`, `<font>`, attributs `on…`, CSS
  externe, classes inventées.
- **Code** : dans `<pre><code>`, échappe `<`, `>` et `&` (`2&gt;/dev/null`, `&lt;?php`). Commente
  le code en français. `class="language-python"` sur le `pre` est accepté.
- **Styles en ligne** : à éviter. Deux usages sont admis :
  - centrer une image : `<p style="text-align: center;"><img class="img-fluid" …></p>` ;
  - surligner la partie variable d'une commande dans un `pre` :
    `<span style="color: #fca5a5;">[ce que tu tapes]</span>`.
- **Images** (captures de résultats, schémas) : place l'image dans `images/` à côté de
  `lecon.html`, et référence-la en relatif (`src="images/<nom>.png"`). Le déploiement l'envoie avec
  la page de leçon qui la cite : Moodle la range dans la zone de fichiers de cette page et le `src`
  devient `@@PLUGINFILE@@/<nom>.png`. Règles, vérifiées par `check-module.mjs` et par le pont :
  - formats PNG, JPG, GIF ou SVG (sans script) ; **2 Mo au plus par image, 4 Mo et 40 images au plus
    par leçon** (une capture trop lourde se réduit ou passe en JPG) ;
  - nom en lettres, chiffres, point, tiret et tiret bas (pas d'espace) ;
  - Moodle range les images sous leur **seul nom** : deux fichiers différents de même nom dans une
    leçon (`images/a/x.png` et `images/b/x.png`) sont refusés ;
  - une image dans un commentaire HTML n'est pas envoyée.

  Hors leçon (page, étiquette, consigne de TP), une image en relatif **n'est pas envoyée** : préfère
  la mettre dans la leçon ; sinon, l'enseignant la dépose dans l'éditeur après le déploiement
  (signale-le dans la liste de fin de déploiement). Dans une question (TD, test), l'image est
  embarquée dans le Moodle XML (`files` de `build-questions.mjs`). Toujours un `alt` descriptif.
- **Captures d'écran suggérées** : quand une capture aiderait l'élève et que tu ne peux pas la
  produire (fenêtre d'un logiciel, console après l'exécution d'un exemple, étape d'une procédure
  d'installation, message d'erreur, trame dans Wireshark, IHM obtenue), place à l'endroit voulu un
  indicateur **visible**, comme dans les leçons du parcours C++ :
  ```html
  <div class="capture">
    📸 <strong>Capture suggérée :</strong> la console exécutant ce menu, avec plusieurs choix
    enchaînés par l'utilisateur (1, puis 2, puis 0 pour quitter).
  </div>
  ```
  Dis précisément **ce que la capture doit montrer** (quelle fenêtre, quel état, quelles valeurs),
  pour que l'enseignant la refasse sans relire tout le contexte. Une capture n'a sa place que si
  elle apporte quelque chose que le texte ou un bloc de code ne montre pas : ne mets pas un
  indicateur par paragraphe (1 à 3 par leçon, en général).
  Tant qu'une leçon contient des captures suggérées, son **nom** dans le manifeste se termine par
  **` (TODO : Captures)`** (exemple : `Premiers pas avec Qt (TODO : Captures)`) : l'enseignant voit
  dans le cours ce qui reste à faire. Il remplace chaque encadré par la capture (image centrée,
  `img-fluid`, `alt`), puis retire la mention du nom. `check-module.mjs` compte les indicateurs et
  vérifie que le nom porte la mention, et seulement dans ce cas.
- **Diagrammes** (UML, SysML, architecture, maquette de fenêtre) : toujours **PlantUML** (voir
  ci-dessous), jamais un dessin à la main ni des boîtes en caractères (`+---+`, `┌─┐`, `──▶`).
- **Liens vers une autre activité du parcours** (jeu de données, TP) : `href="@@CM:clé@@"`, où
  `clé` est la `key` de l'activité dans le manifeste ; le déploiement la remplace par l'URL réelle
  (`…/mod/<type>/view.php?id=<cmid>`). Une activité déjà dans le cours, hors du manifeste (la leçon
  d'un module déployé avant, pour un `td.deploy.json`) : `href="@@CM:<cmid>@@"`. L'ordre compte :
  - une **leçon** ou le **résumé de la section** peuvent viser n'importe quelle activité du manifeste
    (leur contenu part en fin de déploiement quand la cible est créée après eux) ;
  - une **page**, une **étiquette** ou une **consigne de TP** ne visent qu'une activité placée **plus
    haut** dans le manifeste : leur contenu part à leur création. Pour une étiquette en tête de
    section qui renvoie vers la suite, place-la après ses cibles dans le manifeste, et en tête de
    section avec `before` ;
  - un lien vers une activité que le pont ne crée pas (vidéo, fichier) reste tel quel, à remplacer à
    la main (signalé par `check-module.mjs` et dans le rapport du déploiement).
- **Texte dans un `pre`** : une sortie console (même encadrée de `+---+` par le programme), une
  arborescence de fichiers. Une chaîne d'étapes tient dans une phrase (« reconnaissance → énumération
  → … ») ; dès qu'il faut des boîtes, des lignes de vie ou plusieurs lignes de flèches, c'est un
  diagramme PlantUML.
- **Accessibilité** : pas d'information portée par la seule couleur (la gravité est aussi écrite),
  `alt` sur les images, en-têtes de tableau en `th`.

### Diagrammes UML et SysML avec PlantUML

Dès qu'une leçon, un TD, une question ou une consigne montre un diagramme (cas d'utilisation,
classes, séquence, états, activité, déploiement ; exigences et blocs SysML par les stéréotypes
`<<requirement>>` et `<<block>>` ; architecture réseau en `node` ; maquette de fenêtre avec le module
**Salt**), il s'écrit en PlantUML, comme les diagrammes des sujets de projet :

1. **Source** : `images/<nom>.puml` dans le dossier du module, qui commence par
   `!include ../../_commun/plantuml-style.iuml` (copie de `templates/plantuml/style.iuml` à la racine
   du parcours, dans `_commun/` : même allure dans tout le parcours). Modèle :
   `templates/plantuml/exemple-classes.puml`, `exemple-sequence.puml`, `exemple-maquette.puml` (Salt).
   Écris les `.puml` avec l'outil d'écriture de fichiers,
   pas par un heredoc du shell : un `\n` dans un libellé y deviendrait un vrai retour à la ligne.
2. **Image** : `java -jar plantuml.jar -tpng images/<nom>.puml` produit `images/<nom>.png` (le jar
   GPL v2 de PlantUML embarque Graphviz ; demande à l'enseignant où il est, ou utilise la commande
   `plantuml` si elle existe). Garde la source à côté de l'image : on corrige le `.puml`, puis on
   régénère.
3. **Dans la leçon** : `<p style="text-align: center;"><img class="img-fluid" src="images/<nom>.png"
   alt="Diagramme de classes : …"></p>`, avec un `alt` qui dit ce que montre le diagramme, et le
   texte de la leçon qui le commente (le diagramme ne remplace pas l'explication). Le PNG part avec
   la page au déploiement ; le `.puml`, qui n'est cité par aucune balise, reste sur le disque. **Dans une
   question** (TD, test) : `files: { '<nom>.png': 'images/<nom>.png' }` et
   `<img src="@@PLUGINFILE@@/<nom>.png" alt="…">` dans l'énoncé, ou dans le HTML d'une réponse de QCM
   (chaque image part avec le texte qui l'appelle ; l'`alt` d'une réponse décrit sans trahir la bonne).
   **Dans une consigne de devoir** : le pont n'envoie pas les images ; le rapport du déploiement les
   liste, à déposer à la main dans l'éditeur. Préfère un sujet qui n'en a pas besoin.
4. **Lisibilité** : 900 px de large au plus pour une page Moodle (`scale` dans le `.puml` si besoin),
   texte en français, noms de classes et de méthodes **exactement** ceux du code montré.

Pièges connus : une étiquette de lien entièrement entre guillemets (« … » ou «USB») est prise pour un
stéréotype et perd ses guillemets, voire disparaît (fais-la commencer par un mot) ; un diagramme trop
large passe en `left to right direction` ou empile ses paquetages avec des liens cachés
(`-[hidden]->`) ; `.[norank].>` sort un lien du calcul du placement ; `hide empty members` évite les
boîtes vides d'un diagramme qui ne montre que des relations ; `hide footbox` allège une séquence. Une
maquette Salt s'écrit `@startuml` / `scale 1.5` / `salt` / `{^"Titre" … }` (le style commun n'y
s'applique pas) : `[ Bouton ]`, `"champ    "`, `{# lignes }` pour une liste, `|` entre colonnes.

### Faire produire un diagramme par l'élève

Jamais « dessine en texte » (boîtes en caractères dans un fichier `.txt` ou une zone de réponse) :
c'est laborieux, et le correcteur lit mal un dessin approximatif. Selon l'activité :

- **La leçon apprend l'outil** dès qu'un diagramme est attendu de l'élève : la source PlantUML d'un
  des diagrammes montrés, un petit tableau « pour… on écrit… » (`class X { - a : int }`,
  `A *-- "0..*" B`, `Mere <|-- Fille`, `A -> B : message`) et un éditeur en ligne pour voir le dessin
  et l'enregistrer en PNG (PlantText, planttext.com, par exemple ; vérifie avec l'enseignant que le
  site est accessible en salle).
- **Devoir (TP)** : l'élève rend une **image** (`uml.png` ; PNG, JPG ou PDF), écrite en PlantUML ou
  faite avec un autre outil. Ajoute ces extensions à `filetypes` et mets `vision: true`
  (`meta.vision` dans `tp-evalues.mjs`) : la Correction IA lit alors l'image. Avec une remise en texte
  en ligne, il colle la source PlantUML ou insère l'image (bouton Image de l'éditeur), et `vision: true`
  aussi.
- **Question corrigée par IA** (TD, test, exercices) : le correcteur des tests ne reçoit que le
  texte, pas les pièces jointes ; l'élève écrit la **source PlantUML** dans sa réponse. Le barème ne
  retire rien pour une petite faute de syntaxe si le modèle est clair, et accepte une description en
  liste en dernier recours.
- **Corrigé** (attendu d'une question IA, `fichiers` d'un TP) : la source PlantUML du diagramme
  attendu (`'uml.puml': \`@startuml …\``), suivie d'une ligne « Lecture : … » qui dit le sens des
  relations. Vérifie qu'elle se dessine (`java -jar plantuml.jar` sur un extrait) avant de livrer.

## 4. Anatomie d'une page de leçon

**Page 1** (ou page unique) : l'encadré `objectif` suit l'un des deux modèles de l'enseignant.
Choisis-le **pour tout le parcours**, d'après sa présentation, et note-le dans la carte :

- **Parcours de métier ou de labo** (Cyber : une même cible, des rôles, un rapport qui avance) : la
  compétence travaillée et la situation dans le parcours comptent pour l'élève.
  ```html
  <h1>🗺️ Module 2 — Reconnaissance &amp; cartographie (Nmap)</h1>
  <div class="objectif">
    <strong>🎯 Ce que tu vas maîtriser :</strong> …<br>
    <strong>🛠 Au menu :</strong> …<br>
    <strong>🎓 Compétence travaillée :</strong> <span class="comp">C04</span> Analyser un système informatique
  </div>
  <h2>Où on en est</h2>
  <p>…</p>
  <pre><code>Reconnaissance ──▶ Énumération ──▶ Vulnérabilités ──▶ … (tu es ici)</code></pre>
  ```
- **Parcours de programmation** (IA : chaque module ajoute du code et un jeu de données) : les
  outils et les données du module comptent pour l'élève.
  ```html
  <h1>🔢 Module 5 — Deep learning &amp; reconnaissance d'images</h1>
  <div class="objectif">
    <strong>🎯 Ce que tu vas maîtriser :</strong> …<br>
    <strong>🐍 Python au menu :</strong> Keras (<code>Sequential</code>, <code>Dense</code>, <code>fit</code>)<br>
    <strong>📦 Données :</strong> MNIST
  </div>
  ```

**Pas de durée dans la leçon** (ni « ⏱ Durée estimée », ni durée de lecture) : un élève qui lit
« 3 h » se croit large et prend son temps. La durée va dans la fiche enseignant et dans la carte.
Les leçons existantes l'affichent encore (exemple `lecon-M2-moodle.html`) : ne la reprends pas.

**Corps** : des `h2` numérotés ou titrés, des paragraphes courts, un exemple concret par notion,
du code commenté, des encadrés `astuce` / `danger` là où ils servent. Les exercices non notés :
```html
<div class="suite"><strong>➡️ À toi ! — Lire l'état d'un port</strong><br><br>
Consigne courte et précise.<br><em>Indice : …</em><br><strong>Résultat attendu :</strong> …</div>
```

**Dernière page** (ou fin de la page unique) :
```html
<div class="objectif"><strong>✅ À retenir :</strong>
<ul><li>…</li><li>…</li></ul></div>
<div class="suite">➡️ <strong>Et maintenant :</strong> le QCM d'entraînement, puis le TP 2.</div>
```
Dans une leçon de plusieurs pages, chaque page se termine par un `suite` qui annonce la suivante.

## 5. Ton et écriture

- Tutoiement, phrases courtes, voix active. Pas de remplissage ni d'emphase décorative.
- Le vocabulaire technique normalisé est introduit (en gras, défini) puis réutilisé tel quel.
- Un emoji en tête d'encadré ou de titre, comme repère visuel : 🎯 🛠 🎓 🐍 📦 💡 ⚠️ ➡️ ✅ 📤 📄.
- Concret : la même cible ou la même entreprise fictive tout au long du parcours donne un fil.
- Dans un parcours de sécurité offensive, le cadre légal et le périmètre sont rappelés à chaque
  manipulation risquée (encadré `danger`).

## 6. Consigne de TP et page d'accueil

**Consigne de TP** (champ « Instructions de l'activité », modèles dans `templates/tp/`) :
```html
<p><strong>Mission.</strong> … Objectif : … </p>
<div class="cdc"><strong>📄 Périmètre.</strong> …</div>
<div class="phase"><strong>Phase 1 — …</strong> …</div>
<div class="phase"><strong>Phase 2 — …</strong> …</div>
<div class="rendre"><strong>📤 À rendre :</strong><ul><li>…</li></ul></div>
<p><strong>Rappel :</strong> …</p>
```

**Page d'accueil** (section 0) : la mission du parcours en deux phrases, la carte des modules
(cartes ou tableau avec `modcode` et pastilles `comp`), « Comment on travaille » (leçon, entraînement,
TP, tuteur, test en classe), le matériel, les règles (encadré `danger` pour le cadre légal), comment
on est évalué (EFE, compétences).
