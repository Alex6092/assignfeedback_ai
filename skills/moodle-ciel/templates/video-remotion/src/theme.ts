import { loadFont as loadSpaceGrotesk } from "@remotion/google-fonts/SpaceGrotesk";
import { loadFont as loadInter } from "@remotion/google-fonts/Inter";
import { loadFont as loadJetBrainsMono } from "@remotion/google-fonts/JetBrainsMono";

const display = loadSpaceGrotesk("normal", { weights: ["500", "700"], subsets: ["latin", "latin-ext"] });
const sans = loadInter("normal", { weights: ["400", "500", "600", "700"], subsets: ["latin", "latin-ext"] });
const mono = loadJetBrainsMono("normal", { weights: ["400", "500", "700"], subsets: ["latin", "latin-ext"] });

// Typographies : titres (Space Grotesk), texte (Inter), terminal et réglages (JetBrains Mono).
export const F = {
  display: display.fontFamily,
  sans: sans.fontFamily,
  mono: mono.fontFamily,
};

// Palette « nuit » et couleurs de sens, constantes d'une scène à l'autre :
// rouge = attaque et blocage, cyan = la protection, ambre = réglages et alertes,
// vert = accès qui fonctionne, violet = les clés SSH (module 2).
export const C = {
  ink0: "#03060C",
  ink1: "#070C17",
  ink2: "#0C1424",
  term: "#0A0F1A",
  panel: "rgba(14,21,36,0.92)",
  panelHi: "rgba(22,32,52,0.95)",
  line: "rgba(148,163,184,0.16)",
  lineHi: "rgba(148,163,184,0.34)",
  text: "#EAF0FA",
  dim: "#9AA9BF",
  faint: "#5E6E86",
  log: "#B9C6DA",
  logDim: "#5F6F88",
  red: "#FF4D5E",
  cyan: "#22D3EE",
  amber: "#FBBF24",
  green: "#34D399",
  violet: "#A78BFA",
};

export const alpha = (hex: string, a: number) => {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
};

export const glow = (hex: string, strength = 1) =>
  `0 0 ${18 * strength}px ${alpha(hex, 0.45)}, 0 0 ${48 * strength}px ${alpha(hex, 0.22)}`;

export const W = 1920;
export const H = 1080;
