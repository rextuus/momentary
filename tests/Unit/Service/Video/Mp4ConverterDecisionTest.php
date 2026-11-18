<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video;

use App\Service\Video\Analyze\FieldOrder;
use App\Service\Video\Analyze\Mp4Converter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Mp4ConverterDecisionTest extends TestCase
{
    /**
     * @return iterable<string, array{?array{codec: string, pixFmt: string}, list<string>, bool}>
     */
    public static function streams(): iterable
    {
        yield 'Handy/Action-Cam: H.264 + AAC' => [['codec' => 'h264', 'pixFmt' => 'yuv420p'], ['aac'], true];
        yield 'H.264 ohne Ton' => [['codec' => 'h264', 'pixFmt' => 'yuv420p'], [], true];
        yield 'H.264 + MP3' => [['codec' => 'h264', 'pixFmt' => 'yuvj420p'], ['mp3'], true];
        yield 'H.264 + PCM (Camcorder)' => [['codec' => 'h264', 'pixFmt' => 'yuv420p'], ['pcm_s16le'], false];
        yield 'H.264 10 Bit' => [['codec' => 'h264', 'pixFmt' => 'yuv420p10le'], ['aac'], false];
        yield 'H.264 4:2:2' => [['codec' => 'h264', 'pixFmt' => 'yuv422p'], ['aac'], false];
        yield 'HEVC' => [['codec' => 'hevc', 'pixFmt' => 'yuv420p'], ['aac'], false];
        yield 'MPEG-2 (DVD)' => [['codec' => 'mpeg2video', 'pixFmt' => 'yuv420p'], ['ac3'], false];
        yield 'DV' => [['codec' => 'dvvideo', 'pixFmt' => 'yuv411p'], ['pcm_s16le'], false];
        yield 'kein Videostream' => [null, ['aac'], false];
    }

    /**
     * @param array{codec: string, pixFmt: string}|null $video
     * @param list<string>                              $audio
     */
    #[DataProvider('streams')]
    public function testOnlyBrowserCompatibleVideosAreRemuxed(?array $video, array $audio, bool $remux): void
    {
        self::assertSame($remux, Mp4Converter::canBeRemuxed([
            'video' => $video,
            'audio' => $audio,
            'duration' => 10.0,
        ]));
    }

    public function testIdetSummaryUsesTheLastOutput(): void
    {
        $output = <<<'TXT'
            [Parsed_idet_0 @ 0x1] Multi frame detection: TFF:     0 BFF:     0 Progressive:     0 Undetermined:     0
            [Parsed_idet_0 @ 0x2] Single frame detection: TFF:   98 BFF:     0 Progressive:     2 Undetermined:     0
            [Parsed_idet_0 @ 0x2] Multi frame detection: TFF:   100 BFF:     0 Progressive:     0 Undetermined:     0
            TXT;

        self::assertSame('tt', FieldOrder::fromIdet($output));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function idetResults(): iterable
    {
        yield 'unteres Halbbild zuerst' => [
            'Multi frame detection: TFF: 3 BFF: 412 Progressive: 10 Undetermined: 9',
            'bb',
        ];
        yield 'progressiv' => ['Multi frame detection: TFF: 4 BFF: 2 Progressive: 580 Undetermined: 14', 'progressive'];
        yield 'zu wenig eindeutig' => [
            'Multi frame detection: TFF: 150 BFF: 0 Progressive: 120 Undetermined: 330',
            'progressive',
        ];
        yield 'zu kurz' => ['Multi frame detection: TFF: 12 BFF: 0 Progressive: 0 Undetermined: 3', 'progressive'];
        yield 'keine Ausgabe' => ['Invalid data found when processing input', null];
    }

    #[DataProvider('idetResults')]
    public function testIdetDecision(string $output, ?string $expected): void
    {
        self::assertSame($expected, FieldOrder::fromIdet($output));
    }

    public function testInterlacedMaterialIsDeinterlacedFieldByField(): void
    {
        $command = new Mp4Converter(new NullLogger())->reencodeCommand('/in.dv', '/out.mp4', 'bb');

        $vf = $command[array_search('-vf', $command, true) + 1];
        self::assertSame('bwdif=mode=send_field:parity=bff:deint=all', $vf, 'aus 50i wird 50p');
        self::assertNotContains('-vf', new Mp4Converter(new NullLogger())->reencodeCommand(
            '/in.mov',
            '/out.mp4',
            'progressive',
        ));
        self::assertTrue(FieldOrder::isInterlaced('tb'));
        self::assertFalse(FieldOrder::isInterlaced('progressive'));
        self::assertFalse(FieldOrder::isInterlaced(null));
        self::assertSame('tff', FieldOrder::parity('bt'), 'bt: unteres codiert, oberes zuerst angezeigt');
    }

    public function testFieldOrderLabels(): void
    {
        self::assertSame('interlaced, unteres Halbbild zuerst', FieldOrder::label('bb'));
        self::assertSame('interlaced, oberes Halbbild zuerst', FieldOrder::label('tt'));
        self::assertSame('progressiv (Vollbilder)', FieldOrder::label('progressive'));
        self::assertNull(FieldOrder::label(null));
    }
}
