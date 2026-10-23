import React from "react";
import { Cue, Voice } from "../timeline";
import * as EX from "./Exemple";
import * as FIN from "./Fin";
import * as K from "./Karaoke";
import * as T from "./Titre";

type SceneModule = { Scene: React.FC; sfx?: (v: Voice) => Cue[] };

/**
 * Scènes par identifiant (voir scenes.json) : composant et bruitages. Une scène pas encore écrite
 * peut utiliser Karaoke (texte du bloc au rythme de la voix) pour vérifier le calage.
 */
export const SCENES: Record<string, SceneModule> = {
  accroche: { Scene: K.Karaoke },
  titre: { Scene: T.Titre, sfx: T.sfx },
  exemple: { Scene: EX.Exemple, sfx: EX.sfx },
  fin: { Scene: FIN.Fin, sfx: FIN.sfx },
};
