# Produire une vidéo sur la voix de l'enseignant (Remotion)

Méthode tirée de la vidéo M3 du parcours SécuVM (« fail2ban : la vigie de la porte 22 », 3 min,
octobre 2026). Le modèle de projet est dans `templates/video-remotion/`.

## Sommaire

1. Principe et coût
2. Le script de voix off
3. L'enregistrement, côté enseignant
4. Caler la voix mot à mot
5. Le montage Remotion
6. Le son : musique et bruitages
7. Contrôles avant le rendu final
8. Rendu, niveau sonore, livrables
9. Pièges déjà rencontrés

## 1. Principe et coût

- **Toujours sur la voix de l'enseignant**, jamais en animation muette. Une animation muette, où
  tout est écrit à l'écran, a été jugée « pas ouf ». Tu écris le script en blocs, il enregistre, tu
  cales chaque apparition sur le mot qui la nomme.
- C'est l'activité la plus coûteuse du skill. Pour 3 minutes de vidéo, il faut compter une
  demi-journée de travail et trois rendus d'environ 18 minutes. Réserve-la à un concept dynamique
  (voir `video.md`), et seulement quand l'enseignant la demande.
- Étapes : script (tu) → enregistrement (lui) → calage → scènes → son → contrôles → rendu →
  livrables. Annonce l'étape en cours : l'enseignant suit de loin.

## 2. Le script de voix off (`video/voix-off.md`)

Modèle : `templates/video-remotion/voix-off.md`.

- **Rythme réel : 2,2 à 3,5 mots par seconde.** 430 mots prévus pour 3 min 15 ont donné 2 min 32
  de parole. Prévois 15 % de moins que ton estimation, et des pauses entre les blocs au montage.
- **10 à 14 blocs de 10 à 25 secondes.** Un bloc = une idée = une scène. Nomme-les `01-accroche`,
  `02-journal`…
- **Une accroche narrative à froid**, avant le titre (« Il est trois heures du matin. Ton serveur
  dort… »). Termine par un bloc « À retenir », puis un carton qui renvoie au TP.
- Pour chaque bloc, donne le texte à dire et une ligne **« À l'écran »** : ce qui apparaît, et sur
  quel mot.
- **Les mots techniques sont exacts et non négociables** (`maxretry`, `backend = systemd`…), car ils
  déclenchent l'affichage. L'enseignant peut reformuler le reste.
- **Chaque affirmation technique est vérifiée** contre la leçon et le journal de validation. Les
  sorties montrées à l'écran sont des sorties réelles. Une généralisation de la voix (« tout ce qui
  vient de l'adresse ») se précise à l'écran (« → port 22 »).
- **Prévois où tombent les questions d'interruption** : après le bloc qui pose la notion, une
  toutes les 45 à 90 secondes, plus une en fin de vidéo.

## 3. L'enregistrement, côté enseignant

Consignes à recopier dans `voix-off.md` :

- un fichier par bloc, nommé `NN - Titre.m4a` (ou `.wav`, `.mp3`), déposé dans `video/voix/` ;
- un téléphone suffit, dans une pièce calme, à 20-30 cm du micro ;
- une demi-seconde de silence avant et après (tu recoupes toi-même) ;
- une phrase ratée ? Refaire tout le bloc.

Constaté : l'enregistreur du téléphone produit de l'AAC stéréo 48 kHz très propre (bruit de fond
vers −80 dBFS), mais avec un niveau qui varie d'un bloc à l'autre (−31 à −25 LUFS). Il y a parfois
un clic avant la première phrase. Le script de voix off a été suivi presque mot pour mot.

## 4. Caler la voix mot à mot

```
py scripts/voix/caler.py     # décode, date chaque mot → out/voix-travail/timings.json
py scripts/voix/traiter.py   # coupe, égalise → public/voix/*.wav et src/voix.json
npm run timeline             # minutage des scènes → src/timeline.json (+ temps des questions)
```

- **Le texte exact** de chaque bloc va dans `src/texte.json`, et la prononciation « à la française »
  des termes techniques dans `src/prononciation.json` (`fail2ban` → « fèle tou bane », `ssh` →
  « esse esse ache », `jail.local` → « jèle point locale »).
- **Moteur : la reconnaissance vocale française de Windows** (SAPI, `System.Speech`, reconnaisseur
  `fr-FR` déjà installé sous un Windows en français, sans téléchargement). Elle tourne en
  **grammaire contrainte** : le moteur ne peut reconnaître que les propositions du script (découpées
  à la ponctuation, répétables). Il renvoie alors la position de chaque mot, à environ 50 ms près.
  Confiance relevée : 0,84 à 0,97 par énoncé.
