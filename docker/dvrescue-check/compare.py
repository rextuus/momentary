"""Gegenprobe: dvrescue gegen read_dv_subcodes.py (Datum je Frame und Segmentgrenzen).

Läuft im Wegwerf-Image momentary-dvrescue-check:local:
  siehe README.md
"""
import importlib.util
import json
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

spec = importlib.util.spec_from_file_location("reader", "/reader/read_dv_subcodes.py")
reader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reader)

NS = "{https://mediaarea.net/dvrescue}"


def dvrescue_frames(path):
    with tempfile.TemporaryDirectory() as directory:
        xml = directory + "/out.xml"  # dvrescue überschreibt keine vorhandene Datei
        # Der Rückgabewert sagt wenig; maßgeblich ist, ob das XML geschrieben wurde
        result = subprocess.run(["dvrescue", path, "-x", xml], capture_output=True)
        try:
            root = ET.parse(xml).getroot()
        except (ET.ParseError, FileNotFoundError):
            raise SystemExit(f"dvrescue lieferte kein XML (Code {result.returncode}): {result.stderr.decode()[:500]}")
    frames = []
    for frame in root.iter(NS + "frame"):
        frames.append({
            "n": int(frame.get("n")),
            "rdt": frame.get("rdt"),
            "rdt_nc": frame.get("rdt_nc") is not None,
            "rec_start": frame.get("rec_start") == "1",
            "rec_end": frame.get("rec_end") == "1",
        })
    return frames


def our_dates(path, wanted):
    """Datum je gewünschtem Frame laut read_dv_subcodes.read_frame."""
    with tempfile.TemporaryFile() as errors:
        process = reader.open_stream(path, errors)
        first = process.stdout.read(reader.BLOCK)
        system = reader.detect_system(first)
        size = reader.FRAME_SIZE[system]
        result, pending, n = {}, first, 0
        while wanted and n <= max(wanted):
            frame = pending + process.stdout.read(size - len(pending))
            pending = b""
            if len(frame) < size:
                break
            if n in wanted:
                time, rec_start = reader.read_frame(frame)
                result[n] = (time.strftime("%Y-%m-%d %H:%M:%S") if time else None, rec_start)
            n += 1
        process.kill()
        process.wait()
    return result


def our_runs(path):
    out = subprocess.run(["python3", "-I", "/reader/read_dv_subcodes.py", path], check=True, capture_output=True)
    return json.loads(out.stdout)


def main(path):
    dv = dvrescue_frames(path)
    ours = our_runs(path)
    print(f"Datei: {path}")
    print(f"dvrescue: {len(dv)} gelistete Frames; read_dv_subcodes: {ours['system']}, {ours['frames']} Frames, "
          f"{len(ours['runs'])} Läufe")

    deviations = []
    dates = our_dates(path, {f["n"] for f in dv})
    for f in dv:
        mine = dates.get(f["n"], (None, False))[0]
        if f["rdt"] != mine:
            deviations.append(f"Datum Frame {f['n']}: dvrescue {f['rdt']!r}, read_dv_subcodes {mine!r}")
        if f["rec_start"] != dates.get(f["n"], (None, False))[1]:
            deviations.append(f"Aufnahmebeginn Frame {f['n']}: dvrescue {f['rec_start']}, "
                              f"read_dv_subcodes {dates.get(f['n'], (None, False))[1]}")

    # Grenzen laut dvrescue: Aufnahmebeginn, Datumssprung oder Datum taucht auf / fällt weg
    dv_bounds, previous = set(), None
    for f in dv:
        has_date = f["rdt"] is not None
        if f["n"] > 0 and (f["rec_start"] or f["rdt_nc"] or (previous is not None and has_date != previous)):
            dv_bounds.add(f["n"])
        previous = has_date
    our_bounds = {run["start"] for run in ours["runs"] if run["start"] > 0}
    for n in sorted(dv_bounds - our_bounds):
        deviations.append(f"Grenze nur bei dvrescue: Frame {n}")
    for n in sorted(our_bounds - dv_bounds):
        deviations.append(f"Grenze nur bei read_dv_subcodes: Frame {n}")

    print("Grenzen dvrescue:", sorted(dv_bounds))
    print("Grenzen read_dv_subcodes:", sorted(our_bounds))
    print("Abweichungen:" if deviations else "Keine Abweichungen.")
    for line in deviations:
        print(" -", line)


for argument in sys.argv[1:]:
    main(argument)
