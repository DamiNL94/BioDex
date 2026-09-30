#!/usr/bin/env python3
# ================================================================================================
# Referencia independiente de EOO/AOO para shared/metrics-cases.json (F9), con pyproj + shapely.
#   pip install pyproj shapely
#   python backend/bin/metrics-reference.py
# Imprime, para cada conjunto de `records` del JSON y celda de 2 y 1 km: EOO sin igualar (casco
# convexo de shapely), celdas ocupadas y AOO, y el mínimo y máximo desplazando la rejilla medio tamaño.
# Compara a mano con `expect` de los casos «Sierra Nevada» (tolerancia 0,01 km²).
# F9 (30/09/2026): en la máquina de desarrollo no había Python; la referencia del JSON sale de PROJ
# (cs2cs, imagen osgeo/proj) + envoltura de Jarvis. Pendiente pasar este script.
# ================================================================================================
import json
import math
from pathlib import Path

from pyproj import Transformer
from shapely.geometry import MultiPoint

CASES = Path(__file__).resolve().parents[2] / "shared" / "metrics-cases.json"
data = json.loads(CASES.read_text(encoding="utf-8"))
to_utm = Transformer.from_crs("EPSG:4258", "EPSG:25830", always_xy=True)


def cells(points, size, ox=0.0, oy=0.0):
    return {(math.floor((x - ox) / size), math.floor((y - oy) / size)) for x, y in points}


for name, recs in data["records"].items():
    pts = [to_utm.transform(lon, lat) for lat, lon in recs]
    hull = MultiPoint(pts).convex_hull
    eoo = hull.area / 1e6 if hull.geom_type == "Polygon" else None
    vertices = len(hull.exterior.coords) - 1 if hull.geom_type == "Polygon" else len(pts)
    print(f"{name}: {len(pts)} puntos · EOO sin igualar {eoo:.4f} km² · {vertices} vértices")
    for km in (2, 1):
        size = km * 1000
        n = [len(cells(pts, size, ox, oy)) for ox, oy in ((0, 0), (size / 2, 0), (0, size / 2), (size / 2, size / 2))]
        print(f"  {km} km: {n[0]} celdas · AOO {n[0] * km * km} km² · rango {min(n) * km * km}–{max(n) * km * km} km²")
