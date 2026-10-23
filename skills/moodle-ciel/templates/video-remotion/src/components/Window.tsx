import React from "react";
import { C, F, alpha } from "../theme";

type Box = { x: number; y: number; w: number; h: number };

const Controls: React.FC<{ color: string }> = ({ color }) => (
  <div style={{ display: "flex", gap: 26, alignItems: "center", paddingRight: 20 }}>
    <svg width="12" height="12" viewBox="0 0 12 12">
      <line x1="1" y1="6" x2="11" y2="6" stroke={color} strokeWidth="1.3" />
    </svg>
    <svg width="12" height="12" viewBox="0 0 12 12">
      <rect x="1.5" y="1.5" width="9" height="9" fill="none" stroke={color} strokeWidth="1.3" />
    </svg>
    <svg width="12" height="12" viewBox="0 0 12 12">
      <path d="M1.5 1.5 L10.5 10.5 M10.5 1.5 L1.5 10.5" stroke={color} strokeWidth="1.3" />
    </svg>
  </div>
);

/** Petite icône d'onglet : manchot pour une session Linux, chevron pour PowerShell. */
const TabIcon: React.FC<{ kind: "linux" | "ps" }> = ({ kind }) =>
  kind === "ps" ? (
    <div
      style={{
        width: 18,
        height: 18,
        borderRadius: 4,
        background: "#2B5797",
        color: "#fff",
        fontFamily: F.mono,
        fontSize: 11,
        fontWeight: 700,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      {">_"}
    </div>
  ) : (
    <svg width="18" height="18" viewBox="0 0 18 18">
      <ellipse cx="9" cy="10" rx="6" ry="7.5" fill="#1B1F27" stroke="#9AA9BF" strokeWidth="1" />
      <ellipse cx="9" cy="11.5" rx="3.8" ry="5" fill="#E8EDF5" />
      <circle cx="7.3" cy="6.2" r="1" fill="#E8EDF5" />
      <circle cx="10.7" cy="6.2" r="1" fill="#E8EDF5" />
      <path d="M7.6 8 L9 9.2 L10.4 8 Z" fill="#F5B83D" />
    </svg>
  );

/**
 * Fenêtre Windows Terminal (les postes de la salle sont sous Windows) : onglet, contrôles,
 * fond sombre. `icon` : session SSH vers la VM (linux) ou PowerShell local (ps).
 */
export const TermWindow: React.FC<
  Box & {
    title: string;
    icon?: "linux" | "ps";
    style?: React.CSSProperties;
    accent?: string;
    children: React.ReactNode;
    pad?: string;
  }
> = ({ x, y, w, h, title, icon = "linux", style, accent, children, pad = "18px 24px" }) => (
  <div
    style={{
      position: "absolute",
      left: x,
      top: y,
      width: w,
      height: h,
      borderRadius: 14,
      overflow: "hidden",
      background: C.term,
      border: `1px solid ${accent ? alpha(accent, 0.55) : C.lineHi}`,
      boxShadow: `0 30px 80px rgba(0,0,0,0.55), 0 0 0 1px rgba(0,0,0,0.4)${accent ? `, 0 0 40px ${alpha(accent, 0.18)}` : ""}`,
      display: "flex",
      flexDirection: "column",
      ...style,
    }}
  >
    <div style={{ height: 42, background: "#121925", display: "flex", alignItems: "flex-end", justifyContent: "space-between" }}>
      <div style={{ display: "flex", alignItems: "flex-end", gap: 10, paddingLeft: 10 }}>
        <div
          style={{
            height: 34,
            background: C.term,
            borderRadius: "9px 9px 0 0",
            display: "flex",
            alignItems: "center",
            gap: 10,
            padding: "0 14px 0 12px",
            minWidth: 230,
          }}
        >
          <TabIcon kind={icon} />
          <div style={{ fontFamily: F.sans, fontSize: 15, color: "#D6DEEA", flex: 1, whiteSpace: "nowrap" }}>{title}</div>
          <div style={{ fontFamily: F.sans, fontSize: 14, color: C.faint, marginLeft: 12 }}>✕</div>
        </div>
        <div style={{ fontFamily: F.sans, fontSize: 18, color: C.faint, paddingBottom: 7 }}>+</div>
      </div>
      <div style={{ height: 42, display: "flex", alignItems: "center" }}>
        <Controls color="#8C9AB0" />
      </div>
    </div>
    <div style={{ flex: 1, position: "relative", padding: pad, overflow: "hidden" }}>{children}</div>
  </div>
);

/** Fenêtre de VirtualBox avec la console de la VM (écran texte, hors réseau). */
export const VBoxWindow: React.FC<
  Box & { title: string; style?: React.CSSProperties; screenStyle?: React.CSSProperties; children: React.ReactNode }
> = ({ x, y, w, h, title, style, screenStyle, children }) => (
  <div
    style={{
      position: "absolute",
      left: x,
      top: y,
      width: w,
      height: h,
      borderRadius: 8,
      overflow: "hidden",
      background: "#000",
      border: "1px solid #6B7280",
      boxShadow: "0 30px 80px rgba(0,0,0,0.6)",
      display: "flex",
      flexDirection: "column",
      ...style,
    }}
  >
    <div style={{ height: 36, background: "#F3F4F6", display: "flex", alignItems: "center", justifyContent: "space-between" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, paddingLeft: 12 }}>
        <svg width="18" height="18" viewBox="0 0 18 18">
          <path d="M9 1 L16 5 V13 L9 17 L2 13 V5 Z" fill="#1E5AA8" />
          <path d="M9 1 L16 5 L9 9 L2 5 Z" fill="#3B82D6" />
          <path d="M9 9 V17" stroke="#93C5FD" strokeWidth="0.8" />
        </svg>
        <div style={{ fontFamily: F.sans, fontSize: 14, color: "#111827" }}>{title}</div>
      </div>
      <Controls color="#374151" />
    </div>
    <div
      style={{
        height: 28,
        background: "#FAFAFA",
        borderBottom: "1px solid #E5E7EB",
        display: "flex",
        alignItems: "center",
        gap: 22,
        paddingLeft: 14,
        fontFamily: F.sans,
        fontSize: 13,
        color: "#1F2937",
      }}
    >
      {["Fichier", "Machine", "Écran", "Entrée", "Périphériques", "Aide"].map((m) => (
        <span key={m}>{m}</span>
      ))}
    </div>
    <div style={{ flex: 1, position: "relative", background: "#000", padding: "16px 20px", overflow: "hidden", ...screenStyle }}>
      {children}
    </div>
    <div
      style={{
        height: 26,
        background: "#F3F4F6",
        borderTop: "1px solid #E5E7EB",
        display: "flex",
        alignItems: "center",
        justifyContent: "flex-end",
        gap: 8,
        paddingRight: 12,
      }}
    >
      {new Array(9).fill(0).map((_, i) => (
        <div key={i} style={{ width: 14, height: 14, borderRadius: 3, background: i === 4 ? "#60A5FA" : "#9CA3AF", opacity: 0.8 }} />
      ))}
      <div style={{ fontFamily: F.sans, fontSize: 12, color: "#374151", marginLeft: 6 }}>Ctrl droite</div>
    </div>
  </div>
);

/** Panneau générique (carte), avec titre optionnel. */
export const Panel: React.FC<
  Box & {
    title?: React.ReactNode;
    accent?: string;
    style?: React.CSSProperties;
    children?: React.ReactNode;
    pad?: number;
  }
> = ({ x, y, w, h, title, accent, style, children, pad = 24 }) => (
  <div
    style={{
      position: "absolute",
      left: x,
      top: y,
      width: w,
      height: h,
      borderRadius: 20,
      background: C.panel,
      border: `1px solid ${accent ? alpha(accent, 0.45) : C.line}`,
      boxShadow: `0 24px 60px rgba(0,0,0,0.45)${accent ? `, 0 0 36px ${alpha(accent, 0.12)}` : ""}`,
      padding: pad,
      ...style,
    }}
  >
    {title ? (
      <div
        style={{
          fontFamily: F.sans,
          fontSize: 17,
          fontWeight: 600,
          letterSpacing: 1.6,
          textTransform: "uppercase",
          color: accent ?? C.dim,
          marginBottom: 16,
        }}
      >
        {title}
      </div>
    ) : null}
    {children}
  </div>
);
