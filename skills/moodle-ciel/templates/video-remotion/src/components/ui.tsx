import React from "react";
import { evolvePath, getLength, getPointAtLength } from "@remotion/paths";
import { AbsoluteFill, Easing, useCurrentFrame } from "remotion";
import { pop, prog } from "../anim";
import { C, F, alpha, glow } from "../theme";

/** Pastille : réglage, statut, étiquette courte. */
export const Chip: React.FC<{
  children: React.ReactNode;
  color?: string;
  mono?: boolean;
  size?: number;
  style?: React.CSSProperties;
  solid?: boolean;
}> = ({ children, color = C.cyan, mono = true, size = 22, style, solid }) => (
  <div
    style={{
      display: "inline-flex",
      alignItems: "center",
      gap: 10,
      fontFamily: mono ? F.mono : F.sans,
      fontSize: size,
      fontWeight: mono ? 600 : 600,
      color: solid ? C.ink0 : color,
      background: solid ? color : alpha(color, 0.12),
      border: `1.5px solid ${alpha(color, solid ? 1 : 0.55)}`,
      borderRadius: 999,
      padding: `${size * 0.32}px ${size * 0.75}px`,
      whiteSpace: "nowrap",
      ...style,
    }}
  >
    {children}
  </div>
);

/** Élément qui « saute » à l'image `at` (ressort), placé en absolu. */
export const Pop: React.FC<{
  at: number;
  x: number;
  y: number;
  children: React.ReactNode;
  from?: number;
  origin?: string;
  style?: React.CSSProperties;
  until?: number;
}> = ({ at, x, y, children, from = 0.6, origin = "center", style, until }) => {
  const f = useCurrentFrame();
  if (f < at) return null;
  const p = pop(f, at);
  const out = until !== undefined ? prog(f, until, until + 10) : 0;
  return (
    <div
      style={{
        position: "absolute",
        left: x,
        top: y,
        transform: `scale(${from + (1 - from) * p})`,
        transformOrigin: origin,
        opacity: Math.min(1, p * 2) * (1 - out),
        ...style,
      }}
    >
      {children}
    </div>
  );
};

/** Apparition en fondu + glissement, entre `at` et `at + dur`. */
export const Rise: React.FC<{
  at: number;
  x: number;
  y: number;
  children: React.ReactNode;
  dur?: number;
  dx?: number;
  dy?: number;
  until?: number;
  style?: React.CSSProperties;
}> = ({ at, x, y, children, dur = 14, dx = 0, dy = 22, until, style }) => {
  const f = useCurrentFrame();
  if (f < at) return null;
  const p = prog(f, at, at + dur, Easing.out(Easing.cubic));
  const out = until !== undefined ? prog(f, until, until + 10) : 0;
  return (
    <div
      style={{
        position: "absolute",
        left: x,
        top: y,
        opacity: p * (1 - out),
        transform: `translate(${(1 - p) * dx}px, ${(1 - p) * dy}px)`,
        ...style,
      }}
    >
      {children}
    </div>
  );
};

/** Texte qui s'écrit mot à mot (pour une légende courte synchronisée à la voix). */
export const WordsIn: React.FC<{
  text: string;
  at: number;
  per?: number;
  style?: React.CSSProperties;
}> = ({ text, at, per = 3, style }) => {
  const f = useCurrentFrame();
  const words = text.split(" ");
  return (
    <span style={style}>
      {words.map((w, i) => {
        const p = prog(f, at + i * per, at + i * per + 8);
        return (
          <span key={i} style={{ display: "inline-block", opacity: p, transform: `translateY(${(1 - p) * 10}px)`, marginRight: "0.28em" }}>
            {w}
          </span>
        );
      })}
    </span>
  );
};

/** Tampon « BANNIE » : arrive grand, s'écrase, secoue. */
export const Stamp: React.FC<{ at: number; text?: string; color?: string; size?: number; rot?: number }> = ({
  at,
  text = "BANNIE",
  color = C.red,
  size = 64,
  rot = -8,
}) => {
  const f = useCurrentFrame();
  if (f < at) return null;
  const p = pop(f, at, { damping: 11, stiffness: 260, mass: 0.7 });
  const s = 2.4 - 1.4 * p;
  return (
    <div
      style={{
        transform: `rotate(${rot}deg) scale(${s})`,
        opacity: Math.min(1, (f - at) / 3),
        fontFamily: F.display,
        fontWeight: 700,
        fontSize: size,
        letterSpacing: size * 0.08,
        color,
        border: `${size * 0.07}px solid ${color}`,
        borderRadius: size * 0.22,
        padding: `${size * 0.08}px ${size * 0.35}px`,
        background: alpha(color, 0.12),
        boxShadow: glow(color, 1.2),
        textShadow: `0 0 18px ${alpha(color, 0.6)}`,
        whiteSpace: "nowrap",
      }}
    >
      {text}
    </div>
  );
};

