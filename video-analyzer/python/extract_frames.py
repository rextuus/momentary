import sys
import subprocess
import json
from pathlib import Path

def extract_timestamp_from_filename(filename: str, fps: float) -> int:
    stem = Path(filename).stem
    try:
        # Erwartet frame_0000, frame_0001, etc.
        number = int(stem.split("_")[-1])
        return int(number * (1 / fps))
    except Exception:
        return 0

def main():
    if len(sys.argv) < 2:
        print("Usage: extract_frames.py <video_path> [fps] [--start-time <seconds>] [--duration <seconds>]", file=sys.stderr)
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
            # Support multiple start times
            if start_time is None:
                start_time = []
            start_time.append(float(args[i+1]))
            i += 2
        elif arg == '--duration':
            # Support multiple durations
            if duration is None:
                duration = []
            duration.append(float(args[i+1]))
            i += 2
        elif arg == '--output-dir':
            frame_dir = Path(args[i+1])
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

    # FFmpeg Extraktion mit dynamischer FPS
    # Wir benutzen -start_number 0, damit frame_0000.jpg das erste Frame ist,
    # passend zur Zeitberechnung (index * 1/fps)
    
    # Wenn wir mehrere Segmente haben
    segments = []
    if isinstance(start_time, list) and isinstance(duration, list):
        for s, d in zip(start_time, duration):
            segments.append((s, d))
    elif start_time is not None and duration is not None:
        segments.append((start_time, duration))
    else:
        # Alles extrahieren
        segments.append((None, None))

    frame_list = []
    global_frame_counter = 0

    for s_time, d_time in segments:
        ffmpeg_cmd = ["ffmpeg", "-loglevel", "error", "-y"]
        if s_time is not None:
            ffmpeg_cmd.extend(["-ss", str(s_time)])
        
        ffmpeg_cmd.extend(["-i", str(video_path)])
        
        if d_time is not None:
            ffmpeg_cmd.extend(["-t", str(d_time)])
        
        # Wir müssen sicherstellen, dass wir den global_frame_counter für den Dateinamen nutzen
        ffmpeg_cmd.extend(["-vf", f"fps={fps}", "-start_number", str(global_frame_counter), str(frame_dir / "frame_%04d.jpg")])
        
        subprocess.run(ffmpeg_cmd, check=True)

        # Neue Frames finden (die ab global_frame_counter)
        current_segment_frames = []
        for frame_file in sorted(frame_dir.glob("frame_*.jpg")):
            stem = frame_file.stem
            try:
                num = int(stem.split("_")[-1])
                if num >= global_frame_counter:
                    current_segment_frames.append(frame_file)
            except:
                continue
        
        # Frames sortieren und zur Liste hinzufügen
        current_segment_frames.sort()
        for i, frame_file in enumerate(current_segment_frames):
            # Timestamp berechnen: Relativ zum Segment-Start
            # index innerhalb des segments * 1/fps + s_time
            ts = int(i * (1 / fps))
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