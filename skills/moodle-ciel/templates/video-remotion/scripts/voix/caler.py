"""Calage mot à mot des enregistrements de l'enseignant sur le texte du script.

Usage (depuis le dossier video) : py scripts/voix/caler.py
1. décode voix/NN - Titre.m4a (ou .wav/.mp3) en WAV 16 kHz et 48 kHz (out/voix-travail/) ;
2. fait reconnaître chaque bloc par la reconnaissance vocale de Windows, contrainte au texte de
   src/texte.json : le moteur retrouve alors la position de chaque mot ;
3. si un passage est rejeté (bruit, hésitation), recommence pause par pause ;
4. écrit out/voix-travail/timings.json (début et fin de chaque mot, début et fin de la parole).
Ensuite : py scripts/voix/traiter.py, puis npm run timeline.
Un écart entre le texte dit et le script est signalé : corriger src/texte.json (le texte réellement
prononcé) et relancer.
"""
import json
import os
import re
import subprocess
import sys
import unicodedata

import numpy as np
import soundfile as sf
from scipy import signal

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WORK = os.path.join(ROOT, "out", "voix-travail")
HERE = os.path.dirname(os.path.abspath(__file__))

# Mots techniques : graphie « à la française » de leur prononciation, pour la reconnaissance.
# Valeurs par défaut, complétées ou remplacées par src/prononciation.json (propre à chaque vidéo).
PRON = {
    "ssh": "esse esse ache", "sshd": "esse esse ache dé", "admin": "admine", "test": "teste",
    "root": "route", "invalid": "invalide", "user": "youzeur", "preauth": "pré ôte",
    "fail2ban": "fèle tou bane", "fail2ban-client": "fèle tou bane claïente", "status": "statusse",
    "maxretry": "maxe ritraï", "findtime": "faïnde taïme", "bantime": "bane taïme",
    "vm": "vé aime", "debian": "débiane", "12": "douze", "auth.log": "ôte point logue",
    "backend": "bake ènde", "systemd": "système dé", "unbanip": "eune bane aïe pé",
    "ban": "bane", "unban": "eune bane", "jail.local": "jèle point locale",
}
_extra = os.path.join(ROOT, "src", "prononciation.json")
if os.path.exists(_extra):
    PRON.update({k.lower(): v for k, v in json.load(open(_extra, encoding="utf-8")).items() if not k.startswith("_")})
PUNCT_ONLY = re.compile(r"^[«»—:?!;…,.]+$")


def slug(name):
    base = os.path.splitext(name)[0]
    num, _, title = base.partition(" - ")
    t = unicodedata.normalize("NFKD", title).encode("ascii", "ignore").decode().lower()
    return f"{num.strip()}-" + re.sub(r"[^a-z0-9]+", "-", t).strip("-")


def tokens(text):
    out = []
    for raw in text.split():
        if PUNCT_ONLY.match(raw):
            if out:
                out[-1]["pause"] = True
            continue
        key = raw.strip("«»…:,.?!;—").lower()
        if not key:
            continue
        out.append({"w": raw, "spoken": PRON.get(key, key).split(), "pause": bool(re.search(r"[.,:;?!…]$", raw))})
    return out


def clauses(toks):
    cl, cur = [], []
    for t in toks:
        cur += t["spoken"]
        if t["pause"]:
            cl.append(" ".join(cur))
            cur = []
    if cur:
        cl.append(" ".join(cur))
    return cl


def decode(src, dst, rate):
    subprocess.run(
        ["npx", "remotion", "ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", src, "-ac", "1", "-ar", str(rate), "-c:a", "pcm_s16le", dst],
        check=True, cwd=ROOT, shell=(os.name == "nt"),
    )


def run_sapi(jobs):
    path = os.path.join(WORK, "taches.tsv")
    with open(path, "w", encoding="utf-8") as f:
        for j in jobs:
            f.write("\t".join(j) + "\n")
    subprocess.run(["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", os.path.join(HERE, "aligner.ps1"), "-Jobs", path], check=True)


def read_sapi(path, offset=0.0):
    words, cur, rejected = [], None, 0
    for line in open(path, encoding="utf-8"):
        p = line.rstrip("\n").split("\t")
        if p[0] == "R":
            cur = float(p[1]) + offset
        elif p[0] == "W" and cur is not None:
            s = cur + float(p[1])
            words.append((p[4].lower(), s, s + float(p[2]), float(p[3])))
        elif p[0] == "X":
            rejected += 1
    return words, rejected


def envelope(path):
    x, sr = sf.read(path, dtype="float64")
    y = signal.sosfiltfilt(signal.butter(4, [100, 4000], btype="band", fs=sr, output="sos"), x)
    hop, win = int(0.005 * sr), int(0.020 * sr)
    n = 1 + (len(y) - win) // hop
    rms = np.array([np.sqrt(np.mean(y[i * hop:i * hop + win] ** 2)) for i in range(n)])
    db = 20 * np.log10(rms + 1e-10)
    floor, sp = np.percentile(db, 8), np.percentile(db, 90)
    return db, floor + 0.30 * (sp - floor), len(x) / sr


