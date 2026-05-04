import os
import sys
import subprocess
import uuid
import json
from pathlib import Path

def find_project_root(start_path: Path) -> Path:
    for parent in [start_path, *start_path.parents]:
        if (parent / "composer.json").exists():
            return parent
    raise FileNotFoundError("Could not find Symfony project root (no composer.json)")

def extract_timestamp_from_filename(filename: str, fps: float) -> int:
    stem = Path(filename).stem
    try:
        number = int(stem.split("_")[-1])
        return int((number - 1) * (1 / fps))
    except Exception:
        return 0

def main():
    if len(sys.argv) < 2:
        print("Usage: download_and_extract.py <youtube_url>", file=sys.stderr)
        sys.exit(1)

    url = sys.argv[1]
    fps = 0.2

    script_dir = Path(__file__).resolve().parent
    project_root = find_project_root(script_dir)
    var_dir = project_root / "var" / "video-processing"

    # Nutze die ID von Symfony, falls übergeben, sonst Zufall
    video_id_arg = next((arg.split('=')[1] for arg in sys.argv if arg.startswith('--video-id=')), None)
    video_id = video_id_arg if video_id_arg else str(uuid.uuid4())[:8]

    video_dir = var_dir / f"video_{video_id}"
    frame_dir = video_dir / "frames"

    # Wir setzen hier nur den Basisnamen ohne Endung
    video_base_path = video_dir / "video"

    video_dir.mkdir(parents=True, exist_ok=True)
    frame_dir.mkdir(parents=True, exist_ok=True)

    print(f"[⏬] Downloading video to {video_dir}...", file=sys.stderr)

    # Wir sagen yt-dlp, es soll die Datei einfach "video.EXT" nennen
    subprocess.run([
        "yt-dlp",
        "-q", "--no-warnings",
        "-o", f"{video_base_path}.%(ext)s",
        url
    ], check=True)

    # Jetzt suchen wir, welche Datei yt-dlp tatsächlich erzeugt hat (mp4, mkv, webm...)
    actual_video_path = None
    for ext in ['mp4', 'mkv', 'webm', 'avi']:
        p = video_base_path.with_suffix(f".{ext}")
        if p.exists():
            actual_video_path = p
            break

    if not actual_video_path:
        # Fallback: Nimm die erste Datei im Ordner, die nicht der Frame-Ordner ist
        files = [f for f in video_dir.iterdir() if f.is_file()]
        if files: actual_video_path = files[0]

    if not actual_video_path or not actual_video_path.exists():
        print(f"Error: Downloaded video not found in {video_dir}", file=sys.stderr)
        sys.exit(1)

    print(f"[🎞️] Extracting frames from {actual_video_path}...", file=sys.stderr)
    subprocess.run([
        "ffmpeg",
        "-loglevel", "error", # Weniger Noise auf stdout
        "-i", str(actual_video_path),
        "-vf", f"fps={fps}",
        str(frame_dir / "frame_%04d.jpg")
    ], check=True)

    frame_list = []
    for frame_file in sorted(frame_dir.glob("frame_*.jpg")):
        frame_list.append({
            "path": str(frame_file),
            "timestamp": extract_timestamp_from_filename(frame_file.name, fps)
        })

    # Das ist das einzige, was PHP auf stdout sehen darf:
    print(json.dumps(frame_list))

    # Cleanup: Video löschen
    if actual_video_path.exists():
        actual_video_path.unlink()

if __name__ == "__main__":
    main()