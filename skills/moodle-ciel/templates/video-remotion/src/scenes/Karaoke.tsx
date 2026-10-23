import React from "react";
import { AbsoluteFill, useCurrentFrame } from "remotion";
import { prog } from "../anim";
import { TL, VOIX, useScene } from "../timeline";
import { C, F, alpha } from "../theme";

/**
 * Scène provisoire : le texte du bloc s'écrit au rythme de la voix, mot à mot. Sert à vérifier
 * le calage et le rythme d'un bloc avant d'écrire sa vraie scène (à remplacer ensuite : une vidéo
 * n'est pas un texte lu à l'écran).
 */
export const Karaoke: React.FC = () => {
  const f = useCurrentFrame();
  const s = useScene();
  if (!s.bloc) return null;
  const words = VOIX[s.bloc].words;
  return (
    <AbsoluteFill style={{ justifyContent: "center", alignItems: "center", padding: "0 200px" }}>
      <div style={{ fontFamily: F.display, fontSize: 58, fontWeight: 700, lineHeight: 1.35, textAlign: "center" }}>
        {words.map((w, i) => {
          const at = s.voiceStart + Math.round(w.s * TL.fps);
          const p = prog(f, at - 2, at + 6);
          return (
            <span key={i} style={{ color: p > 0.5 ? C.text : alpha(C.text, 0.18), marginRight: "0.28em", display: "inline-block", transform: `translateY(${(1 - p) * 8}px)` }}>
              {w.w}
            </span>
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
