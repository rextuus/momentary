"""
Bereitet ein Foto für Anzeige und Analyse auf.

- liest JPEG, PNG und HEIC/HEIF (iPhone)
- dreht das Bild laut EXIF-Orientierung
- schreibt eine Anzeige-Fassung (JPEG, längste Seite max. 2560 px) und ein Vorschaubild (max. 480 px)
- liest Aufnahmedatum, GPS-Position und Kamera aus den EXIF-Daten
- berechnet Bild-Fingerabdruck (für Serienfotos) und Schärfe (Varianz des Laplace-Filters)

Ausgabe: JSON auf stdout
    {"width": 4032, "height": 3024, "takenAt": "2025-03-19T12:15:37+01:00", "latitude": 39.57, "longitude": 2.65,
     "camera": "Apple iPhone 13", "fingerprint": "…", "sharpness": 412.7}

Mit --measure statt --display/--thumbnail werden nur fingerprint und sharpness eines Bildes berechnet.
"""
import argparse
import base64
import json
from datetime import datetime

from PIL import Image, ImageFilter, ImageOps, ImageStat

try:
    import numpy
except ImportError:  # ohne numpy: Schärfe über den Kantenfilter von Pillow
    numpy = None

try:
    from pillow_heif import register_heif_opener

    register_heif_opener()
except ImportError:  # ohne pillow-heif gehen nur JPEG/PNG
    pass

EXIF_IFD = 0x8769
GPS_IFD = 0x8825
DATETIME_ORIGINAL = 36867
OFFSET_TIME_ORIGINAL = 36881
DATETIME = 306
MAKE = 271
MODEL = 272


def taken_at(exif):
    sub = exif.get_ifd(EXIF_IFD)
    raw = sub.get(DATETIME_ORIGINAL) or exif.get(DATETIME)
    if not raw:
        return None
    try:
        value = datetime.strptime(str(raw).strip("\x00 "), "%Y:%m:%d %H:%M:%S")
    except ValueError:
        return None
    offset = sub.get(OFFSET_TIME_ORIGINAL)
    iso = value.isoformat()
    if offset and len(str(offset).strip()) == 6:
        iso += str(offset).strip()
    return iso


def to_degrees(value, ref):
    try:
        d, m, s = (float(x) for x in value)
    except (TypeError, ValueError):
        return None
    degrees = d + m / 60 + s / 3600
    return -degrees if ref in ("S", "W") else degrees


def location(exif):
    gps = exif.get_ifd(GPS_IFD)
    if not gps or 2 not in gps or 4 not in gps:
        return None, None
    lat = to_degrees(gps[2], gps.get(1))
    lon = to_degrees(gps[4], gps.get(3))
    if lat is None or lon is None or (lat == 0 and lon == 0):
        return None, None
    return round(lat, 6), round(lon, 6)


def camera(exif):
    make = str(exif.get(MAKE) or "").strip("\x00 ")
    model = str(exif.get(MODEL) or "").strip("\x00 ")
    if model.lower().startswith(make.lower()):
        make = ""
    name = f"{make} {model}".strip()
    return name or None


def fingerprint(image):
    """Bild-Fingerabdruck für Serienfotos (Base64, 640 Byte), None ohne numpy.

    - 16x16 Graustufen, stark weichgezeichnet und normiert (256 x int8): robust gegen leichtes Verwackeln
    - Farbhistogramm in HSV mit 8x4x4 Fächern (128 x uint16)
    Vergleich: App\\Service\\Photo\\PhotoFingerprint
    """
    if numpy is None:
        return None
    small = image.convert("RGB")
    small.thumbnail((256, 256), Image.LANCZOS)
    gray = small.convert("L").filter(ImageFilter.GaussianBlur(4)).resize((16, 16), Image.LANCZOS)
    values = numpy.asarray(gray, dtype=numpy.float32).ravel()
    values = (values - values.mean()) / (values.std() + 1e-6)
    shape = numpy.clip(numpy.round(values * 32), -127, 127).astype(numpy.int8)
    hsv = numpy.asarray(small.convert("HSV")).reshape(-1, 3)
    hist, _ = numpy.histogramdd(hsv, bins=(8, 4, 4), range=((0, 256), (0, 256), (0, 256)))
    hist = numpy.round(hist.ravel() / hist.sum() * 65535).astype("<u2")
    return base64.b64encode(shape.tobytes() + hist.tobytes()).decode("ascii")


def sharpness(image):
    """Varianz des Laplace-Filters auf einer Fassung mit 1024 px: höher = schärfer."""
    gray = image.convert("L")
    gray.thumbnail((1024, 1024), Image.LANCZOS)
    if numpy is not None:
        a = numpy.asarray(gray, dtype=numpy.float32)
        lap = a[1:-1, :-2] + a[1:-1, 2:] + a[:-2, 1:-1] + a[2:, 1:-1] - 4 * a[1:-1, 1:-1]
        return round(float(lap.var()), 2)
    edges = gray.filter(ImageFilter.FIND_EDGES)
    return round(float(ImageStat.Stat(edges).var[0]), 2)


def measure(path):
    with Image.open(path) as original:
        image = ImageOps.exif_transpose(original)
        print(json.dumps({"fingerprint": fingerprint(image), "sharpness": sharpness(image)}))


def save_resized(image, path, max_side, quality):
    copy = image.copy()
    copy.thumbnail((max_side, max_side), Image.LANCZOS)
    copy.save(path, "JPEG", quality=quality, optimize=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--display")
    parser.add_argument("--thumbnail")
    parser.add_argument("--measure", action="store_true")
    args = parser.parse_args()

    if args.measure:
        measure(args.input)
        return
    if not args.display or not args.thumbnail:
        parser.error("--display und --thumbnail sind nötig (oder --measure)")

    with Image.open(args.input) as original:
        exif = original.getexif()
        image = ImageOps.exif_transpose(original)
        if image.mode not in ("RGB", "L"):
            image = image.convert("RGB")
        width, height = image.size

        save_resized(image, args.display, 2560, 88)
        save_resized(image, args.thumbnail, 480, 82)
        fingerprint_value = fingerprint(image)
        sharp = sharpness(image)

    latitude, longitude = location(exif)
    print(json.dumps({
        "width": width,
        "height": height,
        "takenAt": taken_at(exif),
        "latitude": latitude,
        "longitude": longitude,
        "camera": camera(exif),
        "fingerprint": fingerprint_value,
        "sharpness": sharp,
    }))


if __name__ == "__main__":
    main()
