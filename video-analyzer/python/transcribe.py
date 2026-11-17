"""
Transkribiert eine WAV-Datei (16 kHz, mono, PCM16) lokal mit faster-whisper.

Ausgabe: JSON auf stdout
    {"language": "de", "language_probability": 0.98, "duration": 121.0, "speech_duration": 98.0,
     "segments": [{"start": 0.1, "end": 2.0, "text": "...", "avg_logprob": -0.35, "no_speech_prob": 0.01}]}

Fortschritt: Zeilen "PROGRESS <verarbeitete Sekunden> <Gesamtsekunden>" auf stderr.

Das Audio wird selbst eingelesen statt über PyAV: die von faster-whisper erwartete PyAV-Version passt nicht
immer zur installierten (z. B. wegen scenedetect[av]).
"""
import argparse
import json
import sys
import time
import wave

import numpy as np


def load_wav(path):
    with wave.open(path) as w:
        if w.getframerate() != 16000 or w.getnchannels() != 1 or w.getsampwidth() != 2:
            raise ValueError("Erwartet 16 kHz mono PCM16, bitte vorher mit ffmpeg umwandeln")
        frames = w.readframes(w.getnframes())
    return np.frombuffer(frames, dtype=np.int16).astype(np.float32) / 32768.0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--audio", required=True)
    parser.add_argument("--model", default="large-v3-turbo")
    parser.add_argument("--threads", type=int, default=3)
    parser.add_argument("--language", default=None, help="z. B. de; leer = automatisch erkennen")
    parser.add_argument("--models-dir", default=None, help="Ablage der heruntergeladenen Modelle")
    args = parser.parse_args()

    from faster_whisper import WhisperModel

    audio = load_wav(args.audio)
    total = len(audio) / 16000.0

    model = WhisperModel(
        args.model,
        device="cpu",
        compute_type="int8",
        cpu_threads=args.threads,
        download_root=args.models_dir,
    )
    segments, info = model.transcribe(
        audio,
        language=args.language or None,
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 500},
        beam_size=5,
        # Verhindert, dass sich ein Verhörer über viele Abschnitte fortpflanzt
        condition_on_previous_text=False,
    )

    result = []
    last_report = 0.0
    for s in segments:
        result.append({
            "start": round(s.start, 2),
            "end": round(s.end, 2),
            "text": s.text.strip(),
            "avg_logprob": round(s.avg_logprob, 3),
            "no_speech_prob": round(s.no_speech_prob, 3),
        })
        now = time.monotonic()
        if now - last_report >= 2:
            print(f"PROGRESS {s.end:.1f} {total:.1f}", file=sys.stderr, flush=True)
            last_report = now

    print(f"PROGRESS {total:.1f} {total:.1f}", file=sys.stderr, flush=True)
    json.dump({
        "language": info.language,
        "language_probability": round(info.language_probability, 3),
        "duration": round(total, 2),
        "speech_duration": round(info.duration_after_vad, 2),
        "segments": result,
    }, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
