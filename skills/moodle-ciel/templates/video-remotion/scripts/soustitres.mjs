// Sous-titres (SRT et VTT) et transcription horodatée, à partir du minutage réel des mots.
// Usage : node scripts/soustitres.mjs   → out/<NOM>.fr.srt, out/<NOM>.fr.vtt, transcription.md
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";

// À adapter pour chaque vidéo.
const NOM = "video";
const TITRE = "Titre de la vidéo";
const CARTON_TITRE = "« Titre » — sous-titre affiché sur le carton-titre.";
const CARTON_FIN = "« À toi de jouer ! » — renvoi vers le TP.";

const read = (p) => JSON.parse(readFileSync(new URL(p, import.meta.url), "utf8"));
const tl = read("../src/timeline.json");
const voix = read("../src/voix.json");
const texte = read("../src/texte.json");
const fps = tl.fps;
const PUNCT = /^[«»—:?!;…,.]+$/;

// Mots affichés (avec leur ponctuation), datés en secondes depuis le début de la vidéo.
const blocs = [];
for (const s of tl.scenes) {
  if (!s.bloc) continue;
  const t0 = (s.start + s.voiceStart) / fps;
  const timed = voix[s.bloc].words;
  const out = [];
  let k = 0;
  let pendingOpen = "";
  for (const tok of texte[s.bloc].split(/\s+/)) {
    if (tok === "«") {
      pendingOpen = "« ";
      continue;
    }
    if (PUNCT.test(tok)) {
      if (out.length) out[out.length - 1].w += tok === "," || tok === "." || tok === "…" ? tok : ` ${tok}`;
      continue;
    }
    const w = timed[k++];
    if (!w) throw new Error(`${s.bloc} : plus de mots datés que de mots dans le texte`);
    out.push({ w: pendingOpen + tok, s: t0 + w.s, e: t0 + w.e });
    pendingOpen = "";
  }
  if (k !== timed.length) throw new Error(`${s.bloc} : ${timed.length - k} mot(s) daté(s) non utilisé(s)`);
  blocs.push({ scene: s, t0, words: out });
}

// Découpage en sous-titres : 2 lignes de 42 caractères au plus, 6 s au plus, coupure aux pauses.
const cues = [];
for (const b of blocs) {
  let cur = [];
  const flush = () => {
    if (cur.length) cues.push({ s: cur[0].s, e: cur[cur.length - 1].e, words: cur.map((x) => x.w) });
    cur = [];
  };
  b.words.forEach((w, i) => {
    const text = [...cur.map((x) => x.w), w.w].join(" ");
    const prev = cur[cur.length - 1];
    if (cur.length && prev && w.s - prev.e > 0.7 && /[.?!…:]$/.test(prev.w)) flush();
    else if (cur.length && (text.length > 80 || w.e - cur[0].s > 6)) {
      // Trop long : on coupe plutôt après la dernière virgule (ou deux-points) du sous-titre en cours.
      let k = -1;
      for (let j = cur.length - 2; j >= 2; j--) {
        if (/[,:;]$/.test(cur[j].w)) {
          k = j;
          break;
        }
      }
      if (k >= 0) {
        const rest = cur.slice(k + 1);
        cur = cur.slice(0, k + 1);
        flush();
        cur = rest;
      } else flush();
    }
    cur.push(w);
    if (/[.?!…]$/.test(w.w) && [...cur.map((x) => x.w)].join(" ").length > 30) flush();
    if (i === b.words.length - 1) flush();
  });
}
for (let i = 0; i < cues.length; i++) {
  const next = cues[i + 1];
  cues[i].e = Math.min(cues[i].e + 0.25, next ? next.s - 0.04 : Infinity);
}
const twoLines = (words) => {
  const all = words.join(" ");
  if (all.length <= 42) return all;
  let best = 1;
  let bestGap = Infinity;
  for (let i = 1; i < words.length; i++) {
    const a = words.slice(0, i).join(" ").length;
    // Équilibre des deux lignes, avec une préférence pour couper après une ponctuation.
    const gap = Math.abs(a - (all.length - a - 1)) - (/[.,:;?!…]$/.test(words[i - 1]) ? 14 : 0) + (Math.max(a, all.length - a - 1) > 42 ? 100 : 0);
    if (gap < bestGap) {
      bestGap = gap;
      best = i;
    }
  }
  return `${words.slice(0, best).join(" ")}\n${words.slice(best).join(" ")}`;
};
const stamp = (t, sep) => {
  const ms = Math.round(t * 1000);
  const h = String(Math.floor(ms / 3600000)).padStart(2, "0");
  const m = String(Math.floor((ms % 3600000) / 60000)).padStart(2, "0");
  const s = String(Math.floor((ms % 60000) / 1000)).padStart(2, "0");
  return `${h}:${m}:${s}${sep}${String(ms % 1000).padStart(3, "0")}`;
};
mkdirSync(new URL("../out/", import.meta.url), { recursive: true });
writeFileSync(
  new URL(`../out/${NOM}.fr.srt`, import.meta.url),
  cues.map((c, i) => `${i + 1}\n${stamp(c.s, ",")} --> ${stamp(c.e, ",")}\n${twoLines(c.words)}\n`).join("\n"),
);
writeFileSync(
  new URL(`../out/${NOM}.fr.vtt`, import.meta.url),
  "WEBVTT\n\n" + cues.map((c) => `${stamp(c.s, ".")} --> ${stamp(c.e, ".")}\n${twoLines(c.words)}\n`).join("\n"),
);

// Transcription horodatée (pour le plugin videoqcm et pour relire le texte réellement dit).
const mmss = (t) => `${Math.floor(t / 60)}:${String(Math.floor(t % 60)).padStart(2, "0")}`;
const total = tl.total / fps;
const titre = tl.scenes.find((s) => s.id === "titre");
const fin = tl.scenes.find((s) => s.id === "fin");
const qs = tl.scenes.filter((s) => s.question).map((s) => `${s.question} : ${s.id === "fin" ? "fin" : mmss((s.start + s.dur - s.overlapOut) / fps)}`);
let md = `# Transcription horodatée — « ${TITRE} » (${mmss(total)})\n\n`;
md += "Voix de l'enseignant, animation calée mot à mot. Ce texte est celui réellement prononcé\n";
md += `(vérifié par reconnaissance vocale). Sous-titres : \`out/${NOM}.fr.srt\`\n`;
md += `(ou \`.vtt\`), à importer avec la vidéo. Questions d'interruption : ${qs.join(" ; ")}.\n`;
let titreFait = false;
for (const b of blocs) {
  const s = b.scene;
  if (titre && !titreFait && s.start > titre.start) {
    md += `\n## ${mmss(titre.start / fps)} · Titre\n\n${CARTON_TITRE}\n`;
    titreFait = true;
  }
  md += `\n## ${mmss(b.t0)} · ${s.chapitre ?? "Ouverture"}\n\n${texte[s.bloc]}\n`;
}
if (fin) md += `\n## ${mmss(fin.start / fps)} · Fin\n\n${CARTON_FIN}\n`;
writeFileSync(new URL("../transcription.md", import.meta.url), md);
console.log(`${cues.length} sous-titres ; transcription.md écrite ; questions : ${qs.join(", ")}`);