def segments(db, thr, min_pause=0.25, min_run=0.08):
    """Passages parlés séparés par des pauses d'au moins min_pause secondes (claquements ignorés)."""
    act = db > thr
    # supprime les îlots d'activité trop courts (claquement de langue, clic du téléphone)
    i = 0
    while i < len(act):
        if act[i]:
            j = i
            while j < len(act) and act[j]:
                j += 1
            if (j - i) * 0.005 < min_run:
                act[i:j] = False
            i = j
        else:
            i += 1
    idx = np.where(act)[0]
    segs, start, last = [], idx[0], idx[0]
    for i in idx[1:]:
        if (i - last) * 0.005 >= min_pause:
            segs.append((start * 0.005, last * 0.005 + 0.02))
            start = i
        last = i
    segs.append((start * 0.005, last * 0.005 + 0.02))
    return segs


def main():
    texte = json.load(open(os.path.join(ROOT, "src", "texte.json"), encoding="utf-8"))
    for d in ("16k", "48k", "phrases", "sapi", "segments"):
        os.makedirs(os.path.join(WORK, d), exist_ok=True)
    files = {slug(f): f for f in sorted(os.listdir(os.path.join(ROOT, "voix"))) if re.match(r"^\d\d - .+\.(m4a|wav|mp3)$", f)}
    missing = [b for b in texte if b not in files]
    if missing:
        sys.exit(f"Enregistrements manquants dans voix/ : {', '.join(missing)}")
    toks = {}
    jobs = []
    for bloc in texte:
        src = os.path.join(ROOT, "voix", files[bloc])
        for rate, sub in ((16000, "16k"), (48000, "48k")):
            decode(src, os.path.join(WORK, sub, bloc + ".wav"), rate)
        toks[bloc] = tokens(texte[bloc])
        ph = os.path.join(WORK, "phrases", bloc + ".txt")
        open(ph, "w", encoding="utf-8").write("\n".join(clauses(toks[bloc])) + "\n")
        jobs.append([os.path.join(WORK, "16k", bloc + ".wav"), "grammaire", ph, os.path.join(WORK, "sapi", bloc + ".tsv")])
        print("décodé :", files[bloc], "→", bloc)
    run_sapi(jobs)

    out = {}
    for bloc, tl in toks.items():
        spoken = [w for t in tl for w in t["spoken"]]
        rec, rejected = read_sapi(os.path.join(WORK, "sapi", bloc + ".tsv"))
        db, thr, dur = envelope(os.path.join(WORK, "48k", bloc + ".wav"))
        if [r[0] for r in rec] != spoken:
            # Repli : reconnaissance passage par passage (entre deux pauses).
            x16, sr16 = sf.read(os.path.join(WORK, "16k", bloc + ".wav"), dtype="int16")
            pad = np.zeros(int(0.4 * sr16), dtype=np.int16)
            seg_jobs, segs = [], segments(db, thr)
            for i, (a, b) in enumerate(segs):
                a0, b0 = max(0, a - 0.12), min(dur, b + 0.12)
                wav = os.path.join(WORK, "segments", f"{bloc}-{i}.wav")
                sf.write(wav, np.concatenate([pad, x16[int(a0 * sr16):int(b0 * sr16)], pad]), sr16, subtype="PCM_16")
                seg_jobs.append([wav, "grammaire", os.path.join(WORK, "phrases", bloc + ".txt"), wav[:-4] + ".tsv"])
            run_sapi(seg_jobs)
            rec = []
            for i, (a, b) in enumerate(segs):
                r, _ = read_sapi(os.path.join(WORK, "segments", f"{bloc}-{i}.tsv"), offset=max(0, a - 0.12) - 0.4)
                rec += r
            print(f"{bloc} : {rejected} passage(s) rejeté(s) d'un seul tenant, repris pause par pause")
        if [r[0] for r in rec] != spoken:
            got = " ".join(r[0] for r in rec)
            sys.exit(f"{bloc} : le texte reconnu diffère du script.\n  script  : {' '.join(spoken)}\n  reconnu : {got}\n"
                     "Corrige src/texte.json avec le texte réellement dit (ou réenregistre le bloc), puis relance.")
        words, k = [], 0
        for t in tl:
            n = len(t["spoken"])
            words.append({"w": t["w"], "s": round(rec[k][1], 3), "e": round(rec[k + n - 1][2], 3), "c": round(min(r[3] for r in rec[k:k + n]), 2)})
            k += n
        i0 = int(max(0, words[0]["s"] - 0.25) / 0.005)
        act = np.where(db[i0:] > thr)[0]
        s0 = (i0 + act[0]) * 0.005 if len(act) else words[0]["s"]
        i1 = int(min(dur, words[-1]["e"] + 0.40) / 0.005)
        act = np.where(db[:i1] > thr)[0]
        s1 = act[-1] * 0.005 + 0.020 if len(act) else words[-1]["e"]
        out[bloc] = {"dur": round(dur, 3), "speech": [round(s0, 3), round(s1, 3)], "words": words}
        print(f"{bloc} : {len(words)} mots calés, parole {s0:.2f}–{s1:.2f} s")
    json.dump(out, open(os.path.join(WORK, "timings.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    main()
