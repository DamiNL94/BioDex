#!/usr/bin/env python3
# ================================================================================================
# Referencia independiente de las series temporales para shared/iucn-cases.json › «series».
#   python backend/bin/series-reference.py            → imprime los casos (JSON)
#   python backend/bin/series-reference.py --write    → los escribe en shared/iucn-cases.json (sustituye «series»)
# Con statsmodels (pip install statsmodels) ajusta el GLM de Poisson con efecto sitio (y con factor año para el índice)
# y el cuasi-Poisson con φ = χ² de Pearson / gl, igual que shared/iucn-series.js y backend/Lib/series.php. Sin
# statsmodels solo genera los casos con SOLUCIÓN ANALÍTICA (dos sitios sin ruido: r exacta, EE = 1/√Σ μ (t − t̄_s)²,
# índice = suma de las curvas) y avisa de los que se salta.
# Los datos van escritos aquí (nada aleatorio): misma entrada, misma salida.
# 11/11/2026: generado con python:3.12-slim + statsmodels 0.15.0 (docker run --rm -v …:/w python:3.12-slim).
# ================================================================================================
import json
import math
import sys
from pathlib import Path

try:
    import numpy as np
    import statsmodels.api as sm
    HAVE_SM = True
except ImportError:  # pragma: no cover
    HAVE_SM = False

CASES = Path(__file__).resolve().parents[2] / "shared" / "iucn-cases.json"
Z95 = 1.959963984540054


def jsround(v, d):
    """Math.round(v · 10^d) / 10^d de JS (medio hacia +∞)."""
    f = 10 ** d
    return math.floor(v * f + 0.5) / f


def window_years(gen):
    w = 10.0
    if gen:
        w = max(w, 3 * gen)
    return min(w, 100.0)


def reduction(r, T):
    return max(0.0, jsround(100 * (1 - math.exp(r * T)), 2))


def prepare(obs):
    """Máximo por sitio-año; sitios con ≥ 2 años y algún recuento > 0."""
    cell = {}
    for o in obs:
        k = (o["site"], o["year"])
        if k not in cell or o["mature"] > cell[k]:
            cell[k] = o["mature"]
    sites = sorted({s for s, _ in cell})
    used = []
    for s in sites:
        ys = [y for (ss, y) in cell if ss == s]
        if len(ys) >= 2 and any(cell[(s, y)] > 0 for y in ys):
            used.append(s)
    rows = sorted(((s, y, cell[(s, y)]) for (s, y) in cell if s in used), key=lambda t: (used.index(t[0]), t[1]))
    return used, rows


def analytic(obs, gen):
    """Dos (o más) sitios sin ruido: recuentos exactamente b_s · e^(r (t − t0))."""
    used, rows = prepare(obs)
    years = sorted({y for _, y, _ in rows})
    # r exacta de cualquier sitio con dos años.
    s0 = [t for t in rows if t[0] == used[0]]
    r = math.log(s0[1][2] / s0[0][2]) / (s0[1][1] - s0[0][1])
    info = 0.0
    for s in used:
        pts = [(y, v) for ss, y, v in rows if ss == s]
        w = sum(v for _, v in pts)
        tb = sum(v * y for y, v in pts) / w
        info += sum(v * (y - tb) ** 2 for y, v in pts)
    se = 1 / math.sqrt(info)
    index = {}
    for y in years:
        # Índice = suma de las curvas de todos los sitios (también de los que no se visitaron ese año).
        tot = 0.0
        for s in used:
            pts = [(yy, v) for ss, yy, v in rows if ss == s]
            yy, v = pts[0]
            tot += v * math.exp(r * (y - yy))
        index[y] = tot
    return r, se, 1.0, index, years


