<?php

declare(strict_types=1);

namespace App\Service\Photo;

/**
 * Vergleich zweier Bild-Fingerabdrücke aus process_photo.py: 256 x int8 (16x16 Graustufen, weichgezeichnet und
 * normiert) und 128 x uint16 (HSV-Farbhistogramm), Base64.
 *
 * Ähnlichkeit = Mittel aus Korrelation der Graustufen und Schnittmenge der Histogramme, 0 bis 1. An den
 * Mallorca-Fotos gemessen: gleiche Szene ≥ 0,64, verschiedene Motive ≤ 0,45.
 */
final class PhotoFingerprint
{
    private const int SHAPE_SIZE = 256;
    private const int HISTOGRAM_SIZE = 128;

    public static function similarity(?string $a, ?string $b): ?float
    {
        $left = self::decode($a);
        $right = self::decode($b);
        if ($left === null || $right === null) {
            return null;
        }

        $correlation = 0.0;
        for ($i = 0; $i < self::SHAPE_SIZE; $i++) {
            $correlation += $left[0][$i] * $right[0][$i];
        }
        // int8 = Wert * 32; normierte Werte haben Varianz 1
        $correlation /= self::SHAPE_SIZE * 32 * 32;

        $intersection = 0;
        for ($i = 0; $i < self::HISTOGRAM_SIZE; $i++) {
            $intersection += min($left[1][$i], $right[1][$i]);
        }
        $intersection /= 65535;

        return (max(-1.0, min(1.0, $correlation)) + $intersection) / 2;
    }

    /**
     * @return array{list<int>, list<int>}|null
     */
    private static function decode(?string $fingerprint): ?array
    {
        $bytes = $fingerprint === null ? false : base64_decode($fingerprint, true);
        if ($bytes === false || \strlen($bytes) !== (self::SHAPE_SIZE + (2 * self::HISTOGRAM_SIZE))) {
            return null;
        }
        $shape = unpack('c*', substr($bytes, 0, self::SHAPE_SIZE));
        $histogram = unpack('v*', substr($bytes, self::SHAPE_SIZE));
        if ($shape === false || $histogram === false) {
            return null;
        }

        return [array_values(array_map(intval(...), $shape)), array_values(array_map(intval(...), $histogram))];
    }
}
