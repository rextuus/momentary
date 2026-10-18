<?php

declare(strict_types=1);

namespace App\Service\Video;

use App\Entity\File;
use App\Entity\Video;
use App\Service\Storage\StoragePathProvider;

/**
 * Abspielbare Videodatei im Storage (MP4), für Streams ohne Jellyfin (Share-Links, signierte Stream-URLs).
 */
class VideoFiles
{
    public const string QUALITY_WEB = 'web';
    public const string QUALITY_ORIGINAL = 'original';

    public function __construct(
        private readonly StoragePathProvider $pathProvider,
    ) {}

    /**
     * @return string|null absoluter Pfad, null wenn es (noch) keine MP4 gibt
     */
    public function playablePath(Video $video): ?string
    {
        foreach ([$video->getConvertedFile(), $video->getSourceFile()] as $file) {
            $path = $this->existing($file);
            if ($path !== null && str_ends_with(strtolower($path), '.mp4')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Datei zum Streamen: die Web-Fassung, sonst (oder auf Wunsch) das abspielbare Original.
     *
     * @return array{path: string, quality: self::QUALITY_*}|null
     */
    public function streamFile(Video $video, string $quality = self::QUALITY_WEB): ?array
    {
        $web = $quality === self::QUALITY_WEB ? $this->existing($video->getWebFile()) : null;
        if ($web !== null) {
            return ['path' => $web, 'quality' => self::QUALITY_WEB];
        }
        $original = $this->playablePath($video);

        return $original !== null ? ['path' => $original, 'quality' => self::QUALITY_ORIGINAL] : null;
    }

    public function existing(?File $file): ?string
    {
        if ($file === null) {
            return null;
        }
        $path = $this->pathProvider->getAbsolutePath($file);

        return is_file($path) ? $path : null;
    }
}
