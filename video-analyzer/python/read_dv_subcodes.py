#!/usr/bin/env python3
"""Aufnahmedatum aus den Subcodes einer DV-Datei (MiniDV, Digital8) lesen.

DV speichert in jedem Frame Datum und Uhrzeit der Aufnahme (Packs 0x62/0x63), sowohl im Subcode- als auch im
VAUX-Bereich, dazu im VAUX-Source-Control-Pack (0x61) das Kennzeichen "Aufnahmebeginn" (REC ST = 0). Das Skript liest
nur diese Blöcke (5 von 150 je DIF-Sequenz), dekodiert nichts und fasst aufeinanderfolgende Frames zu Läufen
zusammen. Ein neuer Lauf beginnt bei einem Aufnahmebeginn, einem Zeitsprung oder wenn das Datum auftaucht/wegfällt.

Plausibilität (Jahr >= 1995, nicht in der Zukunft, Standarddaten) prüft der Aufrufer (RecordingSegmentBuilder).

Eingabe: beliebiger Container mit DV-Video (rohes .dv, AVI, MOV); ffmpeg reicht den Videostrom unverändert durch.

Usage: read_dv_subcodes.py <video_path>
Ausgabe (JSON): {"system": "PAL"|"NTSC", "fps": 25.0, "frames": 1234,
                 "runs": [{"start": 0, "end": 36, "recorded_at": "2004-07-12 14:33:05"|null, "rec_start": true}]}
"""
import json
import subprocess
import sys
import tempfile
from collections import Counter
from datetime import datetime

BLOCK = 80
SEQUENCE = 150 * BLOCK
FRAME_SIZE = {"NTSC": 10 * SEQUENCE, "PAL": 12 * SEQUENCE}
FPS = {"NTSC": 30000 / 1001, "PAL": 25.0}
# Größter Zeitsprung innerhalb einer Aufnahme (die Uhrzeit springt einmal je Sekunde weiter)
MAX_STEP_SECONDS = 2


def bcd(value, mask=0xFF):
    value &= mask
    high, low = value >> 4, value & 0x0F
    return None if high > 9 or low > 9 else high * 10 + low


def parse_date(pack):
    day, month, year = bcd(pack[2], 0x3F), bcd(pack[3], 0x1F), bcd(pack[4])
    if None in (day, month, year) or not 1 <= month <= 12 or not 1 <= day <= 31:
        return None
    # Zweistelliges Jahr: 00-49 = 20xx, 50-99 = 19xx
    return (2000 + year if year < 50 else 1900 + year, month, day)


def parse_time(pack):
    second, minute, hour = bcd(pack[2], 0x7F), bcd(pack[3], 0x7F), bcd(pack[4], 0x3F)
    if None in (second, minute, hour) or hour > 23 or minute > 59 or second > 59:
        return None
    return (hour, minute, second)


def read_frame(frame):
    """Häufigstes gültiges Datum+Uhrzeit des Frames und ob er als Aufnahmebeginn markiert ist."""
    dates, times, rec_start = Counter(), Counter(), Counter()
    for seq in range(0, len(frame), SEQUENCE):
        # Block 0 Header, 1-2 Subcode, 3-5 VAUX
        for index in range(1, 6):
            block = frame[seq + index * BLOCK: seq + (index + 1) * BLOCK]
            if len(block) < BLOCK:
                continue
            section = block[0] >> 5
            if section == 1:
                packs = [block[3 + i * 8 + 3: 3 + i * 8 + 8] for i in range(6)]
            elif section == 2:
                packs = [block[3 + i * 5: 3 + i * 5 + 5] for i in range(15)]
            else:
                continue
            for pack in packs:
                kind = pack[0]
                if kind == 0x62:
                    value = parse_date(pack)
                    if value:
                        dates[value] += 1
                elif kind == 0x63:
                    value = parse_time(pack)
                    if value:
                        times[value] += 1
                elif kind == 0x61 and pack[2] != 0xFF:
                    rec_start[(pack[2] >> 7) == 0] += 1

    recorded_at = None
    if dates and times:
        try:
            recorded_at = datetime(*dates.most_common(1)[0][0], *times.most_common(1)[0][0])
        except ValueError:  # z. B. 31.02.
            recorded_at = None
    return recorded_at, bool(rec_start) and rec_start.most_common(1)[0][0]


def open_stream(path, errors):
    # -f rawvideo gibt die DIF-Frames unverändert aus (der dv-Muxer verlangt eine Tonspur). Fehlermeldungen in eine
    # Datei: bei kaputten Bändern können es viele sein, eine volle Pipe hielte ffmpeg an.
    return subprocess.Popen(
        ["ffmpeg", "-v", "error", "-nostdin", "-i", path, "-map", "0:v:0", "-c", "copy", "-f", "rawvideo", "-"],
        stdout=subprocess.PIPE,
        stderr=errors,
    )


def error_text(errors):
    errors.seek(0)
    return errors.read(2000).decode(errors="replace").strip()


def detect_system(first_block):
    # Header-Block der ersten DIF-Sequenz: Sektion 0, Sequenz 0 mit gesetzten Reservebits, Block 0.
    # DSF-Bit (Byte 3, Bit 7): 0 = 525/60 (NTSC), 1 = 625/50 (PAL)
    if len(first_block) < BLOCK or first_block[0] >> 5 != 0 or first_block[1] & 0xF7 != 0x07 or first_block[2] != 0:
        return None
    return "PAL" if first_block[3] >> 7 else "NTSC"


def continues(previous, current):
    if previous is None or current is None:
        return previous is None and current is None
    step = (current - previous).total_seconds()
    return 0 <= step <= MAX_STEP_SECONDS


def main():
    if len(sys.argv) != 2:
        print("Usage: read_dv_subcodes.py <video_path>", file=sys.stderr)
        sys.exit(2)

    errors = tempfile.TemporaryFile()
    process = open_stream(sys.argv[1], errors)
    first = process.stdout.read(BLOCK)
    system = detect_system(first)
    if system is None:
        process.kill()
        process.wait()
        print("Kein DV-Videostrom: " + error_text(errors), file=sys.stderr)
        sys.exit(1)

    frame_size = FRAME_SIZE[system]
    runs = []
    pending = first
    frame_number = 0
    previous = None
    previous_rec_start = False
    while True:
        frame = pending + process.stdout.read(frame_size - len(pending))
        pending = b""
        if len(frame) < frame_size:
            break
        recorded_at, rec_start = read_frame(frame)
        # Das Kennzeichen kann mehrere Frames lang stehen: nur sein Beginn zählt
        starts_recording = rec_start and not previous_rec_start and recorded_at is not None
        if not runs or starts_recording or not continues(previous, recorded_at):
            runs.append({
                "start": frame_number,
                "end": frame_number,
                "recorded_at": recorded_at.strftime("%Y-%m-%d %H:%M:%S") if recorded_at else None,
                "rec_start": rec_start,
            })
        runs[-1]["end"] = frame_number
        previous = recorded_at
        previous_rec_start = rec_start
        frame_number += 1

    process.stdout.close()
    if process.wait() != 0 and frame_number == 0:
        print("ffmpeg: " + error_text(errors), file=sys.stderr)
        sys.exit(1)

    print(json.dumps({"system": system, "fps": FPS[system], "frames": frame_number, "runs": runs}))


if __name__ == "__main__":
    main()
