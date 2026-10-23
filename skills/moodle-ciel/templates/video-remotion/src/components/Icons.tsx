import React, { useId } from "react";
import { useCurrentFrame } from "remotion";
import { C, alpha } from "../theme";

const useUid = () => useId().replace(/[^a-zA-Z0-9]/g, "");

/**
 * Un serveur, avec sa porte (le port 22). `door.glow` (0-1) allume le cadre de la porte,
 * `door.color` en fixe la couleur ; `leds` : sommeil (respiration lente), normal, alarme.
 */
export const Server: React.FC<{
  size?: number;
  door?: { color?: string; glow?: number; open?: number };
  leds?: "sleep" | "normal" | "alarm";
  label?: string;
  style?: React.CSSProperties;
}> = ({ size = 300, door = {}, leds = "normal", label = "Serveur", style }) => {
  const f = useCurrentFrame();
  const id = useUid();
  const dc = door.color ?? C.cyan;
  const dg = door.glow ?? 0.25;
  const led = (i: number, base: string) => {
    if (leds === "sleep") return { c: C.cyan, o: 0.25 + 0.35 * (0.5 + 0.5 * Math.sin(f / 22 + i)) };
    if (leds === "alarm") return { c: C.red, o: Math.floor((f + i * 3) / 4) % 2 ? 1 : 0.35 };
    return { c: base, o: Math.floor((f + i * 7) / 6) % 5 ? 1 : 0.4 };
  };
  return (
    <svg width={size} height={(size * 4) / 3} viewBox="0 0 300 400" style={{ overflow: "visible", ...style }}>
      <defs>
        <linearGradient id={`b${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#22314B" />
          <stop offset="1" stopColor="#0C1322" />
        </linearGradient>
        <linearGradient id={`e${id}`} x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="rgba(255,255,255,0.10)" />
          <stop offset="0.5" stopColor="rgba(255,255,255,0)" />
        </linearGradient>
        <filter id={`g${id}`} x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation="6" />
        </filter>
      </defs>
      <ellipse cx="150" cy="392" rx="130" ry="12" fill="rgba(0,0,0,0.5)" />
      <rect x="20" y="16" width="260" height="364" rx="28" fill={`url(#b${id})`} stroke="rgba(148,163,184,0.38)" strokeWidth="2" />
      <rect x="20" y="16" width="130" height="364" rx="28" fill={`url(#e${id})`} />
      <rect x="44" y="40" width="212" height="46" rx="11" fill="#08101C" stroke="rgba(148,163,184,0.25)" />
      <text x="62" y="70" fontFamily="JetBrains Mono, monospace" fontSize="19" fill="#9AA9BF" fontWeight="600">
        {label}
      </text>
      <circle cx="236" cy="63" r="5" fill={led(0, C.green).c} opacity={led(0, C.green).o} />
      {[104, 138, 172].map((y, i) => {
        const l = led(i + 1, i === 1 ? C.cyan : C.green);
        return (
          <g key={y}>
            <rect x="44" y={y} width="212" height="24" rx="6" fill="#0E1828" stroke="rgba(148,163,184,0.16)" />
            <rect x="58" y={y + 10} width="120" height="4" rx="2" fill="rgba(148,163,184,0.22)" />
            <circle cx="236" cy={y + 12} r="4.5" fill={l.c} opacity={l.o} />
            <circle cx="236" cy={y + 12} r="9" fill={l.c} opacity={l.o * 0.25} />
          </g>
        );
      })}
      {/* plaque du port */}
      <rect x="122" y="208" width="56" height="26" rx="7" fill="#08101C" stroke={alpha(dc, 0.35 + 0.6 * dg)} />
      <text x="150" y="228" textAnchor="middle" fontFamily="JetBrains Mono, monospace" fontSize="18" fontWeight="700" fill={dc} opacity={0.55 + 0.45 * dg}>
        22
      </text>
      {/* la porte */}
      <path d="M104 362 V288 A46 46 0 0 1 196 288 V362 Z" fill={dc} opacity={0.35 * dg} filter={`url(#g${id})`} />
      <path d="M104 362 V288 A46 46 0 0 1 196 288 V362 Z" fill="#070D18" stroke={dc} strokeOpacity={0.35 + 0.65 * dg} strokeWidth="3" />
      <g style={{ transformOrigin: "106px 320px", transform: `perspective(400px) rotateY(${-(door.open ?? 0) * 70}deg)` }}>
        <path d="M112 356 V290 A38 38 0 0 1 188 290 V356 Z" fill="#111B2C" stroke="rgba(148,163,184,0.25)" />
        <circle cx="176" cy="324" r="5" fill={dc} opacity={0.5 + 0.5 * dg} />
      </g>
    </svg>
  );
};

/**
 * Le robot attaquant, de profil vers la droite. `knock` (0-1) : bras tendu qui frappe ;
 * `glitch` (0-1) : brouillage d'apparition.
 */
export const Robot: React.FC<{
  size?: number;
  knock?: number;
  glitch?: number;
  eye?: string;
  style?: React.CSSProperties;
}> = ({ size = 240, knock = 0, glitch = 0, eye = C.red, style }) => {
  const f = useCurrentFrame();
  const id = useUid();
  const blink = f % 96 < 4 ? 0.15 : 1;
  const jit = glitch > 0 ? Math.sin(f * 12.9) * 10 * glitch : 0;
  const arm = -32 + knock * 40;
  const body = (dx: number, tint?: string) => (
    <g transform={`translate(${dx} 0)`} opacity={tint ? 0.55 : 1}>
      <line x1="112" y1="38" x2="112" y2="12" stroke={tint ?? "#7C8799"} strokeWidth="4" strokeLinecap="round" />
      <circle cx="112" cy="10" r="7" fill={tint ?? eye} opacity={tint ? 1 : 0.55 + 0.45 * Math.sin(f / 5)} />
      <rect x="40" y="38" width="150" height="122" rx="34" fill={tint ?? `url(#h${id})`} stroke={tint ?? alpha(eye, 0.55)} strokeWidth="2.5" />
      <rect x="58" y="70" width="120" height="54" rx="24" fill="#05070C" />
      <ellipse cx="98" cy="97" rx="13" ry={9 * blink} fill={eye} />
      <ellipse cx="146" cy="97" rx="13" ry={9 * blink} fill={eye} />
      <ellipse cx="122" cy="97" rx="60" ry="16" fill={eye} opacity="0.12" />
      <rect x="102" y="160" width="26" height="14" fill="#2A3142" />
      <rect x="58" y="172" width="114" height="100" rx="24" fill={tint ?? `url(#h${id})`} stroke={tint ?? alpha(eye, 0.35)} strokeWidth="2" />
      <circle cx="115" cy="216" r="11" fill={eye} opacity={0.4 + 0.4 * Math.sin(f / 7)} />
      {[236, 248, 260].map((y) => (
        <line key={y} x1="80" y1={y} x2="150" y2={y} stroke="rgba(148,163,184,0.25)" strokeWidth="3" strokeLinecap="round" />
      ))}
      <g transform={`rotate(${arm} 166 196)`}>
        <rect x="160" y="186" width="78" height="20" rx="10" fill={tint ?? "#2A3142"} stroke={tint ?? alpha(eye, 0.35)} />
        <circle cx="244" cy="196" r="15" fill={tint ?? "#3A4256"} stroke={tint ?? alpha(eye, 0.45)} strokeWidth="2" />
      </g>
    </g>
  );
  return (
    <svg width={size} height={(size * 300) / 260} viewBox="0 0 260 300" style={{ overflow: "visible", ...style }}>
      <defs>
        <linearGradient id={`h${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#323B50" />
          <stop offset="1" stopColor="#151B27" />
        </linearGradient>
      </defs>
      <ellipse cx="118" cy="292" rx="90" ry="9" fill="rgba(0,0,0,0.45)" />
      {glitch > 0.05 ? body(-jit - 6, alpha("#22D3EE", 0.5 * glitch)) : null}
      {glitch > 0.05 ? body(jit + 6, alpha("#FF4D5E", 0.5 * glitch)) : null}
      {body(jit * 0.3)}
    </svg>
  );
};

/** Emblème de protection (la « vigie ») : bouclier et œil. `look` : regard (-1 à 1), `glow` : éclat. */
export const Vigie: React.FC<{ size?: number; look?: number; glow?: number; color?: string; style?: React.CSSProperties }> = ({
  size = 220,
  look = 0,
  glow = 0.6,
  color = C.cyan,
  style,
}) => {
  const f = useCurrentFrame();
  const id = useUid();
  const blink = f % 120 < 5 ? 0.1 : 1;
  return (
    <svg width={size} height={(size * 280) / 240} viewBox="0 0 240 280" style={{ overflow: "visible", ...style }}>
      <defs>
        <linearGradient id={`v${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#0E2F3A" />
          <stop offset="1" stopColor="#06141B" />
        </linearGradient>
        <radialGradient id={`i${id}`}>
          <stop offset="0" stopColor="#A5F3FC" />
          <stop offset="0.55" stopColor={color} />
          <stop offset="1" stopColor="#0E7490" />
        </radialGradient>
        <filter id={`gl${id}`} x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur stdDeviation="10" />
        </filter>
      </defs>
      <path d="M120 8 L222 46 V138 C222 206 176 252 120 272 C64 252 18 206 18 138 V46 Z" fill={color} opacity={0.35 * glow} filter={`url(#gl${id})`} />
      <path d="M120 8 L222 46 V138 C222 206 176 252 120 272 C64 252 18 206 18 138 V46 Z" fill={`url(#v${id})`} stroke={color} strokeWidth="4" />
      <path d="M120 30 L202 60 V138 C202 194 166 232 120 250 C74 232 38 194 38 138 V60 Z" fill="none" stroke={alpha(color, 0.25)} strokeWidth="2" />
      <g transform={`translate(0 ${135 * (1 - blink)}) scale(1 ${blink})`}>
        <path d="M56 135 Q120 74 184 135 Q120 196 56 135 Z" fill={alpha(color, 0.1)} stroke={color} strokeWidth="4" strokeLinejoin="round" />
        <circle cx={120 + look * 22} cy="135" r="27" fill={`url(#i${id})`} />
        <circle cx={120 + look * 24} cy="135" r="11" fill="#031016" />
        <circle cx={112 + look * 24} cy="127" r="5" fill="#ECFEFF" opacity="0.9" />
      </g>
    </svg>
  );
};

/** Silhouette d'une personne (élève, utilisateur). */
export const Person: React.FC<{ size?: number; color?: string; fill?: string; style?: React.CSSProperties }> = ({
  size = 120,
  color = C.text,
  fill = "#1A2436",
  style,
}) => (
  <svg width={size} height={(size * 140) / 120} viewBox="0 0 120 140" style={{ overflow: "visible", ...style }}>
    <circle cx="60" cy="40" r="27" fill={fill} stroke={color} strokeWidth="4" />
    <path d="M12 136 C12 98 34 80 60 80 C86 80 108 98 108 136 Z" fill={fill} stroke={color} strokeWidth="4" strokeLinejoin="round" />
  </svg>
);

/** Poste de travail (écran). */
export const PC: React.FC<{ size?: number; color?: string; screen?: React.ReactNode; style?: React.CSSProperties }> = ({
  size = 220,
  color = C.lineHi,
  screen,
  style,
}) => (
  <div style={{ position: "relative", width: size, height: (size * 170) / 200, ...style }}>
    <svg width={size} height={(size * 170) / 200} viewBox="0 0 200 170" style={{ position: "absolute", inset: 0 }}>
      <rect x="6" y="6" width="188" height="122" rx="12" fill="#0B1220" stroke={color} strokeWidth="3" />
      <rect x="16" y="16" width="168" height="102" rx="6" fill="#050A14" />
      <path d="M84 128 L78 154 H122 L116 128 Z" fill="#1A2436" stroke={color} strokeWidth="2" />
      <rect x="56" y="154" width="88" height="10" rx="5" fill="#1A2436" stroke={color} strokeWidth="2" />
    </svg>
    <div style={{ position: "absolute", left: (16 / 200) * size, top: (16 / 200) * size, width: (168 / 200) * size, height: (102 / 200) * size, overflow: "hidden" }}>
      {screen}
    </div>
  </div>
);

export const Lock: React.FC<{ size?: number; color?: string; open?: number }> = ({ size = 64, color = C.red, open = 0 }) => (
  <svg width={size} height={size} viewBox="0 0 64 64" style={{ overflow: "visible" }}>
    <path d={`M20 30 V20 A12 12 0 0 1 44 20 V${30 - open * 10}`} fill="none" stroke={color} strokeWidth="6" strokeLinecap="round" transform={`translate(0 ${-open * 6})`} />
    <rect x="12" y="28" width="40" height="30" rx="8" fill={alpha(color, 0.18)} stroke={color} strokeWidth="4" />
    <circle cx="32" cy="41" r="4.5" fill={color} />
    <rect x="30" y="42" width="4" height="9" rx="2" fill={color} />
  </svg>
);

export const Key: React.FC<{ size?: number; color?: string }> = ({ size = 64, color = C.violet }) => (
  <svg width={size} height={size} viewBox="0 0 64 64">
    <circle cx="20" cy="32" r="12" fill="none" stroke={color} strokeWidth="5" />
    <circle cx="20" cy="32" r="4" fill={color} />
    <path d="M32 32 H58 M48 32 V42 M56 32 V40" stroke={color} strokeWidth="5" strokeLinecap="round" />
  </svg>
);

export const Warning: React.FC<{ size?: number; color?: string }> = ({ size = 64, color = C.amber }) => (
  <svg width={size} height={size} viewBox="0 0 64 64" style={{ overflow: "visible" }}>
    <path d="M32 6 L60 56 H4 Z" fill={alpha(color, 0.16)} stroke={color} strokeWidth="5" strokeLinejoin="round" />
    <rect x="29" y="22" width="6" height="19" rx="3" fill={color} />
    <circle cx="32" cy="48" r="3.6" fill={color} />
  </svg>
);

export const Check: React.FC<{ size?: number; color?: string; p?: number }> = ({ size = 48, color = C.green, p = 1 }) => (
  <svg width={size} height={size} viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="21" fill={alpha(color, 0.16)} stroke={color} strokeWidth="3" />
    <path d="M14 25 L21 32 L35 17" fill="none" stroke={color} strokeWidth="4.5" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="40" strokeDashoffset={40 * (1 - p)} />
  </svg>
);

export const Cross: React.FC<{ size?: number; color?: string; p?: number }> = ({ size = 48, color = C.red, p = 1 }) => (
  <svg width={size} height={size} viewBox="0 0 48 48">
    <circle cx="24" cy="24" r="21" fill={alpha(color, 0.16)} stroke={color} strokeWidth="3" />
    <path d="M16 16 L32 32" stroke={color} strokeWidth="4.5" strokeLinecap="round" strokeDasharray="23" strokeDashoffset={23 * (1 - Math.min(1, p * 2))} />
    <path d="M32 16 L16 32" stroke={color} strokeWidth="4.5" strokeLinecap="round" strokeDasharray="23" strokeDashoffset={23 * (1 - Math.max(0, p * 2 - 1))} />
  </svg>
);

export const Magnifier: React.FC<{ size?: number; color?: string }> = ({ size = 64, color = C.cyan }) => (
  <svg width={size} height={size} viewBox="0 0 64 64">
    <circle cx="27" cy="27" r="17" fill={alpha(color, 0.12)} stroke={color} strokeWidth="5" />
    <path d="M40 40 L56 56" stroke={color} strokeWidth="7" strokeLinecap="round" />
  </svg>
);

export const Folder: React.FC<{ size?: number; color?: string }> = ({ size = 64, color = C.amber }) => (
  <svg width={size} height={size} viewBox="0 0 64 64">
    <path d="M6 16 A4 4 0 0 1 10 12 H26 L31 18 H54 A4 4 0 0 1 58 22 V50 A4 4 0 0 1 54 54 H10 A4 4 0 0 1 6 50 Z" fill={alpha(color, 0.18)} stroke={color} strokeWidth="4" strokeLinejoin="round" />
  </svg>
);

export const Camera: React.FC<{ size?: number; color?: string }> = ({ size = 64, color = C.text }) => (
  <svg width={size} height={size} viewBox="0 0 64 64">
    <path d="M8 22 A4 4 0 0 1 12 18 H20 L25 11 H39 L44 18 H52 A4 4 0 0 1 56 22 V50 A4 4 0 0 1 52 54 H12 A4 4 0 0 1 8 50 Z" fill={alpha(color, 0.1)} stroke={color} strokeWidth="4" strokeLinejoin="round" />
    <circle cx="32" cy="35" r="10" fill="none" stroke={color} strokeWidth="4" />
  </svg>
);

export const Gear: React.FC<{ size?: number; color?: string; rot?: number }> = ({ size = 64, color = C.amber, rot = 0 }) => (
  <svg width={size} height={size} viewBox="0 0 64 64">
    <g transform={`rotate(${rot} 32 32)`}>
      {new Array(8).fill(0).map((_, i) => (
        <rect key={i} x="28" y="4" width="8" height="12" rx="2" fill={color} transform={`rotate(${i * 45} 32 32)`} />
      ))}
      <circle cx="32" cy="32" r="17" fill={alpha(color, 0.15)} stroke={color} strokeWidth="5" />
      <circle cx="32" cy="32" r="6" fill={color} />
    </g>
  </svg>
);

/** Ondes concentriques (coups frappés à la porte, alertes). Chaque date de `at` lance une onde. */
export const Ripples: React.FC<{ at: number[]; x: number; y: number; color?: string; size?: number; dur?: number }> = ({
  at,
  x,
  y,
  color = C.red,
  size = 220,
  dur = 22,
}) => {
  const f = useCurrentFrame();
  return (
    <>
      {at.map((a, i) => {
        const t = (f - a) / dur;
        if (t < 0 || t > 1) return null;
        const r = 20 + t * size;
        return (
          <div
            key={i}
            style={{
              position: "absolute",
              left: x - r,
              top: y - r,
              width: r * 2,
              height: r * 2,
              borderRadius: "50%",
              border: `${3 * (1 - t) + 1}px solid ${alpha(color, 0.85 * (1 - t))}`,
              boxShadow: `0 0 20px ${alpha(color, 0.4 * (1 - t))}`,
            }}
          />
        );
      })}
    </>
  );
};
