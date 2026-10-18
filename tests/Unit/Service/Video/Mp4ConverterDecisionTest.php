<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Video;

use App\Service\Video\Analyze\Mp4Converter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
