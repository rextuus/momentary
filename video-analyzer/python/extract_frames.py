import sys
import subprocess
import json
from pathlib import Path

# Bildaufbereitung für die Gesichtserkennung (gilt für Erstanalyse und Refinement):
# - Deinterlacing nur für Bilder, die als interlaced markiert sind (bwdif, ein Bild je Vollbild); progressives Material
#   bleibt unberührt. Muss vor fps stehen, weil bwdif die Nachbarbilder braucht. Neu verarbeitete Videos sind nach der
#   Konvertierung ohnehin progressiv, das ist die Absicherung für alte MP4s.
# - Nicht-quadratische Pixel (SD: SAR 16:15 bei 4:3, 64:45 bei anamorphem 16:9) entzerren: nur die Breite wird
#   angepasst, die Zeilenzahl bleibt; Breite immer gerade. Sonst sind Gesichter bis zu 30 % zu schmal.
# - Nie verkleinern, außer auf höchstens 1920 px lange Seite (Hoch- und Querformat), Kanten gerade.
# - JPEG in hoher Qualität (-q:v 2): Kompressionsartefakte verschlechtern Detektion und Embeddings.
DEINTERLACE = "bwdif=mode=send_frame:parity=auto:deint=interlaced"
SQUARE_PIXELS = "scale=w='trunc(iw*if(gt(sar,0),sar,1)/2)*2':h=ih:flags=lanczos,setsar=1"
MAX_LONG_SIDE = "scale=w='if(gte(iw,ih),min(1920,iw),-2)':h='if(gte(iw,ih),-2,min(1920,ih))':flags=lanczos"
JPEG_QUALITY = ["-q:v", "2"]


def filter_chain(fps=None, pixel_format=True):
    """Filterkette für ein Segment; fps=None für ein einzelnes Bild."""
    parts = [DEINTERLACE]
    if fps is not None:
        parts.append(f"fps={fps}")
    parts += [SQUARE_PIXELS, MAX_LONG_SIDE]
    if pixel_format:
        parts.append("format=yuvj420p")
    return ",".join(parts)


def count_new_frames(frame_dir, start_number):
    """Zählt die Frames, die ab start_number geschrieben wurden (also vom aktuellen Segment)."""
    count = 0
    for frame_file in frame_dir.glob("frame_*.jpg"):
        try:
            if int(frame_file.stem.split("_")[-1]) >= start_number:
                count += 1
        except ValueError:
            continue
    return count


def probe_duration(video_path):
    """Dauer des Videos in Sekunden, None wenn nicht ermittelbar."""
    try:
        result = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=nw=1:nk=1", str(video_path)],
            capture_output=True, text=True, timeout=60,
        )
        return float(result.stdout.strip())
    except Exception:
        return None


def run_ffmpeg(video_path, frame_dir, fps, s_time, d_time, start_number):
    """
    Extrahiert Frames eines Segments und prüft, dass wirklich welche geschrieben wurden.

    Neuere ffmpeg-Versionen verweigern den MJPEG-Encoder bei Eingaben mit eingeschränktem
    Farbbereich (tv range). Sauber löst man das mit format=yuvj420p (inkl. Bereichsumrechnung),
    als Fallback dient -strict unofficial.
    """
    attempts = [
        ("fps + yuvj420p", filter_chain(fps), JPEG_QUALITY),
        ("fps + strict unofficial", filter_chain(fps, pixel_format=False), ["-strict", "unofficial"] + JPEG_QUALITY),
    ]

    # Clips kürzer als ein Intervall: der fps-Filter liefert dort evtl. kein Frame -> erstes Frame nehmen
    interval = 1.0 / fps
    if d_time is not None:
        segment_length = d_time
    else:
        total = probe_duration(video_path)
        segment_length = None if total is None else max(0.0, total - (s_time or 0))

    if segment_length is not None and segment_length < interval:
        attempts.append(("single frame + yuvj420p", filter_chain(), ["-frames:v", "1"] + JPEG_QUALITY))
        attempts.append((
            "single frame + strict unofficial",
            filter_chain(pixel_format=False),
            ["-frames:v", "1", "-strict", "unofficial"] + JPEG_QUALITY,
        ))

    errors = []
    for label, vf, extra in attempts:
        cmd = ["ffmpeg", "-loglevel", "error", "-y"]
        if s_time is not None and s_time > 0:
            cmd.extend(["-ss", str(s_time)])

        cmd.extend(["-i", str(video_path)])

        if d_time is not None:
            cmd.extend(["-t", str(d_time)])

        cmd.extend(extra)
        cmd.extend(["-vf", vf, "-start_number", str(start_number), str(frame_dir / "frame_%04d.jpg")])

        result = subprocess.run(cmd, capture_output=True, text=True)
        written = count_new_frames(frame_dir, start_number)
        if result.returncode == 0 and written > 0:
            return

        errors.append(f"[{label}] exit={result.returncode}, frames={written}, stderr={result.stderr.strip()[:300]}")

    length_info = "unbekannt" if segment_length is None else f"{segment_length:.2f}s"
    raise RuntimeError(f"ffmpeg lieferte keine Frames (Segmentlänge {length_info}): " + " | ".join(errors))


