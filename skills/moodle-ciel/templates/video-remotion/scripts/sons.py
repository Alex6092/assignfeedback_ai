"""Musique d'ambiance et bruitages de la vidéo, entièrement synthétisés (aucun échantillon externe,
donc aucun droit à gérer).

Usage (depuis le dossier video) : py scripts/sons.py
Lit src/timeline.json (minutage des scènes) ; écrit public/musique.wav et public/sfx/<nom>.wav.
À relancer si le minutage change (npm run timeline), pour recaler la musique sur les scènes.
"""
import json
import os

import numpy as np
import soundfile as sf
from scipy import signal

SR = 48000
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
rng = np.random.default_rng(22)


# ——— outils ———————————————————————————————————————————————————————————————
def n_of(d):
    return int(round(d * SR))


def tt(d):
    return np.arange(n_of(d)) / SR


def expdec(n, tau):
    return np.exp(-np.arange(n) / SR / tau)


def filt(x, kind, f, order=2):
    sos = signal.butter(order, f, kind, fs=SR, output="sos")
    return signal.sosfilt(sos, x, axis=0)


def noise(d):
    return rng.standard_normal(n_of(d))


def pad_to(x, n):
    return np.pad(x, (0, max(0, n - len(x))))[:n]


def fades(x, fi=0.002, fo=0.02):
    x = x.copy()
    a, b = n_of(fi), n_of(fo)
    if a:
        x[:a] *= np.linspace(0, 1, a)
    if b:
        x[-b:] *= np.linspace(1, 0, b)
    return x


def ir(d=1.0, tau=0.25, lp=6000, seed=1):
    r = np.random.default_rng(seed)
    n = n_of(d)
    h = r.standard_normal(n) * expdec(n, tau)
    h = filt(h, "lowpass", lp)
    h[: n_of(0.012)] = 0
    return h / np.sqrt(np.sum(h**2))


def verb(x, wet=0.25, d=1.0, tau=0.25, lp=6000, seed=1):
    h = ir(d, tau, lp, seed)
    y = signal.fftconvolve(x, h)
    out = np.zeros(len(y))
    out[: len(x)] += x * (1 - wet)
    return out + y * wet


def k_weight(x):
    b1, a1 = [1.53512485958697, -2.69169618940638, 1.19839281085285], [1.0, -1.69065929318241, 0.73248077421585]
    b2, a2 = [1.0, -2.0, 1.0], [1.0, -1.99004745483398, 0.99007225036621]
    return signal.lfilter(b2, a2, signal.lfilter(b1, a1, x, axis=0), axis=0)


def momentary_max(x):
    """Sonie « momentanée » maximale (fenêtres de 400 ms), en LUFS."""
    y = k_weight(x)
    if y.ndim == 1:
        y = y[:, None]
    p = np.sum(y**2, axis=1)
    w = n_of(0.4)
    if len(p) < w:
        p = np.pad(p, (0, w - len(p)))
    c = np.convolve(p, np.ones(w) / w, mode="valid")
    return -0.691 + 10 * np.log10(np.max(c) + 1e-12)


def integrated(x):
    y = k_weight(x)
    if y.ndim == 1:
        y = y[:, None]
    p = np.sum(y**2, axis=1)
    blk, hop = n_of(0.4), n_of(0.1)
    z = np.array([np.mean(p[i : i + blk]) for i in range(0, len(p) - blk, hop)])
    z = z[-0.691 + 10 * np.log10(z + 1e-12) > -70]
    rel = -0.691 + 10 * np.log10(np.mean(z)) - 10
    z = z[-0.691 + 10 * np.log10(z) > rel]
    return -0.691 + 10 * np.log10(np.mean(z))


def save_sfx(name, x, target=-18.0, ceil_db=-1.0):
    """Bruitage à sonie momentanée max = target (les volumes des scènes deviennent comparables)."""
    x = fades(np.asarray(x, dtype=np.float64))
    x *= 10 ** ((target - momentary_max(x)) / 20)
    pk = np.max(np.abs(x))
    if pk > 10 ** (ceil_db / 20):
        x *= 10 ** (ceil_db / 20) / pk
    sf.write(os.path.join(ROOT, "public", "sfx", name + ".wav"), x.astype(np.float32), SR, subtype="PCM_16")
    return len(x) / SR