def fitted(obs, gen):
    used, rows = prepare(obs)
    years = sorted({y for _, y, _ in rows})
    ns = len(used)
    tm = sum(years) / len(years)
    X = np.zeros((len(rows), ns + 1))
    yv = np.array([v for _, _, v in rows], dtype=float)
    for i, (s, y, _) in enumerate(rows):
        X[i, used.index(s)] = 1
        X[i, ns] = y - tm
    res = sm.GLM(yv, X, family=sm.families.Poisson()).fit(tol=1e-12, maxiter=200)
    df = len(rows) - (ns + 1)
    phi_raw = res.pearson_chi2 / df if df > 0 else None
    phi = max(1.0, phi_raw if phi_raw is not None else 1.0)
    r = float(res.params[ns])
    se = math.sqrt(phi) * float(res.bse[ns])   # bse de Poisson (φ = 1) escalado por √φ
    # Índice: factor año sobre los años con algún recuento > 0.
    tot = {y: sum(v for _, yy, v in rows if yy == y) for y in years}
    fy = [y for y in years if tot[y] > 0]
    rows_i = [t for t in rows if tot[t[1]] > 0]
    Xi = np.zeros((len(rows_i), ns + len(fy) - 1))
    for i, (s, y, _) in enumerate(rows_i):
        Xi[i, used.index(s)] = 1
        if y != fy[0]:
            Xi[i, ns + fy.index(y) - 1] = 1
    ri = sm.GLM(np.array([v for _, _, v in rows_i], dtype=float), Xi, family=sm.families.Poisson()).fit(tol=1e-12, maxiter=200)
    index = {}
    for y in years:
        if tot[y] == 0:
            index[y] = 0.0
            continue
        g = 0.0 if y == fy[0] else float(ri.params[ns + fy.index(y) - 1])
        index[y] = sum(math.exp(float(ri.params[i]) + g) for i in range(ns))
    return r, se, phi, index, years, phi_raw


def expect(obs, gen, method):
    if method == "analytic":
        r, se, phi, index, years = analytic(obs, gen)
        phi_raw = 0.0
    else:
        r, se, phi, index, years, phi_raw = fitted(obs, gen)
    lo, hi = r - Z95 * se, r + Z95 * se
    T = window_years(gen)
    first, last = years[0], years[-1]
    observed = min(last - first, T)
    win = [index[y] for y in index if last - T <= y <= last]
    mx, mn = max(win), min(win)
    out = {
        "status": "ok", "r": jsround(r, 6), "se": jsround(se, 6), "lo": jsround(lo, 6), "hi": jsround(hi, 6), "phi": jsround(phi, 4),
        "reduction": {"best": reduction(r, T), "min": reduction(hi, T), "max": reduction(lo, T)},
        "window": {"years": T, "from": last - T, "to": last, "observedYears": observed,
                   "observedPct": jsround(100 * observed / T, 1), "projected": last - first < T},
        "index": {str(y): jsround(v, 4) for y, v in index.items()},
        "extreme": (mn == 0 and mx > 0) or (mn > 0 and mx / mn > 10),
        "declineSuggested": hi < 0,
        "nSites": len(prepare(obs)[0]), "nYears": len(years),
    }
    if phi_raw is not None and method != "analytic":
        out["phiRaw"] = jsround(phi_raw, 4)
    return out


def curve(site, b0, r, years, t0=2014):
    return [{"site": site, "year": y, "mature": b0 * math.exp(r * (y - t0))} for y in years]


# --- Datos -------------------------------------------------------------------------------------------
Y = list(range(2014, 2025))
NOISY_4 = {  # 4 sitios, 2014–2026, declive ~8 %/año, recuentos enteros con ruido; 2019 y 2023 sin visitas
    "S1": [52, 50, 41, 44, 37, None, 30, 27, 26, None, 21, 18, 17],
    "S2": [31, 26, 29, 22, 23, None, 17, 18, 14, None, 11, 12, 9],
    "S3": [18, 17, 15, 16, 12, None, 11, 9, 10, None, 7, 6, 6],
    "S4": [75, 71, 62, 64, 55, None, 47, 40, 41, None, 31, 30, 26],
}
OVERDISP = {  # 3 sitios, mucha variación entre años (φ > 1), sin tendencia clara
    "P1": [40, 12, 55, 20, 61, 15, 48, 22],
    "P2": [22, 35, 9, 41, 14, 37, 11, 30],
    "P3": [60, 18, 70, 25, 80, 21, 66, 19],
}
FLUCT = {  # 2 sitios, el índice anual se multiplica y se divide por > 10
    "F1": [80, 6, 90, 5, 85, 7],
    "F2": [40, 3, 45, 2, 50, 4],
}


