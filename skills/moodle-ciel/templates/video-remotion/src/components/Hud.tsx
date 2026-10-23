import React from "react";
import { AbsoluteFill, Easing, interpolate, useCurrentFrame } from "remotion";
import { prog } from "../anim";
import { TL } from "../timeline";
import { C, F, alpha } from "../theme";

const CHAPTERS = TL.scenes.filter((s) => s.chapitre);
const FIRST = CHAPTERS[0];
const LAST = CHAPTERS[CHAPTERS.length - 1];
const SPAN_START = FIRST.start + FIRST.overlapIn;
const SPAN_END = LAST.start + LAST.dur - LAST.overlapOut;

/** Étiquette de chapitre (en haut à gauche) et barre de progression (en bas). */
export const Hud: React.FC = () => {
  const f = useCurrentFrame();
  const barIn = prog(f, SPAN_START - 10, SPAN_START + 20);
  const barOut = 1 - prog(f, SPAN_END - 10, SPAN_END + 10);
  const progress = interpolate(f, [SPAN_START, SPAN_END], [0, 1], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  });
  const x0 = 72;
  const x1 = 1848;
  return (
    <AbsoluteFill style={{ pointerEvents: "none" }}>
      {CHAPTERS.map((s, i) => {
        const l = f - s.start;
        const enter = prog(l, s.overlapIn - 2, s.overlapIn + 16, Easing.out(Easing.exp));
        const exit = 1 - prog(l, s.dur - s.overlapOut - 8, s.dur - s.overlapOut + 6);
        const o = Math.min(enter, exit);
        if (o <= 0) return null;
        return (
          <div
            key={s.id}
            style={{
              position: "absolute",
              left: 72,
              top: 50,
              display: "flex",
              alignItems: "center",
              gap: 14,
              opacity: o,
              transform: `translateX(${(1 - enter) * -24}px)`,
            }}
          >
            <div
              style={{
                fontFamily: F.mono,
                fontSize: 17,
                fontWeight: 700,
                color: C.ink0,
                background: C.cyan,
                borderRadius: 6,
                padding: "3px 8px",
                letterSpacing: 1,
              }}
            >
              {String(i + 1).padStart(2, "0")}
            </div>
            <div style={{ fontFamily: F.sans, fontSize: 21, fontWeight: 600, color: C.text, letterSpacing: 0.2 }}>
              {s.chapitre}
            </div>
          </div>
        );
      })}
      <div style={{ position: "absolute", left: x0, top: 1046, width: x1 - x0, height: 3, opacity: barIn * barOut }}>
        <div style={{ position: "absolute", inset: 0, borderRadius: 2, background: alpha("#94A3B8", 0.16) }} />
        <div
          style={{
            position: "absolute",
            left: 0,
            top: 0,
            bottom: 0,
            width: `${progress * 100}%`,
            borderRadius: 2,
            background: `linear-gradient(90deg, ${alpha(C.cyan, 0.5)}, ${C.cyan})`,
            boxShadow: `0 0 10px ${alpha(C.cyan, 0.6)}`,
          }}
        />
        {CHAPTERS.slice(1).map((s) => {
          const x = (s.start + s.overlapIn - SPAN_START) / (SPAN_END - SPAN_START);
          return (
            <div
              key={s.id}
              style={{
                position: "absolute",
                left: `${x * 100}%`,
                top: -3,
                width: 2,
                height: 9,
                borderRadius: 1,
                background: x <= progress ? C.cyan : alpha("#94A3B8", 0.4),
              }}
            />
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