def main():
    if len(sys.argv) < 2:
        print("Usage: extract_frames.py <video_path> [fps] [--start-time <seconds>] [--duration <seconds>] [--output-dir <dir>]", file=sys.stderr)
        sys.exit(1)

    video_path = Path(sys.argv[1])
    fps = 0.2
    start_time = None
    duration = None
    frame_dir = None

    # Argumente parsen
    args = sys.argv[2:]
    i = 0
    while i < len(args):
        arg = args[i]
        if arg == '--start-time':
            # Mehrere Startzeiten möglich
            if start_time is None:
                start_time = []
            start_time.append(float(args[i + 1]))
            i += 2
        elif arg == '--duration':
            # Mehrere Dauern möglich
            if duration is None:
                duration = []
            duration.append(float(args[i + 1]))
            i += 2
        elif arg == '--output-dir':
            frame_dir = Path(args[i + 1])
            i += 2
        else:
            # Wenn es kein Flag ist, nehmen wir an, es ist der FPS-Wert (abwärtskompatibel)
            try:
                fps = float(arg)
            except ValueError:
                pass
            i += 1

    if frame_dir is None:
        frame_dir = video_path.parent / "frames"

    frame_dir.mkdir(parents=True, exist_ok=True)

    # Segmente bilden: (start, dauer). Fehlende Werte bleiben None.
    # Ohne Angaben wird das gesamte Video extrahiert.
    starts = start_time if start_time else [None]
    durations = duration if duration else []
    segments = [
        (s, durations[idx] if idx < len(durations) else None)
        for idx, s in enumerate(starts)
    ]

    frame_list = []
    global_frame_counter = 0

    for s_time, d_time in segments:
        # -start_number 0 für das erste Segment: frame_0000.jpg ist das erste Frame,
        # passend zur Zeitberechnung (index * 1/fps). Folgesegmente laufen weiter.
        try:
            run_ffmpeg(video_path, frame_dir, fps, s_time, d_time, global_frame_counter)
        except RuntimeError as e:
            print(str(e), file=sys.stderr)
            sys.exit(1)

        # Neue Frames finden (die ab global_frame_counter)
        current_segment_frames = []
        for frame_file in sorted(frame_dir.glob("frame_*.jpg")):
            try:
                num = int(frame_file.stem.split("_")[-1])
            except ValueError:
                continue
            if num >= global_frame_counter:
                current_segment_frames.append(frame_file)

        current_segment_frames.sort()
        for idx, frame_file in enumerate(current_segment_frames):
            # Timestamp relativ zum Segment-Start: index * 1/fps + s_time
            ts = int(idx * (1 / fps))
            if s_time is not None:
                ts += int(s_time)

            frame_list.append({
                "path": str(frame_file),
                "timestamp": ts
            })

        global_frame_counter += len(current_segment_frames)

    print(json.dumps(frame_list))


if __name__ == "__main__":
    main()