// Calcule le minutage absolu des scènes à partir de src/scenes.json et des durées réelles des voix
// (src/voix.json). Écrit src/timeline.json, lu par Remotion (montage) et par scripts/musique.py.
// À relancer après toute modification des voix ou de scenes.json : npm run timeline
import { readFileSync, writeFileSync } from "node:fs";

const cfg = JSON.parse(readFileSync(new URL("../src/scenes.json", import.meta.url), "utf8"));
const voix = JSON.parse(readFileSync(new URL("../src/voix.json", import.meta.url), "utf8"));
const fps = cfg.fps;
// Recouvrement (images) entre une scène et la suivante, selon le type de transition d'entrée.
const OVERLAP = { none: 0, cut: 0, scan: 16, iris: 18, fade: 14, zoom: 10 };

const scenes = [];
cfg.scenes.forEach((s, i) => {
  const overlapIn = i === 0 ? 0 : OVERLAP[s.in];
  const next = cfg.scenes[i + 1];
  const overlapOut = next ? OVERLAP[next.in] : 0;
  let leadF = 0;
  let voiceF = 0;
  let tailF = 0;
  let bodyF;
  if (s.bloc) {
    if (!voix[s.bloc]) throw new Error(`bloc inconnu : ${s.bloc}`);
    leadF = Math.round(s.lead * fps);
    voiceF = Math.ceil(voix[s.bloc].dur * fps);
    tailF = Math.round(s.tail * fps);
    bodyF = leadF + voiceF + tailF;
  } else {
    bodyF = Math.round(s.dur * fps);
  }
  const prev = scenes[i - 1];
  const start = prev ? prev.start + prev.dur - overlapIn : 0;
  scenes.push({
    id: s.id,
    bloc: s.bloc ?? null,
    in: s.in,
    ambiance: s.ambiance,
    chapitre: s.chapitre ?? null,
    question: s.question ?? null,
    iris: s.iris ?? null,
    start,
    dur: overlapIn + bodyF + overlapOut,
    overlapIn,
    overlapOut,
    voiceStart: overlapIn + leadF,
    voiceF,
  });
});
const last = scenes[scenes.length - 1];
const total = last.start + last.dur;
writeFileSync(
  new URL("../src/timeline.json", import.meta.url),
  JSON.stringify({ fps, total, scenes }, null, 1) + "\n",
);
const t = (f) => `${Math.floor(f / fps / 60)}:${String(Math.floor((f / fps) % 60)).padStart(2, "0")}`;
for (const s of scenes) {
  const q = s.question ? `  ← question ${s.question} à ${((s.start + s.dur - s.overlapOut) / fps).toFixed(1)} s` : "";
  console.log(`${s.id.padEnd(11)} ${t(s.start)}  ${(s.dur / fps).toFixed(2).padStart(6)} s  voix à +${(s.voiceStart / fps).toFixed(2)} s${q}`);
}
console.log(`total : ${total} images = ${(total / fps).toFixed(2)} s (${t(total)})`);
