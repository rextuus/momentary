import sys
import json
import argparse
from scenedetect import detect, ContentDetector, AdaptiveDetector

def find_scenes(video_path, threshold=27.0, detector_type='content'):
    # Wir versuchen das Video mit PySceneDetect zu öffnen
    # Falls es ein MPG ist, könnte OpenCV Probleme haben.
    
    if detector_type == 'adaptive':
        detector = AdaptiveDetector(adaptive_threshold=threshold)
    else:
        detector = ContentDetector(threshold=threshold)

    try:
        scene_list = detect(video_path, detector)
    except Exception as e:
        # Fallback auf eine Szene bei Crash
        scene_list = []

    # Falls gar keine Szene erkannt wurde, nehmen wir das ganze Video als eine Szene
    if len(scene_list) <= 1:
        import cv2
        cap = cv2.VideoCapture(video_path)
        if not cap.isOpened():
            raise Exception(f"Could not open video file: {video_path}")
        
        fps = cap.get(cv2.CAP_PROP_FPS)
        frame_count = cap.get(cv2.CAP_PROP_FRAME_COUNT)
        
        # Check if we can actually read frames at different positions
        # Sometimes first frame is readable but subsequent ones are not
        readable = True
        for frame_pos in [0, int(frame_count/2), int(frame_count-1)]:
            if frame_pos < 0: continue
            cap.set(cv2.CAP_PROP_POS_FRAMES, frame_pos)
            ret, frame = cap.read()
            if not ret:
                readable = False
                break
        
        if not readable and len(scene_list) == 0:
            cap.release()
            raise Exception("Could not read frames from video. File might be corrupt or format not supported by OpenCV.")
        
        duration_seconds = frame_count / fps if fps > 0 else 0
        cap.release()
        
        if not readable or len(scene_list) <= 1:
            return [{
                'scene_number': 1,
                'start_seconds': 0.0,
                'end_seconds': duration_seconds,
                'duration': duration_seconds,
                'warning': 'Only one scene detected and frame reading issues suspected. Try converting it to MP4 first.'
            }]

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
    parser.add_argument('--threshold', type=float, default=27.0, help="Threshold for scene detection")
    parser.add_argument('--detector', choices=['content', 'adaptive'], default='content', help="Detector type")
    args = parser.parse_args()

    try:
        results = find_scenes(args.video_path, args.threshold, args.detector)
        # Flush stdout to ensure JSON is sent separately from potential library warnings
        sys.stdout.flush()
        print(json.dumps(results))
        sys.stdout.flush()
    except Exception as e:
        print(json.dumps({"error": str(e)}), file=sys.stderr)
        sys.exit(1)