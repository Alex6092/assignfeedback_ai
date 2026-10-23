import React from "react";
import { AbsoluteFill, Easing, useCurrentFrame } from "remotion";
import { pop, prog } from "../anim";
import { Vigie } from "../components/Icons";
import { Cue, Voice } from "../timeline";
import { C, F, alpha } from "../theme";

export const sfx = (v: Voice): Cue[] => [
  { at: 0, src: "impact", volume: 0.8 },
  { at: 6, src: "scintille", volume: 0.5 },
  { at: v.out - 4, src: "balayage", volume: 0.5 },
];

// Textes du carton-titre (à adapter). ACCENT : indices des lettres du mot mises en couleur.
const SURTITRE = "Parcours · module 1";
const WORD = "fail2ban";
const ACCENT = [4];
const SOUS_TITRE = "la vigie de la porte";
const SOUS_TITRE_CODE = "22";
const BANDEAU = "Pare-feu, fail2ban et journaux";

/** Carton-titre : un faisceau balaie la nuit, le nom se pose lettre à lettre. */
export const Titre: React.FC = () => {
  const f = useCurrentFrame();
  const sweep = Math.sin(f / 26) * 48;
  const beamO = prog(f, 0, 20);
  const emb = pop(f, 2, { damping: 15 });
  return (
    <AbsoluteFill>
      {/* Faisceau de la vigie */}
      <div
        style={{
          position: "absolute",
          left: 960 - 1400,
          top: 250 - 1400,
          width: 2800,
          height: 2800,
          borderRadius: "50%",
          background: `conic-gradient(from ${180 + sweep - 14}deg at 50% 50%, transparent 0deg, ${alpha(C.cyan, 0.0)} 2deg, ${alpha(C.cyan, 0.22)} 14deg, ${alpha(C.cyan, 0.0)} 28deg, transparent 30deg)`,
          opacity: beamO,
          maskImage: "radial-gradient(circle, black 0%, black 30%, transparent 62%)",
          WebkitMaskImage: "radial-gradient(circle, black 0%, black 30%, transparent 62%)",
        }}
      />
      <div style={{ position: "absolute", left: 960 - 75, top: 160, transform: `scale(${0.6 + 0.4 * emb})`, opacity: emb }}>
        <Vigie size={150} look={Math.sin(f / 26) * 0.9} glow={0.9} />
      </div>
      <div
        style={{
          position: "absolute",
          left: 0,
          right: 0,
          top: 120,
          textAlign: "center",
          fontFamily: F.sans,
          fontWeight: 600,
          fontSize: 22,
          letterSpacing: 6,
          textTransform: "uppercase",
          color: alpha(C.cyan, 0.85),
          opacity: prog(f, 18, 34),
        }}
      >
        {SURTITRE}
      </div>
      <div style={{ position: "absolute", left: 0, right: 0, top: 380, display: "flex", justifyContent: "center" }}>
        {WORD.split("").map((ch, i) => {
          const p = prog(f, 6 + i * 2.2, 22 + i * 2.2, Easing.out(Easing.exp));
          const isTwo = ACCENT.includes(i);
          return (
            <span
              key={i}
              style={{
                fontFamily: F.display,
                fontWeight: 700,
                fontSize: 210,
                letterSpacing: -4,
                color: isTwo ? C.cyan : C.text,
                textShadow: isTwo ? `0 0 40px ${alpha(C.cyan, 0.7)}` : `0 0 30px ${alpha(C.cyan, 0.25)}`,
                display: "inline-block",
                opacity: p,
                transform: `translateY(${(1 - p) * 70}px)`,
                filter: p < 1 ? `blur(${(1 - p) * 14}px)` : undefined,
              }}
            >
              {ch}
            </span>
          );
        })}
      </div>
      <div
        style={{
          position: "absolute",
          left: 0,
          right: 0,
          top: 650,
          textAlign: "center",
          fontFamily: F.sans,
          fontWeight: 500,
          fontSize: 54,
          color: C.dim,
          opacity: prog(f, 24, 40),
          transform: `translateY(${(1 - prog(f, 24, 40)) * 20}px)`,
        }}
      >
        {SOUS_TITRE} <span style={{ fontFamily: F.mono, fontWeight: 700, color: C.cyan }}>{SOUS_TITRE_CODE}</span>
      </div>
      <div
        style={{
          position: "absolute",
          left: 760,
          width: 400,
          top: 760,
          height: 2,
          background: `linear-gradient(90deg, transparent, ${C.cyan}, transparent)`,
          transform: `scaleX(${prog(f, 30, 56, Easing.inOut(Easing.cubic))})`,
          opacity: 0.8,
        }}
      />
      <div
        style={{
          position: "absolute",
          left: 0,
          right: 0,
          top: 790,
          textAlign: "center",
          fontFamily: F.sans,
          fontWeight: 500,
          fontSize: 26,
          letterSpacing: 1,
          color: C.faint,
          opacity: prog(f, 40, 56),
        }}
      >
        {BANDEAU}
      </div>
    </AbsoluteFill>
  );
};
