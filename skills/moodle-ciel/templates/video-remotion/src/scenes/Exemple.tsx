import React from "react";
import { AbsoluteFill, useCurrentFrame } from "remotion";
import { pop, prog } from "../anim";
import { Line, TermLines, bashPrompt } from "../components/Term";
import { TermWindow } from "../components/Window";
import { Chip, Heading, WordsIn } from "../components/ui";
import { Cue, Voice, useVoice } from "../timeline";
import { C, F } from "../theme";

/**
 * Scène d'exemple : chaque apparition est accrochée à un mot de la voix (bloc « 02-exemple »).
 * Modèle à copier pour une nouvelle scène : un composant + une liste de bruitages, tous deux
 * calculés à partir des mêmes repères (fonction `times`).
 */
const times = (v: Voice) => ({
  tJournal: v.at("journal"),
  tCommande: v.at("commande"),
  tJournalctl: v.at("journalctl"),
  tTentative: v.at("tentative"),
  tAdresse: v.at("l'adresse"),
});

export const sfx = (v: Voice): Cue[] => {
  const t = times(v);
  return [
    { at: t.tCommande, src: "clavier", volume: 0.35 },
    { at: t.tAdresse, src: "surligne", volume: 0.45 },
  ];
};

export const Exemple: React.FC = () => {
  const f = useCurrentFrame();
  const v = useVoice();
  const t = times(v);
  const ip = { at: t.tAdresse, color: C.red, fg: "#FFC2C8" };
  const lines: Line[] = [
    { at: 0, prompt: bashPrompt(), segs: [{ t: "sudo journalctl -u ssh", c: C.text }], type: [t.tCommande, t.tJournalctl + 8] },
    ...[0, 1, 2, 3].map((i) => ({
      at: t.tTentative + i * 4,
      segs: [
        { t: `Oct 23 03:00:0${i + 1} serveur sshd[11${i}2]: `, c: C.logDim },
        { t: "Invalid user admin from " },
        { t: "203.0.113.77", hl: ip },
        { t: ` port 5011${i}` },
      ],
    })),
  ];
  return (
    <AbsoluteFill>
      <Heading at={t.tJournal - 4}>Lire le journal du service SSH</Heading>
      <TermWindow x={96} y={240} w={1100} h={560} title="debian@serveur: ~" accent={f >= t.tAdresse ? C.red : undefined}>
        <TermLines lines={lines} size={20} maxLines={14} />
      </TermWindow>
      {f >= t.tJournalctl ? (
        <div style={{ position: "absolute", left: 1260, top: 300, transform: `scale(${0.6 + 0.4 * pop(f, t.tJournalctl)})`, opacity: prog(f, t.tJournalctl, t.tJournalctl + 6) }}>
          <Chip color={C.cyan} size={26}>
            journalctl
          </Chip>
          <div style={{ fontFamily: F.sans, fontSize: 26, color: C.text, marginTop: 14, width: 520 }}>
            <WordsIn text="affiche chaque tentative, avec son adresse" at={t.tTentative} per={3} />
          </div>
        </div>
      ) : null}
    </AbsoluteFill>
  );
};
