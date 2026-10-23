import React from "react";
import { AbsoluteFill, interpolate, interpolateColors, random, useCurrentFrame } from "remotion";
import { TL } from "../timeline";
import { H, W } from "../theme";

// Ambiances : couleurs des trois nappes lumineuses du fond, densité d'étoiles.
const MOODS: Record<string, { a: string; b: string; c: string; stars: number }> = {
  nuit: { a: "#1D3A8A", b: "#3B1A7A", c: "#0B5A73", stars: 1 },
  protection: { a: "#0B6B85", b: "#1D3A8A", c: "#0E4F63", stars: 0.45 },
  attaque: { a: "#7A1626", b: "#1D2F6B", c: "#3B1A7A", stars: 0.25 },
  neutre: { a: "#1D3A8A", b: "#0B5A73", c: "#2A2766", stars: 0.25 },
  alerte: { a: "#7A4510", b: "#1D2F6B", c: "#6B2A10", stars: 0.2 },
};

const STARS = new Array(150).fill(0).map((_, i) => ({
  x: random(`sx${i}`) * W,
  y: random(`sy${i}`) * H * 0.85,
  r: 0.6 + random(`sr${i}`) ** 3 * 1.9,
  ph: random(`sp${i}`) * Math.PI * 2,
  sp: 0.03 + random(`sv${i}`) * 0.07,
}));

/** Ambiance à l'image f : fondu entre l'ambiance précédente et celle de la scène qui commence. */
const moodAt = (f: number) => {
  const scenes = TL.scenes;
  let i = 0;
  for (let k = 0; k < scenes.length; k++) if (scenes[k].start <= f) i = k;
  const cur = MOODS[scenes[i].ambiance];
  const prev = i > 0 ? MOODS[scenes[i - 1].ambiance] : cur;
  const t = interpolate(f - scenes[i].start, [0, 40], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const col = (k: "a" | "b" | "c") => interpolateColors(t, [0, 1], [prev[k], cur[k]]);
  return { a: col("a"), b: col("b"), c: col("c"), stars: prev.stars + (cur.stars - prev.stars) * t };
};

export const Background: React.FC = () => {
  const f = useCurrentFrame();
  const m = moodAt(f);
  const blob = (color: string, cx: number, cy: number, r: number, ph: number, op: number) => {
    const x = cx + Math.sin(f / 170 + ph) * 140;
    const y = cy + Math.cos(f / 210 + ph) * 90;
    return (
      <div
        style={{
          position: "absolute",
          left: x - r,
          top: y - r,
          width: r * 2,
          height: r * 2,
          borderRadius: "50%",
          background: `radial-gradient(circle, ${color} 0%, transparent 68%)`,
          opacity: op,
        }}
      />
    );
  };
  // Fondu d'ouverture depuis le noir (la vidéo commence dans la nuit).
  const open = interpolate(f, [0, 28], [0, 1], { extrapolateRight: "clamp" });
  return (
    <AbsoluteFill style={{ background: "radial-gradient(ellipse at 50% 28%, #0B1528 0%, #060B16 55%, #03060C 100%)" }}>
      <AbsoluteFill style={{ opacity: open }}>
        {blob(m.a, 420, 260, 760, 0, 0.42)}
        {blob(m.b, 1560, 340, 820, 2.1, 0.36)}
        {blob(m.c, 980, 1020, 900, 4.2, 0.3)}
        {STARS.map((s, i) => {
          const tw = 0.45 + 0.55 * Math.sin(f * s.sp + s.ph);
          return (
            <div
              key={i}
              style={{
                position: "absolute",
                left: s.x,
                top: s.y,
                width: s.r * 2,
                height: s.r * 2,
                borderRadius: "50%",
                background: "#DCE7FF",
                opacity: m.stars * (0.25 + 0.6 * tw) * (s.r > 1.6 ? 1 : 0.7),
              }}
            />
          );
        })}
        <AbsoluteFill
          style={{
            backgroundImage: "radial-gradient(circle, rgba(148,163,184,0.16) 1.3px, transparent 1.8px)",
            backgroundSize: "46px 46px",
            backgroundPosition: `${(f * 0.15) % 46}px ${(f * 0.08) % 46}px`,
            opacity: 0.55,
            maskImage: "radial-gradient(ellipse at 50% 55%, black 0%, transparent 72%)",
            WebkitMaskImage: "radial-gradient(ellipse at 50% 55%, black 0%, transparent 72%)",
          }}
        />
      </AbsoluteFill>
      <AbsoluteFill style={{ background: "radial-gradient(ellipse at 50% 50%, transparent 52%, rgba(0,0,0,0.62) 100%)" }} />
    </AbsoluteFill>
  );
};