def sine(f, d, ph=0.0):
    return np.sin(2 * np.pi * f * tt(d) + ph)


def sweep(f0, f1, d, curve="exp"):
    n = n_of(d)
    if curve == "exp":
        f = f0 * (f1 / f0) ** (np.arange(n) / n)
    else:
        f = np.linspace(f0, f1, n)
    return np.sin(2 * np.pi * np.cumsum(f) / SR)


def bell(f, d, tau=0.8):
    n = n_of(d)
    y = np.zeros(n)
    for ratio, amp, k in [(1, 1, 1), (2.76, 0.35, 0.6), (5.4, 0.18, 0.35), (8.93, 0.08, 0.2)]:
        y += amp * np.sin(2 * np.pi * f * ratio * tt(d)) * expdec(n, tau * k)
    return y * (1 - expdec(n, 0.002))


# ——— bruitages ———————————————————————————————————————————————————————————
def click(d=0.006, lo=2000, hi=7000, tau=0.0015):
    n = n_of(d)
    return filt(noise(d), "bandpass", [lo, hi]) * expdec(n, tau)


def key(heavy=False):
    d = 0.09
    n = n_of(d)
    x = pad_to(click(0.01, 1800, 6500, 0.0018), n)
    x += (0.9 if heavy else 0.45) * sine(150 if heavy else 210, d) * expdec(n, 0.012 if heavy else 0.008)
    x += 0.3 * pad_to(click(0.004, 3000, 9000, 0.0008), n)
    return x


def place(dst, src, at):
    i = n_of(at)
    j = min(len(dst), i + len(src))
    dst[i:j] += src[: j - i]


def sfx_clavier():
    x = np.zeros(n_of(1.0))
    t = 0.0
    for _ in range(10):
        place(x, key() * (0.7 + 0.3 * rng.random()), t)
        t += 0.06 + 0.06 * rng.random()
    return verb(x, 0.12, 0.4, 0.06)


def sfx_entree():
    return verb(key(heavy=True), 0.15, 0.5, 0.08)


def sfx_tic():
    d = 0.08
    n = n_of(d)
    return sine(2350, d) * expdec(n, 0.012) + 0.4 * pad_to(click(0.004), n)


def sfx_pop():
    d = 0.16
    n = n_of(d)
    x = sweep(900, 380, d) * expdec(n, 0.04)
    return verb(x + 0.3 * pad_to(click(0.004, 1500, 5000), n), 0.15, 0.4, 0.06)


def sfx_surligne():
    d = 0.28
    n = n_of(d)
    nz = noise(d)
    x = filt(nz, "bandpass", [2500, 7000])
    e = np.sin(np.pi * np.linspace(0, 1, n)) ** 1.5
    return x * e * 0.5


def sfx_allume():
    d = 0.6
    n = n_of(d)
    x = (sine(659.3, d) + 0.6 * sine(987.8, d) + 0.25 * sine(1318.5, d, 0.3)) * expdec(n, 0.22)
    x *= 1 - expdec(n, 0.008)
    return verb(x, 0.3, 1.0, 0.25)


def sfx_valide():
    d = 1.1
    x = np.zeros(n_of(d))
    for i, f in enumerate([1046.5, 1318.5, 1568.0]):
        place(x, bell(f, d - i * 0.07, 0.35) * 0.8, i * 0.07)
    return verb(x, 0.3, 1.2, 0.3)


def sfx_toc():
    d = 0.3
    n = n_of(d)
    body = sum(a * sine(f, d) * expdec(n, tau) for f, a, tau in [(170, 1.0, 0.05), (330, 0.6, 0.03), (560, 0.35, 0.02), (980, 0.15, 0.01)])
    x = body + 0.8 * pad_to(click(0.008, 600, 3500, 0.002), n)
    x = filt(x, "lowpass", 2600)
    return verb(x, 0.22, 0.6, 0.09, 3000)


def sfx_impact():
    d = 2.0
    n = n_of(d)
    f = 95 * (40 / 95) ** (np.clip(np.arange(n) / n_of(0.5), 0, 1))
    boom = np.sin(2 * np.pi * np.cumsum(f) / SR) * expdec(n, 0.45)
    hit = filt(noise(d), "lowpass", 1800) * expdec(n, 0.03)
    crack = filt(noise(d), "bandpass", [2000, 8000]) * expdec(n, 0.008)
    x = 1.0 * boom + 0.6 * hit + 0.25 * crack
    return verb(np.tanh(1.6 * x), 0.3, 1.6, 0.4, 2500)


