"""Prépare les voix pour la vidéo : recoupe chaque bloc autour de la parole, compresse doucement,
égalise le volume (BS.1770, cible -17 LUFS), limite les crêtes, et écrit les temps des mots
relatifs au fichier coupé.

Usage (depuis le dossier video, après scripts/voix/caler.py) : py scripts/voix/traiter.py
Entrées : out/voix-travail/48k/<bloc>.wav et out/voix-travail/timings.json
Sorties : public/voix/<bloc>.wav et src/voix.json ; ensuite : npm run timeline.
"""
import json, os, sys
import numpy as np
import soundfile as sf
from scipy import signal

PROJ = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
S = os.path.join(PROJ, "out", "voix-travail")
TARGET = -17.0
PRE, POST = 0.10, 0.28


def k_weight(x, sr):
    b1, a1 = [1.53512485958697, -2.69169618940638, 1.19839281085285], [1.0, -1.69065929318241, 0.73248077421585]
    b2, a2 = [1.0, -2.0, 1.0], [1.0, -1.99004745483398, 0.99007225036621]
    return signal.lfilter(b2, a2, signal.lfilter(b1, a1, x))


def loudness(x, sr):
    y = k_weight(x, sr)
    blk, hop = int(0.4 * sr), int(0.1 * sr)
    z = np.array([np.mean(y[i:i + blk] ** 2) for i in range(0, len(y) - blk, hop)])
    z = z[-0.691 + 10 * np.log10(z + 1e-12) > -70]
    rel = -0.691 + 10 * np.log10(np.mean(z)) - 10
    z = z[(-0.691 + 10 * np.log10(z)) > rel]
    return -0.691 + 10 * np.log10(np.mean(z))


def smooth_env(level_db, sr, att, rel):
    """Suivi d'enveloppe (dB) avec attaque et relâchement distincts."""
    a, r = np.exp(-1 / (att * sr)), np.exp(-1 / (rel * sr))
    out = np.empty_like(level_db)
    cur = level_db[0]
    for i, v in enumerate(level_db):
        k = a if v > cur else r
        cur = v + (cur - v) * k
        out[i] = cur
    return out


def compress(x, sr, thr_db, ratio=2.5, att=0.008, rel=0.140):
    """Compresseur à enveloppe RMS courte : réduit l'écart entre syllabes fortes et faibles."""
    rms = np.sqrt(signal.lfilter([1 - np.exp(-1 / (0.005 * sr))], [1, -np.exp(-1 / (0.005 * sr))], x ** 2) + 1e-12)
    lvl = 20 * np.log10(rms)
    env = smooth_env(lvl, sr, att, rel)
    over = np.maximum(0, env - thr_db)
    gr = -over * (1 - 1 / ratio)
    return x * 10 ** (gr / 20), gr


def limit(x, sr, ceil_db=-1.5):
    ceil = 10 ** (ceil_db / 20)
    g = np.minimum(1.0, ceil / np.maximum(np.abs(x), 1e-9))
    look = int(0.003 * sr)
    g = np.minimum.reduce([np.roll(g, -k) for k in range(look)])
    r = np.exp(-1 / (0.06 * sr))
    out = np.empty_like(g)
    cur = 1.0
    for i, gi in enumerate(g):
        cur = gi if gi < cur else gi + (cur - gi) * r
        out[i] = cur
    return x * out, out


def main(proj):
    tim = json.load(open(os.path.join(S, "timings.json"), encoding="utf-8"))
    os.makedirs(os.path.join(proj, "public", "voix"), exist_ok=True)
    res = {}
    for bloc, v in tim.items():
        x, sr = sf.read(os.path.join(S, "48k", f"{bloc}.wav"), dtype="float64")
        s0 = max(0.0, v["speech"][0] - PRE)
        s1 = min(v["dur"], v["speech"][1] + POST)
        y = x[int(s0 * sr):int(s1 * sr)].copy()
        # coupe-bas doux à 80 Hz (souffle, manipulations du téléphone)
        y = signal.sosfiltfilt(signal.butter(2, 80, "highpass", fs=sr, output="sos"), y)
        L0 = loudness(y, sr)
        y *= 10 ** ((TARGET - L0) / 20)
        # compression douce au-dessus du niveau moyen de parole
        y, gr = compress(y, sr, thr_db=TARGET - 3)
        y *= 10 ** ((TARGET - loudness(y, sr)) / 20)
        y, g = limit(y, sr)
        fi, fo = int(0.015 * sr), int(0.060 * sr)
        y[:fi] *= np.linspace(0, 1, fi)
        y[-fo:] *= np.linspace(1, 0, fo) ** 2
        lim_ms = np.sum(g < 10 ** (-1 / 20)) / sr * 1000
        sf.write(os.path.join(proj, "public", "voix", bloc + ".wav"), y.astype(np.float32), sr, subtype="PCM_24")
        words = [{"w": w["w"], "s": round(w["s"] - s0, 3), "e": round(w["e"] - s0, 3)} for w in v["words"]]
        res[bloc] = {"file": f"voix/{bloc}.wav", "dur": round(len(y) / sr, 3), "words": words}
        print(f"{bloc}: {s0:.2f}-{s1:.2f} -> {len(y)/sr:.2f}s | source {L0:.1f} LUFS | compression max "
              f"{-gr.min():.1f} dB | limiteur >1 dB pendant {lim_ms:.0f} ms | final {loudness(y, sr):.1f} LUFS")
    json.dump(res, open(os.path.join(proj, "src", "voix.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print("total voix:", round(sum(r["dur"] for r in res.values()), 2), "s")


if __name__ == "__main__":
    main(PROJ)
