import React from "react";
import { AbsoluteFill, Easing, useCurrentFrame } from "remotion";
import { prog } from "../anim";
import { SceneInfo, SceneProvider, TL } from "../timeline";
import { C, H, W, alpha } from "../theme";

// Centre de l'ouverture en iris : champ « iris »: [x, y] de la scène dans scenes.json (là où apparaît
// le sujet de la scène), centre de l'écran par défaut.
const irisCenter = (s: SceneInfo): [number, number] => (s.iris as [number, number] | null) ?? [W / 2, H / 2];
const IRIS_MAX = 1250;
const ease = Easing.inOut(Easing.cubic);

/**
 * Enveloppe d'une scène : fournit ses repères (voix, durée) et joue les transitions.
 * Entrée : balayage « scan » (une ligne lumineuse révèle la scène), iris, fondu, ou flash après
 * le zoom de la scène précédente. La scène qui sort est masquée de façon complémentaire (le fond
 * commun reste visible), et recule légèrement.
 */
export const SceneShell: React.FC<{ scene: SceneInfo; children: React.ReactNode }> = ({ scene, children }) => {
  const f = useCurrentFrame();
  const idx = TL.scenes.findIndex((s) => s.id === scene.id);
  const next = TL.scenes[idx + 1];
  const pin = prog(f, 0, scene.overlapIn, ease);
  const pout = scene.overlapOut > 0 ? prog(f, scene.dur - scene.overlapOut, scene.dur, ease) : 0;

  const style: React.CSSProperties = {};
  let overlay: React.ReactNode = null;

  // Entrée
  if (scene.overlapIn > 0 && pin < 1) {
    if (scene.in === "scan") {
      style.clipPath = `inset(0 ${(1 - pin) * 100}% 0 0)`;
      const x = pin * W;
      overlay = (
        <>
          <div
            style={{
              position: "absolute",
              left: x - 260,
              top: 0,
              width: 260,
              height: H,
              background: `linear-gradient(90deg, transparent, ${alpha(C.cyan, 0.14)})`,
            }}
          />
          <div
            style={{
              position: "absolute",
              left: x - 2,
              top: 0,
              width: 3,
              height: H,
              background: "#E9FCFF",
              boxShadow: `0 0 18px ${C.cyan}, 0 0 46px ${alpha(C.cyan, 0.7)}`,
            }}
          />
        </>
      );
    } else if (scene.in === "iris") {
      const [cx, cy] = irisCenter(scene);
      const r = pin * IRIS_MAX;
      style.clipPath = `circle(${r}px at ${cx}px ${cy}px)`;
      overlay = (
        <div
          style={{
            position: "absolute",
            left: cx - r,
            top: cy - r,
            width: r * 2,
            height: r * 2,
            borderRadius: "50%",
            border: `3px solid ${alpha("#E9FCFF", 0.9 * (1 - pin))}`,
            boxShadow: `0 0 30px ${alpha(C.cyan, 0.8 * (1 - pin))}`,
          }}
        />
      );
    } else if (scene.in === "fade") {
      style.opacity = pin;
    } else if (scene.in === "zoom") {
      style.opacity = pin;
      overlay = (
        <AbsoluteFill
          style={{
            background: "radial-gradient(circle at 50% 45%, #FFFFFF 0%, #BDF4FF 35%, rgba(34,211,238,0.35) 70%, transparent 100%)",
            opacity: 0.9 * (1 - prog(f, 0, 12, Easing.out(Easing.cubic))),
          }}
        />
      );
    }
  }

  // Sortie, complémentaire de l'entrée de la scène suivante
  if (pout > 0 && next) {
    if (next.in === "scan") {
      style.clipPath = `inset(0 0 0 ${pout * 100}%)`;
    } else if (next.in === "iris") {
      const [cx, cy] = irisCenter(next);
      const r = pout * IRIS_MAX;
      const mask = `radial-gradient(circle at ${cx}px ${cy}px, transparent ${r}px, black ${r + 1}px)`;
      style.maskImage = mask;
      style.WebkitMaskImage = mask;
    } else {
      style.opacity = 1 - pout;
    }
  }
  const recede: React.CSSProperties =
    pout > 0 ? { transform: `scale(${1 - 0.03 * pout})`, filter: `brightness(${1 - 0.4 * pout})` } : {};

  return (
    <SceneProvider scene={scene}>
      <AbsoluteFill style={style}>
        <AbsoluteFill style={recede}>{children}</AbsoluteFill>
      </AbsoluteFill>
      {overlay ? <AbsoluteFill style={{ pointerEvents: "none" }}>{overlay}</AbsoluteFill> : null}
    </SceneProvider>
  );
};