def sfx_glisse():
    d = 0.45
    n = n_of(d)
    nz = noise(d)
    lo = filt(nz, "bandpass", [700, 1600])
    hi = filt(nz, "bandpass", [1600, 4000])
    k = np.linspace(0, 1, n)
    e = np.sin(np.pi * k) ** 2
    return (lo * (1 - k) + hi * k) * e * 0.6


def sfx_whoosh():
    d = 1.0
    n = n_of(d)
    nz = noise(d)
    bands = [filt(nz, "bandpass", [lo, lo * 2.2]) for lo in (300, 700, 1600, 3500)]
    k = np.linspace(0, 1, n)
    pos = np.clip(k * 3.2, 0, 3)
    x = np.zeros(n)
    for i, b in enumerate(bands):
        x += b * np.clip(1 - np.abs(pos - i), 0, 1)
    e = np.sin(np.pi * k) ** 1.2
    return verb(x * e, 0.25, 0.8, 0.2)


def sfx_scintille():
    d = 1.4
    x = np.zeros(n_of(d))
    for i in range(14):
        f = rng.choice([1760, 2093, 2349, 2637, 3136, 3520, 4186])
        place(x, bell(f, 0.6, 0.18) * (0.3 + 0.4 * rng.random()), 0.05 + i * 0.07 + 0.02 * rng.random())
    k = np.linspace(0, 1, len(x))
    return verb(x * np.sin(np.pi * np.clip(k * 1.1, 0, 1)), 0.4, 1.4, 0.4)


def sfx_erreur():
    d = 0.32
    x = np.zeros(n_of(d))
    for i, f in enumerate([466.2, 349.2]):
        s = signal.square(2 * np.pi * f * tt(0.11)) * 0.5
        s = filt(s, "lowpass", 1800) * np.sin(np.pi * np.linspace(0, 1, n_of(0.11)))
        place(x, s, i * 0.13)
    return verb(x, 0.15, 0.4, 0.06)


def sfx_curseur():
    d = 0.55
    x = np.zeros(n_of(d))
    ts = np.cumsum(np.concatenate([[0.0], 0.045 - 0.03 * np.sin(np.linspace(0, np.pi, 11))]))
    for i, t0 in enumerate(ts):
        place(x, sfx_tic()[: n_of(0.05)] * 0.5 * (0.6 + 0.4 * np.sin(np.pi * i / len(ts))), t0)
    return x


def sfx_declic():
    d = 0.35
    x = np.zeros(n_of(d))
    place(x, click(0.02, 900, 6000, 0.004), 0.0)
    place(x, 0.5 * sine(120, 0.05) * expdec(n_of(0.05), 0.01), 0.0)
    place(x, click(0.02, 1200, 7000, 0.003) * 0.8, 0.07)
    return verb(x, 0.2, 0.5, 0.08)


def sfx_balayage():
    d = 0.9
    n = n_of(d)
    k = np.linspace(0, 1, n)
    x = sweep(300, 1400, d) * 0.35 + filt(noise(d), "bandpass", [1500, 5000]) * 0.25
    return verb(x * np.sin(np.pi * k) ** 2, 0.3, 1.0, 0.25)


def sfx_verrou():
    d = 0.4
    x = np.zeros(n_of(d))
    m = sine(2150, 0.12) * expdec(n_of(0.12), 0.03) + 0.6 * sine(3420, 0.12) * expdec(n_of(0.12), 0.02)
    place(x, m * 0.5 + pad_to(click(0.01, 1500, 6000, 0.002), n_of(0.12)), 0.0)
    place(x, (pad_to(click(0.015, 500, 2500, 0.004), n_of(0.1)) + 0.6 * sine(240, 0.1) * expdec(n_of(0.1), 0.015)), 0.085)
    return verb(x, 0.15, 0.5, 0.07)


def sfx_deverrou():
    d = 0.7
    x = np.zeros(n_of(d))
    place(x, pad_to(click(0.015, 800, 4000, 0.004), n_of(0.05)), 0.0)
    place(x, bell(880, 0.5, 0.2) * 0.5, 0.06)
    place(x, bell(1174.7, 0.5, 0.2) * 0.5, 0.14)
    return verb(x, 0.25, 0.8, 0.2)


