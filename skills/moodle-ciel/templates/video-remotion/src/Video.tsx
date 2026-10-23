import React from "react";
import { AbsoluteFill, Html5Audio, Sequence, interpolate, staticFile } from "remotion";
import { Background } from "./components/Background";
import { Hud } from "./components/Hud";
import { SceneShell } from "./components/SceneShell";
import { SCENES } from "./scenes";
import { Cue, TL, VOIX, voiceOf } from "./timeline";

// Plages où la voix parle (images absolues), pour baisser la musique sous la voix.
const SPEECH: [number, number][] = [];
for (const s of TL.scenes) {
  if (!s.bloc) continue;
  for (const w of VOIX[s.bloc].words) {
    const a = s.start + s.voiceStart + Math.round(w.s * TL.fps);
    const b = s.start + s.voiceStart + Math.round(w.e * TL.fps);
    const last = SPEECH[SPEECH.length - 1];
    if (last && a - last[1] < 12) last[1] = Math.max(last[1], b);
    else SPEECH.push([a, b]);
  }
}

/** Volume de la musique : plein entre les phrases, baissé sous la voix (attaque 6 images, relâche 20). */
const musicVolume = (f: number) => {
  let duck = 0;
  for (const [a, b] of SPEECH) {
    if (f < a - 6) break;
    const k = f < a ? (f - (a - 6)) / 6 : f <= b ? 1 : Math.max(0, 1 - (f - b) / 20);
    duck = Math.max(duck, k);
  }
  const fadeOut = interpolate(f, [TL.total - 40, TL.total], [1, 0], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  return (0.5 - 0.3 * duck) * fadeOut;
};

const CUES: { at: number; src: string; volume: number }[] = TL.scenes.flatMap((s) => {
  const mod = SCENES[s.id];
  if (!mod.sfx) return [];
  return mod.sfx(voiceOf(s)).map((c: Cue) => ({ at: s.start + c.at, src: c.src, volume: c.volume ?? 0.6 }));
});

export const Video: React.FC<{ musique: boolean; bruitages: boolean }> = ({ musique, bruitages }) => (
  <AbsoluteFill style={{ background: "#03060C" }}>
    {/* Pas de ligatures : « |- » et « -> » doivent s'afficher comme dans un vrai terminal. */}
    <style>{"*{font-variant-ligatures:none;font-feature-settings:\"liga\" 0,\"calt\" 0;}"}</style>
    <Background />
    {TL.scenes.map((s) => {
      const Comp = SCENES[s.id].Scene;
      return (
        <Sequence key={s.id} from={s.start} durationInFrames={s.dur} name={s.id} premountFor={30}>
          <SceneShell scene={s}>
            <Comp />
          </SceneShell>
        </Sequence>
      );
    })}
    <Hud />

    {/* Voix de l'enseignant, un fichier par bloc */}
    {TL.scenes
      .filter((s) => s.bloc)
      .map((s) => (
        <Sequence key={`v-${s.id}`} from={s.start + s.voiceStart} name={`voix ${s.bloc}`} layout="none">
          <Html5Audio src={staticFile(VOIX[s.bloc as string].file)} />
        </Sequence>
      ))}
    {musique ? <Html5Audio src={staticFile("musique.wav")} volume={musicVolume} /> : null}
    {bruitages
      ? CUES.map((c, i) => (
          <Sequence key={`sfx-${i}`} from={Math.max(0, c.at)} name={`sfx ${c.src}`} layout="none">
            <Html5Audio src={staticFile(`sfx/${c.src}.wav`)} volume={c.volume} />
          </Sequence>
        ))
      : null}
  </AbsoluteFill>
);
