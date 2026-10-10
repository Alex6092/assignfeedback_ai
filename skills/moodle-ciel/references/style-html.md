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

`install`, `sev-*` et `modcode` ont été ajoutées au SCSS du thème en octobre 2026. Si
`missingclasses` les liste encore, ne les utilise pas et signale-le.

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
  `lecon.html`, et référence-la en relatif ; le déploiement l'envoie dans Moodle. Si l'image reste
  à produire par l'enseignant (capture d'un résultat réel), laisse un commentaire
  `<!-- IMAGE À FOURNIR : … -->` à l'endroit voulu et signale-le. Toujours un `alt` descriptif.
- **Liens vers une autre activité du parcours** (jeu de données, TP) : `href="@@CM:clé@@"`, où
  `clé` est la `key` de l'activité dans le manifeste ; le déploiement la remplace par l'URL réelle.
- **Schémas ASCII** dans un `pre` : bienvenus pour une chaîne, un flux, une arborescence
  (`Reconnaissance ──▶ Énumération ──▶ … (tu es ici)`).
- **Accessibilité** : pas d'information portée par la seule couleur (la gravité est aussi écrite),
  `alt` sur les images, en-têtes de tableau en `th`.

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
