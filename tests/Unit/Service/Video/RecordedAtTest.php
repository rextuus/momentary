<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video;

use App\Service\Video\Analyze\BetterVideoAnalyzer;
use App\Service\Video\Analyze\Mp4Converter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordedAtTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function creationTimes(): iterable
    {
        yield 'Kamera (UTC)' => ['2025-03-19T14:23:25.000000Z', '2025-03-19 14:23:25'];
        yield 'fehlt' => [null, null];
        yield 'Kamera ohne gestellte Uhr' => ['1970-01-01T00:00:00.000000Z', null];
        yield 'kaputt' => ['gestern', null];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fileNames(): iterable
    {
        yield 'Action-Cam' => ['videos/7/20250319-214201-30ea077dac01.mov', '2025-03-19 20:42:01'];
        yield 'Android' => ['VID_20250320_011005.mp4', '2025-03-20 00:10:05'];
        yield 'Sommerzeit' => ['20250719_120000.MOV', '2025-07-19 10:00:00'];
    }

    #[DataProvider('fileNames')]
    public function testTimestampInFileNameIsLocalTimeAndWins(string $path, string $expectedUtc): void
    {
        $converter = $this->createStub(Mp4Converter::class);
        // Kamera mit falscher Zeitzone: creation_time wird ignoriert
        $converter
            ->method('probe')
            ->willReturn(['video' => null, 'audio' => [], 'duration' => 10.0, 'createdAt' => '2025-03-19T04:15:38Z']);

        self::assertSame($expectedUtc, $this->analyzer($converter)->probeRecordedAt($path)?->format('Y-m-d H:i:s'));
    }

    #[DataProvider('creationTimes')]
    public function testRecordingDateComesFromCreationTime(?string $creationTime, ?string $expected): void
    {
        $converter = $this->createStub(Mp4Converter::class);
        $converter
            ->method('probe')
            ->willReturn(['video' => null, 'audio' => [], 'duration' => 10.0, 'createdAt' => $creationTime]);
        self::assertSame(
            $expected,
            $this->analyzer($converter)->probeRecordedAt('IMG_1234.MOV')?->format('Y-m-d H:i:s'),
        );
    }

    private function analyzer(Mp4Converter $converter): BetterVideoAnalyzer
    {
        $analyzer = new \ReflectionClass(BetterVideoAnalyzer::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(BetterVideoAnalyzer::class, 'mp4Converter')->setValue($analyzer, $converter);

        return $analyzer;
    }
}