/** Flash plein écran (capture, impact). */
export const Flash: React.FC<{ at: number; color?: string; dur?: number; max?: number }> = ({ at, color = "#FFFFFF", dur = 8, max = 0.6 }) => {
  const f = useCurrentFrame();
  const t = f - at;
  if (t < 0 || t > dur) return null;
  return <AbsoluteFill style={{ background: color, opacity: max * (1 - t / dur) }} />;
};

/**
 * Flèche qui se trace de `at` à `at + dur`, avec pointe et, en option, des particules qui
 * circulent le long du tracé (flux de lignes de journal, paquets réseau).
 */
export const Arrow: React.FC<{
  d: string;
  at: number;
  dur?: number;
  color?: string;
  width?: number;
  head?: boolean;
  dash?: boolean;
  flow?: { from: number; to?: number; every?: number; speed?: number; color?: string; size?: number };
  style?: React.CSSProperties;
}> = ({ d, at, dur = 16, color = C.cyan, width = 4, head = true, dash = false, flow, style }) => {
  const f = useCurrentFrame();
  const p = prog(f, at, at + dur, Easing.inOut(Easing.cubic));
  if (p <= 0) return null;
  const len = getLength(d);
  const ev = evolvePath(p, d);
  const end = getPointAtLength(d, len * p) ?? { x: 0, y: 0 };
  const before = getPointAtLength(d, Math.max(0, len * p - 2)) ?? end;
  const ang = (Math.atan2(end.y - before.y, end.x - before.x) * 180) / Math.PI;
  const dots: React.ReactNode[] = [];
  if (flow && f >= flow.from) {
    const every = flow.every ?? 8;
    const speed = flow.speed ?? 18; // images pour parcourir le tracé
    const last = flow.to ?? Infinity;
    for (let s = flow.from; s <= Math.min(f, last); s += every) {
      const t = (f - s) / speed;
      if (t < 0 || t > 1) continue;
      const pt = getPointAtLength(d, len * t) ?? { x: 0, y: 0 };
      dots.push(
        <circle key={s} cx={pt.x} cy={pt.y} r={flow.size ?? 6} fill={flow.color ?? color} opacity={Math.min(1, (1 - t) * 3)} />,
      );
    }
  }
  return (
    <svg width="1920" height="1080" viewBox="0 0 1920 1080" style={{ position: "absolute", left: 0, top: 0, overflow: "visible", ...style }}>
      <path
        d={d}
        fill="none"
        stroke={alpha(color, 0.9)}
        strokeWidth={width}
        strokeLinecap="round"
        strokeDasharray={dash ? "10 12" : ev.strokeDasharray}
        strokeDashoffset={dash ? -f * 1.5 : ev.strokeDashoffset}
        style={{ filter: `drop-shadow(0 0 6px ${alpha(color, 0.6)})` }}
        opacity={dash ? p : 1}
      />
      {head && p > 0.05 ? (
        <path d="M -14 -9 L 2 0 L -14 9" fill="none" stroke={color} strokeWidth={width} strokeLinecap="round" strokeLinejoin="round" transform={`translate(${end.x} ${end.y}) rotate(${ang})`} />
      ) : null}
      {dots}
    </svg>
  );
};

/** Libellé d'action (« lit », « compte », « bloque ») posé sur une flèche. */
export const Verb: React.FC<{ at: number; x: number; y: number; children: React.ReactNode; color?: string }> = ({ at, x, y, children, color = C.cyan }) => {
  const f = useCurrentFrame();
  if (f < at) return null;
  const p = pop(f, at);
  return (
    <div
      style={{
        position: "absolute",
        left: x,
        top: y,
        transform: `translate(-50%, -50%) scale(${0.5 + 0.5 * p})`,
        opacity: Math.min(1, p * 2),
        fontFamily: F.display,
        fontWeight: 700,
        fontSize: 30,
        color,
        background: "rgba(5,10,20,0.88)",
        border: `2px solid ${alpha(color, 0.6)}`,
        borderRadius: 14,
        padding: "6px 18px",
        boxShadow: glow(color, 0.6),
        whiteSpace: "nowrap",
      }}
    >
      {children}
    </div>
  );
};

/** Nombre à la française (espace fine insécable pour les milliers). */
export const fr = (n: number) => Math.round(n).toLocaleString("fr-FR").replace(/ | /g, " ");

/** Légende (titre de scène, phrase-clé), en haut de l'écran. */
export const Heading: React.FC<{ at: number; children: React.ReactNode; x?: number; y?: number; size?: number; color?: string; until?: number }> = ({
  at,
  children,
  x = 72,
  y = 110,
  size = 50,
  color = C.text,
  until,
}) => (
  <Rise at={at} x={x} y={y} until={until}>
    <div style={{ fontFamily: F.display, fontWeight: 700, fontSize: size, color, letterSpacing: -0.5 }}>{children}</div>
  </Rise>
);
