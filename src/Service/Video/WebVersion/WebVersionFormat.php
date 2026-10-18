<?php

declare(strict_types=1);

namespace App\Service\Video\WebVersion;

/**
 * Format der Web-Fassung: höchstens 1080p (kürzere Seite), H.264 mit etwa 4 Mbit/s, AAC Stereo, Faststart.
 */
final class WebVersionFormat
{
    /** Kürzere Bildseite der Web-Fassung */
    public const int MAX_SHORT_SIDE = 1080;

    /** Bis zu dieser Bitrate (Bit/s) genügt das Original */
    public const int SMALL_ENOUGH_BITRATE = 6_000_000;

    public const string RENDER_DEVICE = '/dev/dri/renderD128';

    /**
     * @param array{shortSide: int, bitrate: int} $probe
     */
    public static function smallEnough(array $probe): bool
    {
        return (
            $probe['shortSide'] <= self::MAX_SHORT_SIDE
            && $probe['bitrate'] > 0
            && $probe['bitrate'] <= self::SMALL_ENOUGH_BITRATE
        );
    }

    /**
     * @return list<string>
     */
    public static function softwareCommand(string $source, string $target): array
    {
        return [
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $source,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-map_metadata',
            '0',
            '-vf',
            self::scaleFilter(),
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '23',
            '-maxrate',
            '4M',
            '-bufsize',
            '8M',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-b:a',
            '128k',
            '-ac',
            '2',
            '-movflags',
            '+faststart',
            $target,
        ];
    }

    /**
     * @return list<string>
     */
    public static function vaapiCommand(string $source, string $target): array
    {
        return [
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-vaapi_device',
            self::RENDER_DEVICE,
            '-i',
            $source,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-map_metadata',
            '0',
            '-vf',
            self::scaleFilter() . ',format=nv12,hwupload',
            '-c:v',
            'h264_vaapi',
            '-b:v',
            '4M',
            '-maxrate',
            '4M',
            '-bufsize',
            '8M',
            '-c:a',
            'aac',
            '-b:a',
            '128k',
            '-ac',
            '2',
            '-movflags',
            '+faststart',
            $target,
        ];
    }

    /**
     * Kürzere Seite auf höchstens 1080 Pixel, Hoch- und Querformat, nie vergrößern; gerade Kantenlängen für H.264.
     */
    private static function scaleFilter(): string
    {
        $max = self::MAX_SHORT_SIDE;

        return "scale=w='if(gte(iw,ih),-2,min({$max},iw))':h='if(gte(iw,ih),min({$max},ih),-2)'";
    }
}