- **Repli automatique** : si un passage est rejeté d'un seul tenant (claquement de langue, souffle),
  le bloc est repris pause par pause. Le découpage se fait sur l'énergie, et les îlots de moins de
  80 ms sont ignorés.
- **Si le texte prononcé diffère du script**, `caler.py` s'arrête et affiche les deux versions. Tu
  corriges alors `src/texte.json`, ou l'enseignant réenregistre le bloc. Pour deviner ce qui a été
  dit, utilise la dictée libre (mode `dictee` d'`aligner.ps1`) : elle est médiocre, mais suffit à
  repérer une reformulation.
- **`traiter.py`** :
  - coupe de 0,10 s avant à 0,28 s après la parole, puis passe-haut à 80 Hz ;
  - compression 2,5:1 au-dessus de −20 dBFS, puis −17 LUFS (BS.1770) ;
  - limiteur à −1,5 dBFS et fondus.

  Les mots datés sont relatifs au fichier coupé.

## 5. Le montage Remotion

- **`src/scenes.json`** liste les scènes dans l'ordre. Pour chacune :
  - `bloc` ;
  - `lead` : pause après la transition d'entrée, environ 0,2 s ;
  - `tail` : maintien après la voix, de 0,5 à 2,4 s selon ce qui doit s'achever à l'écran ;
  - `in` : transition d'entrée (`scan`, `iris` avec `iris: [x, y]`, `cut`, `fade`, `zoom`) ;
  - `ambiance` : couleur du fond et filtre de la musique ;
  - `chapitre` : bandeau ;
  - `question` : n° de la question d'interruption posée à la fin de la scène.

  Les cartons sans voix (`titre`, `fin`) ont une durée `dur`.
- **Une scène = un fichier `src/scenes/*.tsx`.**
  - `const v = useVoice()`, puis chaque apparition à `v.at("mot")` (`v.at("mot", 2)` pour la 2e
    occurrence). Un mot absent du bloc fait échouer le rendu, ce qui évite les décalages muets.
  - Les bruitages sont déclarés dans la même scène :
    `export const sfx = (v) => [{ at: v.at("bannie"), src: "impact", volume: 0.7 }]`.
  - Une transition `cut` permet de prolonger le décor d'une scène dans la suivante (même terminal) ;
    voir `shiftLines`.
- **Le système visuel est constant d'une scène à l'autre** :
  - une palette « de sens » : rouge = attaque ou blocage, cyan = protection, ambre = réglages et
    alertes, vert = accès qui fonctionne, violet = clés ;
  - trois polices chargées par `@remotion/google-fonts` : Space Grotesk, Inter, JetBrains Mono ;
  - un fond commun animé (nappes de couleur, étoiles, grille) ;
  - un bandeau de chapitre et une barre de progression.
- **Réalisme** :
  - les fenêtres sont celles des élèves : Windows Terminal (postes Windows), console VirtualBox ;
  - les sorties de commandes viennent du journal de validation ou de la leçon ;
  - la ligne la plus longue d'un terminal doit tenir dans la fenêtre : sa largeur vaut environ
    nombre de caractères × 0,6 × taille de police.
- **Une idée visuelle forte par scène**, pas un diaporama. Exemples de la vidéo M3 :
  - une frise du temps avec une loupe sur la première minute ;
  - un schéma réseau avec un mur qui tombe ;
  - un cycle « banni → libéré → revient → recompté » ;
  - des curseurs de réglage ;
  - un écran partagé PowerShell / console.
- **Mouvement** :
  - apparitions en ressort (`pop`), déplacements sans rebond (`glide`) ;
  - secousse et tampon sur les impacts ;
  - texte qui s'écrit mot à mot (`WordsIn`) pour les phrases-clés.
- **Transitions** :
  - un balayage lumineux par défaut ;
  - un iris centré sur le sujet de la scène, à condition qu'il soit déjà visible ;
  - un zoom dans un objet pour passer de l'accroche au titre.

## 6. Le son : musique et bruitages

`npm run sons` (`scripts/sons.py`) synthétise tout : aucun échantillon externe, donc aucun droit à
gérer.

- **Musique** :
  - nappes d'accords (la m – fa – do – sol, 8 s par accord) et basse ;
  - filtre plus sombre dans les ambiances « attaque », cloches rares, pouls discret ;
  - −20 LUFS dans le fichier, jouée à 0,5, baissée à 0,2 sous la voix (`musicVolume` : attaque de
    6 images, relâche de 20).
- **Bruitages** : une trentaine (coups à la porte, impact, frappe au clavier, verrou, alerte,
  balayage…). Ils sont normalisés à −18 LUFS momentanés, si bien que les volumes des repères sont
  comparables entre eux.
- **Cibles mesurées** sur le mixage :
  - voix à −17 LUFS, musique vers −30 LUFS ;
  - bruitages 5 à 8 dB sous la voix ;
  - un impact de climax peut monter à 1-2 dB sous la voix, jamais au-dessus pendant qu'elle parle.

  Les coups de la scène d'accroche couvraient la voix de 4 dB : ils ont été baissés.

## 7. Contrôles avant le rendu final

- `npx tsc --noEmit -p .`
- **Images de contrôle** :
  - `node scripts/stills.mjs nuit:+120 comptage:+300 …` (demi-résolution) ;
  - une planche contact (Pillow) pour regarder 3 à 5 moments par scène ;
  - sur le MP4 rendu, des images extraites aux transitions avec `npx remotion ffmpeg -ss T`.
- **Mixage** :
  - trois rendus audio seuls (`--codec=wav`) : complet, voix seule (`--props` avec `musique` et
    `bruitages` à `false`), voix + bruitages ;
  - par soustraction, mesure de chaque piste scène par scène.
- **Sous-titres** : pas de ligne de plus de 42 caractères, pas de sous-titre de moins de 0,8 s,
  coupure de préférence après une virgule.

## 8. Rendu, niveau sonore, livrables

- `npm run render` : environ 18 min pour 3 min en 1080p30 (concurrence 6 sur 8 cœurs, 75 Mo).
- **Niveau final** : mesurer avec `loudnorm` (`print_format=json`), puis appliquer un gain linéaire
  (`-af volume=…dB`, vidéo copiée) vers −16,5 LUFS, sans dépasser −1 dBTP. YouTube ramène à
  −14 LUFS, mais ne remonte pas un son trop faible.
- **Livrables dans `video/`** :
  - `out/<nom>.mp4` ;
  - `out/<nom>.fr.srt` et `.vtt`, produits par `node scripts/soustitres.mjs` ;
  - `video.json`, avec les questions aux temps affichés par `npm run timeline` (fin de scène, la voix
    s'est tue) ;
  - `transcription.md` ;
  - `out/miniature.png`, le carton-titre en 1920 × 1080 ;
  - `README.md`, pour refaire ou modifier.
- **Côté enseignant** : mise en ligne YouTube non répertoriée, sous-titres importés, puis activité
  `videoqcm` créée à la main (le pont ne la crée pas). Rôle `video` de `reglages-moodle.md`.

## 9. Pièges déjà rencontrés

- **SAPI** : la position d'un mot est relative à son énoncé. Il faut y ajouter
  `RecognizedAudio.AudioPosition` de l'énoncé.
- **`py -I`** masque les paquets installés pour l'utilisateur (numpy, scipy) : ne l'utilise pas
  pour tes propres scripts.
- **Le ffmpeg de Remotion** (`npx remotion ffmpeg`) est une version réduite : `loudnorm`, `volume`,
  `silencedetect`, `aresample`, `atrim`, mais ni `highpass` ni `afftdn`. Le traitement du son se fait
  donc en Python.
- **Le son d'une `<Sequence>` est coupé quand la séquence finit.** Les bruitages sont déclarés dans
  chaque scène, mais joués à la racine de la composition.
- **Les scènes sont transparentes** au-dessus du fond commun. Pendant un balayage, la scène sortante
  reçoit le masque complémentaire de l'entrante ; sinon, les deux se superposent.
- **Les ligatures de JetBrains Mono** transforment `|-` en glyphe. Il faut
  `font-variant-ligatures: none` sur tout le document.
- **Les alignements de configuration** (`enabled  = true`) demandent `whiteSpace: "pre"`.
- **Pas d'emoji**, dont le rendu dépend du système : dessiner les icônes en SVG.
- **Une étiquette qu'on allonge** passe sur deux lignes : `whiteSpace: "nowrap"`, et une largeur
  suffisante.
- **Un flash blanc long** fait « laiteux » : il faut un flash bref (12 images au plus) en dégradé
  radial.
- **Un iris qui s'ouvre sur un fond vide** perd son effet : le sujet doit déjà être là, en veille.
- **Un exemple doit rester cohérent avec le reste du module.** « 3 fautes dans son mot de passe »
  n'a pas de sens sur un serveur à clés seules : « un nom de compte mal tapé » en a.
