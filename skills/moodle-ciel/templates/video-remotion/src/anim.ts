import type React from "react";
import { Easing, interpolate, random, spring } from "remotion";

export const FPS = 30;

const CLAMP = { extrapolateLeft: "clamp", extrapolateRight: "clamp" } as const;

/** Progression 0 → 1 entre les images a et b (ease-out par défaut). */
export const prog = (f: number, a: number, b: number, easing = Easing.out(Easing.cubic)) =>
  b <= a ? (f >= a ? 1 : 0) : interpolate(f, [a, b], [0, 1], { ...CLAMP, easing });

/** Progression en douceur dans les deux sens. */
export const smooth = (f: number, a: number, b: number) => prog(f, a, b, Easing.inOut(Easing.cubic));

/** Ressort qui démarre à l'image `at` (rebond léger par défaut). */
export const pop = (f: number, at: number, cfg: { damping?: number; stiffness?: number; mass?: number } = {}) =>
  spring({ frame: f - at, fps: FPS, config: { damping: 13, stiffness: 160, mass: 0.8, ...cfg } });

/** Ressort sans rebond, pour les déplacements. */
export const glide = (f: number, at: number, dur = 20) =>
  spring({ frame: f - at, fps: FPS, config: { damping: 200 }, durationInFrames: dur });

/** Valeur interpolée sur plusieurs clés [image, valeur]. */
export const keys = (f: number, k: [number, number][], easing = Easing.inOut(Easing.cubic)) =>
  interpolate(
    f,
    k.map((x) => x[0]),
    k.map((x) => x[1]),
    { ...CLAMP, easing },
  );

/** Apparition « fondu + montée ». */
export const rise = (p: number, dist = 26): React.CSSProperties => ({
  opacity: p,
  transform: `translateY(${(1 - p) * dist}px)`,
});

/** Apparition « fondu + zoom », avec flou qui se dissipe. */
export const bloom = (p: number, from = 0.85): React.CSSProperties => ({
  opacity: Math.min(1, p * 1.4),
  transform: `scale(${from + (1 - from) * p})`,
  filter: p < 0.999 ? `blur(${(1 - p) * 10}px)` : undefined,
});

/** Secousse amortie déclenchée à l'image `at`. */
export const shake = (f: number, at: number, amp = 10, dur = 14) => {
  const t = f - at;
  if (t < 0 || t > dur) return { x: 0, y: 0 };
  const k = (1 - t / dur) ** 2 * amp;
  return { x: Math.sin(t * 2.7) * k, y: Math.cos(t * 3.4) * k * 0.6 };
};

/** Nombre de caractères visibles d'une frappe au clavier entre a et b. */
export const typed = (text: string, f: number, a: number, b: number) =>
  text.slice(0, Math.round(prog(f, a, b, Easing.linear) * text.length));

/** Chaîne qui se brouille puis se recompose de `from` vers `to` (p de 0 à 1). */
export const scramble = (from: string, to: string, p: number, seed: string, f: number) => {
  const n = Math.max(from.length, to.length);
  const glyphs = "abcdefghijklmnopqrstuvwxyz0123456789[]:/._-";
  let out = "";
  for (let i = 0; i < n; i++) {
    const r = random(`${seed}-${i}`);
    const t0 = 0.05 + r * 0.55;
    const t1 = t0 + 0.25;
    if (p < t0) out += from[i] ?? "";
    else if (p >= t1) out += to[i] ?? "";
    else out += glyphs[Math.floor(random(`${seed}-${i}-${f}`) * glyphs.length)];
  }
  return out.replace(/\s+$/, "");
};

/** Clignotement du curseur de terminal. */
export const caretOn = (f: number) => Math.floor(f / 15) % 2 === 0;

export const lerp = (a: number, b: number, t: number) => a + (b - a) * t;
