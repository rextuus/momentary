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
    """Calculate timestamp based on frame number and fps (default: every 5s → 0.2 fps)"""
    stem = Path(filename).stem  # e.g., frame_0001
    try:
        number = int(stem.split("_")[-1])
        return int((number - 1) * (1 / fps))  # assuming frame_0001 starts at 0s
    except Exception:
        return 0

def main():
    if len(sys.argv) < 2:
        print("Usage: download_and_extract.py <youtube_url>", file=sys.stderr)
        sys.exit(1)

    url = sys.argv[1]
    fps = 0.2  # one frame every 5 seconds

    script_dir = Path(__file__).resolve().parent
    project_root = find_project_root(script_dir)
    var_dir = project_root / "var" / "video-processing"

    video_id = str(uuid.uuid4())[:8]
    video_dir = var_dir / f"video_{video_id}"
    frame_dir = video_dir / "frames"
    video_path = video_dir / "video.webm"

    video_dir.mkdir(parents=True, exist_ok=True)
    frame_dir.mkdir(parents=True, exist_ok=True)

    print(f"[⏬] Downloading video to {video_path}...", file=sys.stderr)
    subprocess.run([
        "yt-dlp",
        "-o", str(video_path.with_suffix(".%(ext)s")),
        url
    ], check=True)

    print(f"[🎞️] Extracting frames to {frame_dir}...", file=sys.stderr)
    subprocess.run([
        "ffmpeg",
        "-i", str(video_path),
        "-vf", f"fps={fps}",
        str(frame_dir / "frame_%04d.jpg")
    ], check=True)

    # Build JSON with frame paths and timestamps
    frame_list = []
    for frame_file in sorted(frame_dir.glob("frame_*.jpg")):
        frame_list.append({
            "path": str(frame_file),
            "timestamp": extract_timestamp_from_filename(frame_file.name, fps)
        })

    # Output JSON to stdout
    print(json.dumps(frame_list))  # 👈 Symfony expects this only!

    # Delete the video file after processing
    if video_path.exists():
        video_path.unlink()
        print(f"[🗑️] Deleted video file: {video_path}", file=sys.stderr)

if __name__ == "__main__":
    main()
