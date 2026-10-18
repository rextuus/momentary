<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\PhotoFingerprint;
use PHPUnit\Framework\TestCase;

final class PhotoFingerprintTest extends TestCase
{
    /**
     * Fingerabdruck wie aus process_photo.py: Graustufen-Muster (normiert) und ein einziges Farbfach.
     */
    public static function make(int $scene, int $colorBin = 0, float $noise = 0.0): string
    {
        $shape = '';
        for ($i = 0; $i < 256; $i++) {
            $value = sin(($i * (0.1 + (0.37 * $scene))) + $scene) + ($noise * sin($i * 7.3));
            $shape .= pack('c', (int) max(-127, min(127, round($value * 32 * 1.41))));
        }
        $histogram = '';
        for ($i = 0; $i < 128; $i++) {
            $histogram .= pack('v', $i === $colorBin ? 65535 : 0);
        }

        return base64_encode($shape . $histogram);
    }

    public function testIdenticalAndSimilarImages(): void
    {
        self::assertEqualsWithDelta(1.0, PhotoFingerprint::similarity(self::make(1), self::make(1)), 0.02);
        self::assertGreaterThan(0.8, PhotoFingerprint::similarity(self::make(1), self::make(1, 0, 0.3)));
    }

    public function testDifferentImages(): void
    {
        self::assertLessThan(0.3, PhotoFingerprint::similarity(self::make(1), self::make(4, 9)));
    }

    public function testMissingOrBrokenFingerprints(): void
    {
        self::assertNull(PhotoFingerprint::similarity(null, self::make(1)));
        self::assertNull(PhotoFingerprint::similarity('kaputt', self::make(1)));
        self::assertNull(PhotoFingerprint::similarity(base64_encode('zu kurz'), self::make(1)));
    }
}