def table(d, first):
    return [{"site": s, "year": first + i, "mature": v} for s, vals in d.items() for i, v in enumerate(vals) if v is not None]


DATASETS = [
    ("Dos sitios sin ruido, r = −0,08, 2014–2024, generación 2 años: r exacta y EE analítico", "analytic",
     curve("A", 100, -0.08, Y) + curve("B", 40, -0.08, Y), 2),
    ("Dos sitios sin ruido, subida r = +0,05: reducción 0 y sin sugerencia de declive", "analytic",
     curve("A", 30, 0.05, Y) + curve("B", 10, 0.05, Y), None),
    ("Dos sitios sin ruido, 2018–2024 y generación 5 años: ventana de 15, 40 % observado (resto proyectado)", "analytic",
     curve("A", 100, -0.1, range(2018, 2025), 2018) + curve("B", 60, -0.1, range(2018, 2025), 2018), 5),
    ("Varias visitas a un sitio en un año: cuenta el máximo", "analytic",
     curve("A", 100, -0.08, Y) + curve("B", 40, -0.08, Y) + [{"site": "A", "year": 2016, "mature": 10}, {"site": "B", "year": 2020, "mature": 0}], 2),
    ("Sitio con un solo año y sitio siempre a cero: fuera del modelo", "analytic",
     curve("A", 100, -0.08, Y) + curve("B", 40, -0.08, Y) + [{"site": "C", "year": 2020, "mature": 300}]
     + [{"site": "Z", "year": y, "mature": 0} for y in (2015, 2018, 2021)], 2),
    ("Cuatro sitios con ruido y años sin visita (2019, 2023), 2014–2026, generación 3 años (GLM de statsmodels)", "fitted",
     table(NOISY_4, 2014), 3),
    ("Sobredispersión: φ > 1 ensancha el IC (statsmodels, cuasi-Poisson)", "fitted", table(OVERDISP, 2015), 4),
    ("Fluctuación extrema: el índice anual varía más de ×10 (statsmodels)", "fitted", table(FLUCT, 2019), None),
]


def build():
    out = []
    for name, method, obs, gen in DATASETS:
        if method == "fitted" and not HAVE_SM:
            print(f"(sin statsmodels: se salta «{name}»)", file=sys.stderr)
            continue
        out.append({"name": name, "method": method, "generationLength": gen, "obs": obs, "expect": expect(obs, gen, method)})
    # Casos sin ajuste (los decide la preparación, no el modelo).
    out.append({"name": "Dos años con datos: no evaluable", "method": "rule", "generationLength": None,
                "obs": curve("A", 50, -0.1, [2024, 2025]) + curve("B", 20, -0.1, [2024, 2025]), "expect": {"status": "not_evaluable"}})
    out.append({"name": "Ningún sitio con dos años: no evaluable", "method": "rule", "generationLength": None,
                "obs": [{"site": "A", "year": 2020, "mature": 5}, {"site": "B", "year": 2021, "mature": 7}, {"site": "C", "year": 2022, "mature": 3}],
                "expect": {"status": "not_evaluable"}})
    out.append({"name": "Sin datos", "method": "rule", "generationLength": None, "obs": [], "expect": {"status": "no_data"}})
    return out


if __name__ == "__main__":
    cases = build()
    if "--write" in sys.argv:
        data = json.loads(CASES.read_text(encoding="utf-8"))
        data["series"] = cases
        CASES.write_text(json.dumps(data, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
        print(f"{len(cases)} casos de series escritos en {CASES}")
    else:
        print(json.dumps(cases, ensure_ascii=False, indent=1))
