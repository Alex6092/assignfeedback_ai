import React from "react";
import { Composition } from "remotion";
import { Video } from "./Video";
import { TL } from "./timeline";

// Durée calculée par scripts/timeline.mjs à partir des voix enregistrées (1080p, 30 images/s).
export const RemotionRoot: React.FC = () => (
  <Composition
    id="Video"
    component={Video}
    durationInFrames={TL.total}
    fps={TL.fps}
    width={1920}
    height={1080}
    defaultProps={{ musique: true, bruitages: true }}
  />
);
