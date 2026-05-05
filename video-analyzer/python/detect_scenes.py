import sys
import json
import argparse
from scenedetect import detect, ContentDetector

def find_scenes(video_path):
    # ContentDetector ist ideal für Schnitte und starke Inhaltsänderungen
    scene_list = detect(video_path, ContentDetector())

    scenes = []
    for i, scene in enumerate(scene_list):
        scenes.append({
            'scene_number': i + 1,
            'start_seconds': scene[0].get_seconds(),
            'end_seconds': scene[1].get_seconds(),
            'duration': scene[1].get_seconds() - scene[0].get_seconds()
        })
    return scenes

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('video_path', help="Path to the local video file")
    args = parser.parse_args()

    try:
        results = find_scenes(args.video_path)
        print(json.dumps(results))
    except Exception as e:
        print(json.dumps({"error": str(e)}), file=sys.stderr)
        sys.exit(1)