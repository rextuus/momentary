<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video;

use App\Service\Video\Analyze\Mp4Converter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CameraNameTest extends TestCase
{
    /**
     * @param array<string, string> $tags
     */
    #[DataProvider('tags')]
    public function testCameraNameFromVideoMetadata(array $tags, ?string $expected): void
    {
        self::assertSame($expected, Mp4Converter::cameraName($tags));
    }

    /**
     * @return iterable<string, array{array<string, string>, ?string}>
     */
    public static function tags(): iterable
    {
        yield 'iPhone' => [
            ['com.apple.quicktime.make' => 'Apple', 'com.apple.quicktime.model' => 'iPhone 13'],
            'Apple iPhone 13',
        ];
        yield 'Android' => [
            ['com.android.manufacturer' => 'samsung', 'com.android.model' => 'SM-G991B'],
            'samsung SM-G991B',
        ];
        yield 'Modell enthält Hersteller' => [['make' => 'Canon', 'model' => 'Canon EOS R6'], 'Canon EOS R6'];
        yield 'Action-Cam ohne Angaben' => [['creation_time' => '2025-03-18T12:07:37.000000Z'], null];
    }
}