def sfx_grave():
    d = 2.0
    n = n_of(d)
    x = sine(55, d) + 0.5 * sine(110.3, d) + 0.25 * sine(164.8, d)
    x = np.tanh(1.5 * x) * (1 - expdec(n, 0.04)) * expdec(n, 0.7)
    return verb(filt(x, "lowpass", 900), 0.3, 1.8, 0.5, 1500)


def sfx_flux():
    d = 1.1
    x = np.zeros(n_of(d))
    t = 0.0
    while t < d - 0.03:
        f = 1400 + 2800 * rng.random()
        b = sine(f, 0.018) * np.hanning(n_of(0.018))
        place(x, b * (0.3 + 0.5 * rng.random()), t)
        t += 0.02 + 0.03 * rng.random()
    k = np.linspace(0, 1, len(x))
    return x * np.sin(np.pi * k) ** 0.5


def sfx_coupure():
    d = 0.8
    n = n_of(d)
    zap = sweep(1500, 70, 0.22) * expdec(n_of(0.22), 0.09)
    x = np.zeros(n)
    place(x, np.tanh(3 * zap), 0.0)
    place(x, filt(noise(0.06), "bandpass", [1000, 6000]) * expdec(n_of(0.06), 0.012) * 0.8, 0.0)
    return verb(x, 0.2, 0.6, 0.12)


def sfx_chute():
    d = 0.7
    x = np.zeros(n_of(d))
    place(x, sweep(1600, 320, 0.38) * np.linspace(0.2, 1, n_of(0.38)) * 0.4, 0.0)
    place(x, sfx_toc()[: n_of(0.25)] * 0.9, 0.38)
    return x


def sfx_alerte():
    d = 0.75
    x = np.zeros(n_of(d))
    for i, f in enumerate([659.3, 523.3, 659.3, 523.3]):
        s = signal.square(2 * np.pi * f * tt(0.13), 0.35) * 0.5
        s = filt(s, "lowpass", 2200) * np.sin(np.pi * np.linspace(0, 1, n_of(0.13))) ** 0.6
        place(x, s, i * 0.15)
    return verb(x, 0.2, 0.6, 0.12)


def sfx_panne():
    d = 1.0
    n = n_of(d)
    down = sweep(620, 70, 0.7) * np.linspace(1, 0, n_of(0.7))
    x = np.zeros(n)
    place(x, filt(down, "lowpass", 2000), 0.0)
    buzz = signal.sawtooth(2 * np.pi * 100 * tt(0.25)) * 0.4
    place(x, filt(buzz, "lowpass", 1500) * expdec(n_of(0.25), 0.08), 0.05)
    return verb(x, 0.2, 0.6, 0.15)


def sfx_montee():
    d = 0.45
    n = n_of(d)
    k = np.linspace(0, 1, n)
    x = sweep(200, 1800, d) * 0.4 + filt(noise(d), "highpass", 2000) * 0.3 * k
    return x * k**2


def sfx_glitch():
    d = 0.55
    x = np.zeros(n_of(d))
    t = 0.0
    while t < d - 0.03:
        seg = rng.uniform(0.012, 0.05)
        kind = rng.integers(3)
        if kind == 0:
            s = signal.square(2 * np.pi * rng.uniform(80, 900) * tt(seg)) * 0.6
        elif kind == 1:
            s = noise(seg) * 0.5
        else:
            s = sine(rng.uniform(1000, 4000), seg) * 0.5
        s = np.round(s * 6) / 6  # « bitcrush »
        place(x, s * rng.uniform(0.3, 1.0), t)
        t += seg + rng.uniform(0, 0.02)
    return filt(x, "bandpass", [120, 9000])


def sfx_accelere():
    d = 0.9
    x = np.zeros(n_of(d))
    t, g, i = 0.0, 0.11, 0
    while t < d - 0.05:
        f = 1800 + 120 * i
        place(x, sine(f, 0.03) * expdec(n_of(0.03), 0.006) * 0.6, t)
        t += g
        g = max(0.018, g * 0.82)
        i += 1
    return x


