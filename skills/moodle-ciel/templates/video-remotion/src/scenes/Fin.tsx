import React from "react";
import { AbsoluteFill, Easing, useCurrentFrame } from "remotion";
import { pop, prog } from "../anim";
import { Vigie } from "../components/Icons";
import { Cue, Voice, useVoice } from "../timeline";
import { C, F, alpha, glow } from "../theme";

export const sfx = (v: Voice): Cue[] => [
  { at: 6, src: "final", volume: 0.7 },
  { at: v.dur - 40, src: "souffle", volume: 0.4 },
];

// Textes du carton final (à adapter).
const APPEL = "À toi de jouer !";
const RENVOI_CODE = "TP 1";
const RENVOI = "Intitulé du TP qui met la vidéo en pratique";
const PIED = "Parcours · BTS CIEL · module 1";

/** Carton final : renvoi vers le TP, puis fondu au noir. */
export const Fin: React.FC = () => {
  const f = useCurrentFrame();
  const v = useVoice();
  const emb = pop(f, 6, { damping: 15 });
  const a = prog(f, 14, 32, Easing.out(Easing.cubic));
  const b = prog(f, 26, 44, Easing.out(Easing.cubic));
  const black = prog(f, v.dur - 26, v.dur);
  return (
    <AbsoluteFill>
      <div style={{ position: "absolute", left: 960 - 85, top: 170, transform: `scale(${0.6 + 0.4 * emb})`, opacity: emb }}>
        <Vigie size={170} look={Math.sin(f / 18) * 0.7} glow={1} />
      </div>
      <div style={{ position: "absolute", left: 0, right: 0, top: 420, textAlign: "center", opacity: a, transform: `translateY(${(1 - a) * 30}px)` }}>
        <div style={{ fontFamily: F.display, fontSize: 92, fontWeight: 700, color: C.text, textShadow: `0 0 40px ${alpha(C.cyan, 0.3)}` }}>{APPEL}</div>
      </div>
      <div style={{ position: "absolute", left: 0, right: 0, top: 570, textAlign: "center", opacity: b, transform: `translateY(${(1 - b) * 20}px)` }}>
        <div style={{ display: "inline-block", fontFamily: F.sans, fontSize: 36, fontWeight: 500, color: C.text, border: `2px solid ${alpha(C.cyan, 0.6)}`, borderRadius: 18, padding: "16px 34px", boxShadow: glow(C.cyan, 0.4), background: alpha(C.cyan, 0.08) }}>
          <span style={{ fontFamily: F.mono, fontWeight: 700, color: C.cyan }}>{RENVOI_CODE}</span> · {RENVOI}
        </div>
      </div>
      <div style={{ position: "absolute", left: 0, right: 0, top: 700, textAlign: "center", fontFamily: F.sans, fontSize: 24, letterSpacing: 4, textTransform: "uppercase", color: C.faint, opacity: prog(f, 40, 56) }}>
        {PIED}
      </div>
      <AbsoluteFill style={{ background: "#000", opacity: black }} />
    </AbsoluteFill>
  );
};
