import React from "react";
import { Easing, useCurrentFrame } from "remotion";
import { caretOn, prog } from "../anim";
import { C, F, alpha } from "../theme";

/** Morceau de ligne : texte, couleur, gras, surlignage animé (balayage de marqueur). */
export type Seg = {
  t: string;
  c?: string;
  b?: boolean;
  hl?: { at: number; color: string; fg?: string; dur?: number; until?: number };
};

/** Ligne de terminal : apparaît à `at` ; si `type` est fourni, les segments sont tapés entre ces images. */
export type Line = {
  at: number;
  segs: Seg[];
  prompt?: Seg[];
  type?: [number, number];
  /** Remplace le texte à partir d'une image (brouillage puis recomposition). */
  morph?: (f: number) => Seg[] | null;
  /** Image à partir de laquelle la ligne passe au second plan (atténuée). */
  dim?: number;
};

/** Décale toutes les dates d'une liste de lignes (pour prolonger un terminal d'une scène à l'autre). */
export const shiftLines = (lines: Line[], d: number): Line[] => {
  const sh = (s: Seg): Seg => (s.hl ? { ...s, hl: { ...s.hl, at: s.hl.at + d, until: s.hl.until !== undefined ? s.hl.until + d : undefined } } : s);
  return lines.map((l) => ({
    ...l,
    at: l.at + d,
    type: l.type ? [l.type[0] + d, l.type[1] + d] : undefined,
    dim: l.dim !== undefined ? l.dim + d : undefined,
    segs: l.segs.map(sh),
    prompt: l.prompt?.map(sh),
  }));
};

export const seg = (t: string, c?: string, extra: Partial<Seg> = {}): Seg => ({ t, c, ...extra });

const HLSpan: React.FC<{ s: Seg; f: number; children: React.ReactNode }> = ({ s, f, children }) => {
  if (!s.hl) return <span style={{ color: s.c ?? C.log, fontWeight: s.b ? 700 : undefined }}>{children}</span>;
  const { at, color, fg, dur = 8, until } = s.hl;
  const p = prog(f, at, at + dur, Easing.out(Easing.quad));
  const off = until !== undefined ? prog(f, until, until + 10) : 0;
  const k = p * (1 - off);
  return (
    <span
      style={{
        color: k > 0.4 ? fg ?? color : s.c ?? C.log,
        fontWeight: s.b ? 700 : undefined,
        backgroundImage: `linear-gradient(${alpha(color, 0.28 * (1 - off))}, ${alpha(color, 0.28 * (1 - off))})`,
        backgroundSize: `${p * 100}% 100%`,
        backgroundRepeat: "no-repeat",
        borderRadius: 4,
        boxShadow: k > 0.4 ? `0 0 0 1px ${alpha(color, 0.45 * k)}` : undefined,
      }}
    >
      {children}
    </span>
  );
};

/**
 * Lignes de terminal qui apparaissent, se tapent, se surlignent et défilent (les plus récentes en
 * bas). `maxLines` : nombre de lignes visibles avant défilement.
 */
export const TermLines: React.FC<{
  lines: Line[];
  size?: number;
  lh?: number;
  maxLines?: number;
  caret?: boolean;
  font?: string;
  defaultColor?: string;
  style?: React.CSSProperties;
}> = ({ lines, size = 20, lh = 1.55, maxLines = 99, caret = true, font = F.mono, defaultColor = C.log, style }) => {
  const f = useCurrentFrame();
  const vis = lines.filter((l) => l.at <= f);
  const lineH = size * lh;
  const smoothCount = lines.reduce((n, l) => n + prog(f, l.at, l.at + 5, Easing.out(Easing.quad)), 0);
  const offset = Math.max(0, smoothCount - maxLines) * lineH;
  const last = vis[vis.length - 1];
  return (
    <div style={{ position: "relative", height: maxLines * lineH, overflow: "hidden", ...style }}>
      <div style={{ transform: `translateY(${-offset}px)` }}>
        {vis.map((l, i) => {
          let segs = l.morph?.(f) ?? l.segs;
          let typing = false;
          if (l.type) {
            const total = segs.reduce((n, s) => n + s.t.length, 0);
            let n = Math.round(prog(f, l.type[0], l.type[1], Easing.linear) * total);
            typing = f < l.type[1] + 2;
            segs = segs.map((s) => {
              const k = Math.max(0, Math.min(s.t.length, n));
              n -= s.t.length;
              return { ...s, t: s.t.slice(0, k) };
            });
          }
          const appear = prog(f, l.at, l.at + 4) * (l.dim !== undefined ? 1 - 0.62 * prog(f, l.dim, l.dim + 10) : 1);
          const showCaret = caret && l === last && (typing || caretOn(f));
          return (
            <div
              key={i}
              style={{
                height: lineH,
                lineHeight: `${lineH}px`,
                fontFamily: font,
                fontSize: size,
                whiteSpace: "pre",
                color: defaultColor,
                opacity: appear,
              }}
            >
              {l.prompt?.map((s, k) => (
                <HLSpan key={`p${k}`} s={s} f={f}>
                  {s.t}
                </HLSpan>
              ))}
              {segs.map((s, k) => (
                <HLSpan key={k} s={{ ...s, c: s.c ?? defaultColor }} f={f}>
                  {s.t}
                </HLSpan>
              ))}
              {showCaret ? (
                <span
                  style={{
                    display: "inline-block",
                    width: size * 0.6,
                    height: size * 1.1,
                    background: alpha(C.text, 0.85),
                    verticalAlign: "middle",
                    marginLeft: 2,
                  }}
                />
              ) : null}
            </div>
          );
        })}
      </div>
    </div>
  );
};

/** Invite de commande Linux colorée (debian@serveur:~$). */
export const bashPrompt = (host = "serveur", user = "debian"): Seg[] => [
  { t: `${user}@${host}`, c: C.green, b: true },
  { t: ":", c: C.dim },
  { t: "~", c: "#60A5FA", b: true },
  { t: "$ ", c: C.dim },
];

/** Invite PowerShell. */
export const psPrompt = (): Seg[] => [{ t: "PS C:\\Users\\eleve> ", c: "#C7D2FE" }];