def sfx_echec():
    d = 0.3
    n = n_of(d)
    x = sweep(420, 260, 0.12) * expdec(n_of(0.12), 0.04)
    y = np.zeros(n)
    place(y, np.tanh(2 * x), 0.0)
    place(y, click(0.006, 2000, 8000, 0.0012) * 0.6, 0.0)
    return verb(y, 0.15, 0.4, 0.07)


def sfx_final():
    """Accord final (la majeur) : cloches échelonnées sur une nappe qui s'ouvre."""
    d = 3.4
    x = np.zeros(n_of(d))
    for i, f in enumerate([440.0, 554.4, 659.3, 880.0, 1108.7]):
        place(x, bell(f, d - i * 0.09, 0.9) * (0.5 - 0.05 * i), i * 0.09)
    pad = chord_pad([220.0, 329.6, 440.0, 554.4], d, cutoff=2400, att=0.4, rel=2.2, seed=5).mean(axis=1)
    return verb(x + 1.4 * pad, 0.35, 2.0, 0.6)


def sfx_souffle():
    d = 1.8
    n = n_of(d)
    x = filt(noise(d), "lowpass", 1100)
    k = np.linspace(0, 1, n)
    return x * np.sin(np.pi * k) ** 2 * 0.5


def chord_pad(freqs, d, cutoff=1500, att=0.8, rel=1.5, width=1.0, seed=0):
    """Nappe stéréo : dents de scie limitées en bande, désaccordées, filtrées."""
    n = n_of(d)
    x = np.zeros((n, 2))
    xs = np.linspace(0, 1, 2048, endpoint=False)
    r = np.random.default_rng(seed)
    for f in freqs:
        K = max(1, int(2600 // f))
        table = sum(np.sin(2 * np.pi * k * xs) / k for k in range(1, K + 1))
        for ch, dets in enumerate([(-9, 4), (-4, 9)]):
            for c in dets:
                fr = f * 2 ** (c * width / 1200)
                ph = (fr * np.arange(n) / SR + r.random()) % 1.0
                x[:, ch] += np.interp(ph, xs, table, period=1.0) / len(freqs)
    x = filt(x, "lowpass", cutoff)
    e = np.minimum(1, np.arange(n) / n_of(att)) * np.minimum(1, (n - np.arange(n)) / n_of(rel))
    return x * e[:, None] * 0.35


# ——— musique —————————————————————————————————————————————————————————————
NOTE = lambda name: 440 * 2 ** ((
    {"C": -9, "D": -7, "E": -5, "F": -4, "G": -2, "A": 0, "B": 2}[name[0]] + (1 if "#" in name else 0) + 12 * (int(name[-1]) - 4)
) / 12)


def chord(names):
    return [NOTE(n) for n in names.split()]


def musique(tl):
    fps = tl["fps"]
    total = tl["total"] / fps + 1.0
    sc = {s["id"]: s for s in tl["scenes"]}
    # Repères : carton-titre (facultatif), début du corps (1re scène à chapitre), carton de fin.
    first = tl["scenes"][0]
    t_titre = sc["titre"]["start"] / fps if "titre" in sc else (first["start"] + first["dur"]) / fps
    body = next((s for s in tl["scenes"] if s.get("chapitre")), tl["scenes"][min(1, len(tl["scenes"]) - 1)])
    t_journal = (body["start"] + body["overlapIn"]) / fps
    t_fin = sc["fin"]["start"] / fps if "fin" in sc else max(t_journal + 8, total - 5)
    N = n_of(total)
    mix = np.zeros((N, 2))
    bright = np.zeros(N)  # 0 = sombre, 1 = ouvert (filtre), selon l'ambiance des scènes
    level = {"nuit": 0.15, "attaque": 0.0, "alerte": 0.2, "neutre": 0.45, "protection": 0.8}
    for s in tl["scenes"]:
        a = n_of(s["start"] / fps)
        bright[a:] = level[s["ambiance"]]
    bright = np.convolve(bright, np.ones(n_of(1.5)) / n_of(1.5), mode="same")

    def put(x, at):
        i = n_of(at)
        j = min(N, i + len(x))
        mix[i:j] += x[: j - i]

    # 1. Nuit : bourdon grave + la mineur, très lent
    d0 = t_titre + 0.5
    drone = (np.sin(2 * np.pi * 55 * tt(d0)) * 0.5 + np.sin(2 * np.pi * 110.2 * tt(d0)) * 0.2)
    drone *= np.minimum(1, np.arange(len(drone)) / n_of(3)) * np.minimum(1, (len(drone) - np.arange(len(drone))) / n_of(1.0))
    put(np.stack([drone, drone], 1) * 0.5, 0.0)
    put(chord_pad(chord("A2 E3 A3 C4"), d0 + 1.0, cutoff=900, att=3.0, rel=1.5, seed=1), 0.0)
    # 2. Titre : nappe qui s'ouvre (fa majeur 7)
    if "titre" in sc:
        put(chord_pad(chord("F2 C3 E3 A3 C4"), 5.2, cutoff=2200, att=0.25, rel=2.0, seed=2) * 1.2, t_titre)
    # 3. Corps : la m – fa – do – sol, 8 s par accord (nappes qui se chevauchent)
    prog_ = ["A2 E3 G3 C4", "F2 C3 E3 A3", "C3 G3 C4 E4", "G2 D3 G3 B3"]
    t = t_journal - 1.0
    i = 0
    while t < t_fin + 1.0:
        put(chord_pad(chord(prog_[i % 4]), 10.0, cutoff=2400, att=2.0, rel=2.5, seed=10 + i), t)
        bass = NOTE(prog_[i % 4].split()[0]) / 2
        b = np.sin(2 * np.pi * bass * tt(9.5)) * 0.22
        b *= np.minimum(1, np.arange(len(b)) / n_of(1.5)) * np.minimum(1, (len(b) - np.arange(len(b))) / n_of(2.0))
        put(np.stack([b, b], 1), t)
        t += 8.0
        i += 1
    # 4. Fin : la sus2, longue résonance
    put(chord_pad(chord("A2 E3 B3 E4 A4"), total - t_fin + 0.5, cutoff=2600, att=0.6, rel=3.0, seed=99) * 1.3, t_fin - 0.3)

    # Filtre selon l'ambiance : fondu entre une version sombre et une version ouverte
    dark = filt(mix, "lowpass", 700)
    mix = dark * (1 - bright[:, None]) + mix * bright[:, None]

    # Cloches de nuit, rares et douces
    pent = [NOTE(n) for n in "A5 C6 D6 E6 G6 A6".split()]
    t = 2.0
    while t < total - 4:
        f = pent[rng.integers(len(pent))]
        b = bell(f, 3.0, 0.9) * (0.05 + 0.04 * rng.random())
        pan = rng.uniform(0.2, 0.8)
        put(np.stack([b * (1 - pan), b * pan], 1), t)
        t += rng.uniform(2.8, 5.5)

    # Pouls discret dans les scènes d'attaque
    for s in tl["scenes"]:
        if s["ambiance"] != "attaque":
            continue
        a, b_ = s["start"] / fps, (s["start"] + s["dur"]) / fps
        t = a + 0.3
        while t < b_ - 0.5:
            for k, amp in [(0.0, 1.0), (0.22, 0.6)]:
                th = np.sin(2 * np.pi * 52 * tt(0.18)) * expdec(n_of(0.18), 0.05) * 0.18 * amp
                put(np.stack([th, th], 1), t + k)
            t += 60 / 66

    # Réverbération stéréo
    out = np.zeros((N, 2))
    for ch in range(2):
        out[:, ch] = verb(mix[:, ch], 0.35, 3.5, 0.9, 5000, seed=ch + 3)[:N]
    out *= 10 ** ((-20.0 - integrated(out)) / 20)
    pk = np.max(np.abs(out))
    if pk > 0.89:
        out *= 0.89 / pk
    sf.write(os.path.join(ROOT, "public", "musique.wav"), out.astype(np.float32), SR, subtype="PCM_16")
    return total, integrated(out)


if __name__ == "__main__":
    os.makedirs(os.path.join(ROOT, "public", "sfx"), exist_ok=True)
    fns = {k[4:]: v for k, v in globals().items() if k.startswith("sfx_") and callable(v)}
    for name, fn in sorted(fns.items()):
        d = save_sfx(name, fn())
        print(f"sfx/{name}.wav  {d:.2f} s")
    tl = json.load(open(os.path.join(ROOT, "src", "timeline.json"), encoding="utf-8"))
    d, L = musique(tl)
    print(f"musique.wav  {d:.1f} s  {L:.1f} LUFS")
