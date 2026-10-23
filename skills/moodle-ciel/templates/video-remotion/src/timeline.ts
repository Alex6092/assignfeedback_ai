import React, { createContext, useContext } from "react";
import timeline from "./timeline.json";
import voix from "./voix.json";

export type SceneInfo = (typeof timeline.scenes)[number];
type Word = { w: string; s: number; e: number };

export const TL = timeline;
export const VOIX = voix as Record<string, { file: string; dur: number; words: Word[] }>;

export const SceneCtx = createContext<SceneInfo | null>(null);

export const useScene = (): SceneInfo => {
  const s = useContext(SceneCtx);
  if (!s) throw new Error("useScene hors d'une scène");
  return s;
};

const norm = (s: string) =>
  s
    .toLowerCase()
    .replace(/[«»…:,.?!;—]/g, "")
    .trim();

/**
 * Repères de la voix d'une scène, en images relatives à la scène :
 * at("bantime") = image où le mot commence à être prononcé ; at("adresse", 2) = 2e occurrence.
 */
export const voiceOf = (s: SceneInfo) => {
  const words = s.bloc ? VOIX[s.bloc].words : [];
  const find = (word: string, n: number) => {
    const target = norm(word);
    let k = 0;
    for (const w of words) {
      if (norm(w.w) === target && ++k === n) return w;
    }
    throw new Error(`Mot « ${word} » (${n}) introuvable dans ${s.bloc}`);
  };
  const toF = (sec: number) => s.voiceStart + Math.round(sec * TL.fps);
  return {
    at: (word: string, n = 1) => toF(find(word, n).s),
    end: (word: string, n = 1) => toF(find(word, n).e),
    /** Début et fin de la voix dans la scène. */
    start: s.voiceStart,
    stop: s.voiceStart + s.voiceF,
    /** Durée de la scène, et image où la transition de sortie commence. */
    dur: s.dur,
    out: s.dur - s.overlapOut,
  };
};

export type Voice = ReturnType<typeof voiceOf>;

export const useVoice = (): Voice => voiceOf(useScene());

/** Bruitage : image (relative à la scène), fichier de public/sfx (sans extension), volume. */
export type Cue = { at: number; src: string; volume?: number };

/** Scène affichée à l'image globale f (la plus récente si deux se recouvrent). */
export const sceneAt = (f: number): SceneInfo => {
  let cur = TL.scenes[0];
  for (const s of TL.scenes) if (s.start <= f) cur = s;
  return cur;
};

export const SceneProvider: React.FC<{ scene: SceneInfo; children: React.ReactNode }> = ({ scene, children }) =>
  React.createElement(SceneCtx.Provider, { value: scene }, children);
