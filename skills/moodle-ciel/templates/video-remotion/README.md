# Modèle de vidéo Remotion sur la voix de l'enseignant

C'est le moteur de la vidéo M3 de SécuVM (« fail2ban : la vigie de la porte 22 »), rendu générique.
La méthode complète est dans `references/video-production.md` du skill. Les scènes de la vidéo M3 sont
dans `templates/exemples-video/fail2ban-M3/`, à imiter.

## Démarrer une vidéo

1. Copier ce dossier dans `Mx-sujet/video/` du parcours, puis `npm install`. Il faut Node.js 18 ou
   plus, et Python 3 avec `numpy`, `scipy` et `soundfile`.
2. Écrire `voix-off.md` (gabarit fourni) et le faire valider. L'enseignant enregistre ses blocs dans
   `voix/` (`01 - Accroche.m4a`…).
3. Reporter le texte prononcé dans `src/texte.json` (clés `01-accroche`… = numéro + titre du fichier,
   sans accents) et compléter `src/prononciation.json` pour les termes techniques.
4. Caler et préparer la voix :
   `py scripts/voix/caler.py`, puis `py scripts/voix/traiter.py`, puis `npm run timeline`.
5. Décrire les scènes dans `src/scenes.json`, et écrire une scène par bloc dans `src/scenes/`
   (modèle : `Exemple.tsx` ; `Karaoke.tsx` en attendant la vraie scène). Les enregistrer dans
   `src/scenes/index.ts`, puis adapter les textes de `Titre.tsx` et `Fin.tsx`.
6. `npm run sons` (musique et bruitages, ≈ 4 min), puis `npx remotion studio` pour l'aperçu.
7. Contrôler avec `npx tsc --noEmit -p .` et `node scripts/stills.mjs scene:+N` (images dans
   `out/check/`). Mesurer le mixage par des rendus audio seuls (`--codec=wav`).
8. `npm run render` produit `out/video-brut.mp4`. Mesurer avec `loudnorm`, puis appliquer le gain
   (`-af volume=…dB`) vers −16,5 LUFS et −1 dBTP au plus : `out/<nom>.mp4`.
9. `node scripts/soustitres.mjs` (régler `NOM`, `TITRE` et les cartons en tête du fichier) produit
   les sous-titres et `transcription.md`. Reporter les temps des questions affichés par
   `npm run timeline` dans `video.json`.

Les fichiers d'exemple (`src/texte.json`, `src/voix.json`, `src/timeline.json` et les silences de
`public/voix/`) permettent de lancer le modèle tel quel. Ils sont remplacés par les vrais dès l'étape 4.

## Où sont les choses

| Fichier | Rôle |
|---|---|
| `src/scenes.json` | ordre des scènes ; pauses avant (`lead`) et après (`tail`) la voix ; transition (`in`, `iris`) ; `ambiance` ; `chapitre` ; `question` |
| `src/timeline.ts` | `useVoice()` : `v.at("mot")`, `v.end("mot")`, `v.start`, `v.stop`, `v.out` (images relatives à la scène) |
| `src/Video.tsx` | montage : fond commun, scènes, bandeau, voix, musique baissée sous la voix, bruitages |
| `src/theme.ts` | palette « de sens » et polices |
| `src/anim.ts` | `prog`, `pop`, `glide`, `keys`, `shake`, `typed`, `scramble`… |
| `src/components/` | fond animé, bandeau de chapitres, transitions (`SceneShell`), fenêtres Windows Terminal et VirtualBox, texte de terminal (`TermLines`), illustrations (serveur, robot, vigie, poste, cadenas…), `ui` (pastille, tampon, flèche animée, flash…) |
| `scripts/voix/` | calage par la reconnaissance vocale de Windows (`caler.py`, `aligner.ps1`, `Aligner.cs`) et traitement des voix (`traiter.py`) |
| `scripts/sons.py` | musique (nappes selon l'ambiance des scènes) et une trentaine de bruitages synthétisés |
| `scripts/soustitres.mjs` | sous-titres SRT et VTT, et transcription horodatée |
| `scripts/stills.mjs` | images de contrôle (`scene:+N`) |
