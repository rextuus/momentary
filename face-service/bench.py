"""Laufzeit des laufenden face-service messen (über HTTP, also mit Limits und Lock wie im Betrieb).

Im Container:
  python bench.py                          # Beispielbild von InsightFace, 640 und 320, je 10 Durchläufe
  python bench.py /tmp/bench --runs 3      # alle JPG/PNG eines Ordners (vorher mit `docker compose cp` hinein)
  python bench.py bild.jpg --det-size 640 --det-size 480 --det-size 320
  python bench.py /tmp/bench --min-face-px 40 --max-faces 10   # Filter vor dem Embedding (0 = aus)
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
import sys
import time
import urllib.request
import uuid

import insightface


def default_image() -> str:
    return os.path.join(os.path.dirname(insightface.__file__), "data", "images", "t1.jpg")


def collect(paths: list[str]) -> list[str]:
    files: list[str] = []
    for path in paths or [default_image()]:
        if os.path.isdir(path):
            files += sorted(
                os.path.join(path, name) for name in os.listdir(path)
                if name.lower().endswith((".jpg", ".jpeg", ".png"))
            )
        else:
            files.append(path)
    return files


def post(url: str, filename: str, data: bytes) -> dict:
    boundary = uuid.uuid4().hex
    body = (
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"image\"; filename=\"{os.path.basename(filename)}\"\r\n"
        "Content-Type: application/octet-stream\r\n\r\n"
    ).encode() + data + f"\r\n--{boundary}--\r\n".encode()
    request = urllib.request.Request(url, data=body, headers={"Content-Type": f"multipart/form-data; boundary={boundary}"})
    with urllib.request.urlopen(request, timeout=120) as response:
        return json.load(response)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("paths", nargs="*", help="Bilder oder Ordner (Standard: Beispielbild t1.jpg)")
    parser.add_argument("--url", default="http://127.0.0.1:8000")
    parser.add_argument("--runs", type=int, default=10, help="Durchläufe pro Bild")
    parser.add_argument("--det-size", type=int, action="append", help="mehrfach möglich (Standard 640 und 320)")
    parser.add_argument("--min-face-px", type=int, help="kürzere Box-Seite in px (Standard des Dienstes; 0 = aus)")
    parser.add_argument("--max-faces", type=int, help="höchstens so viele Gesichter (Standard des Dienstes; 0 = alle)")
    args = parser.parse_args()

    files = collect(args.paths)
    if not files:
        print("Keine Bilder gefunden.", file=sys.stderr)
        return 1
    images = [(path, open(path, "rb").read()) for path in files]
    sizes = args.det_size or [640, 320]
    filters = "".join(
        f"&{name}={value}" for name, value in (("min_face_px", args.min_face_px), ("max_faces", args.max_faces))
        if value is not None
    )

    # Aufwärmen: die erste Inferenz ist deutlich langsamer
    first = post(f"{args.url}/analyze?det_size={sizes[0]}{filters}", images[0][0], images[0][1])
    applied = "" if filters else " (Standard des Dienstes)"
    print(f"{len(images)} Bild(er), {args.runs} Durchläufe, Zeiten in ms (Median, im Dienst gemessen)")
    print(f"Filter: {filters.lstrip('&').replace('&', ', ') or 'keine Angabe'}{applied}; Modell {first['model']}")
    for endpoint in ("detect", "analyze"):
        for size in sizes:
            detect, embed, total, faces, small, over = [], [], [], [], [], []
            for _ in range(args.runs):
                for path, data in images:
                    started = time.perf_counter()
                    result = post(f"{args.url}/{endpoint}?det_size={size}{filters}", path, data)
                    total.append((time.perf_counter() - started) * 1000)
                    detect.append(result["timings_ms"]["detect"])
                    embed.append(result["timings_ms"]["embed"])
                    faces.append(len(result["faces"]))
                    small.append(result["filtered"]["too_small"])
                    over.append(result["filtered"]["over_limit"])
            per_face = statistics.median(e / f for e, f in zip(embed, faces) if f) if any(faces) else 0.0
            print(
                f"/{endpoint:<7} det_size {size:>4}: detect {statistics.median(detect):7.1f}"
                f"  embed {statistics.median(embed):7.1f} ({per_face:5.1f}/Gesicht)"
                f"  gesamt inkl. HTTP {statistics.median(total):7.1f}"
                f"  Gesichter/Bild {statistics.mean(faces):4.1f}"
                f" (gefiltert: zu klein {statistics.mean(small):4.1f}, über Limit {statistics.mean(over):4.1f})"
            )
    return 0


if __name__ == "__main__":
    sys.exit(main())
