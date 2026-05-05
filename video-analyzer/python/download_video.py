import sys
import subprocess
import uuid
from pathlib import Path
import json

def find_project_root(start_path: Path) -> Path:
    for parent in [start_path, *start_path.parents]:
        if (parent / "composer.json").exists():
            return parent
    raise FileNotFoundError("Could not find Symfony project root")

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Missing URL argument"}))
        sys.exit(1)

    url = sys.argv[1]
    video_id_arg = next((arg.split('=')[1] for arg in sys.argv if arg.startswith('--video-id=')), None)
    video_id = video_id_arg if video_id_arg else str(uuid.uuid4())[:8]

    try:
        project_root = find_project_root(Path(__file__).resolve().parent)
        video_dir = project_root / "var" / "video-processing" / f"video_{video_id}"
        video_dir.mkdir(parents=True, exist_ok=True)

        video_base_path = video_dir / "source_video"

        # Download mit Fehlerabfang
        result = subprocess.run([
            "yt-dlp", "-q", "--no-warnings", "--no-playlist",
            "-o", f"{video_base_path}.%(ext)s",
            url
        ], capture_output=True, text=True)

        if result.returncode != 0:
            print(json.dumps({"error": result.stderr.strip()}))
            sys.exit(1)

        actual_path = next(video_dir.glob("source_video.*"), None)

        if not actual_path:
            print(json.dumps({"error": "File not found after download"}))
            sys.exit(1)

        print(json.dumps({"video_path": str(actual_path)}))

    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()