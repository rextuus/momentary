import sys
import subprocess
import json
from pathlib import Path

def extract_timestamp_from_filename(filename: str, fps: float) -> int:
    stem = Path(filename).stem
    try:
        number = int(stem.split("_")[-1])
        return int((number - 1) * (1 / fps))
    except Exception:
        return 0

def main():
    if len(sys.argv) < 2:
        print("Usage: extract_frames.py <video_path>", file=sys.stderr)
        sys.exit(1)

    video_path = Path(sys.argv[1])
    fps = 0.2

    frame_dir = video_path.parent / "frames"
    frame_dir.mkdir(parents=True, exist_ok=True)

    # FFmpeg Extraktion
    subprocess.run([
        "ffmpeg", "-loglevel", "error", "-y",
        "-i", str(video_path),
        "-vf", f"fps={fps}",
        str(frame_dir / "frame_%04d.jpg")
    ], check=True)

    frame_list = []
    for frame_file in sorted(frame_dir.glob("frame_*.jpg")):
        frame_list.append({
            "path": str(frame_file),
            "timestamp": extract_timestamp_from_filename(frame_file.name, fps)
        })

    print(json.dumps(frame_list))

    # Optional: Video hier löschen
    video_path.unlink()

if __name__ == "__main__":
    main()