<?php

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class VideoFileService
{
    public function __construct(
        // Wir injizieren hier den Flysystem-Storage für Videos (den wir gleich in der config definieren)
        #[Autowire(service: 'video.storage')]
        private FilesystemOperator $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {}

    // Gibt den absoluten Pfad für interne Prozesse (FFMPEG etc.) zurück
    public function getAbsolutePath(string $relativeKey): string
    {
        // Hier definieren wir, dass "relativeKey" innerhalb des Video-Storages liegt
        // Wenn das Flysystem lokal ist, ist das einfach der Pfad auf der Festplatte
        return $this->projectDir . '/var/uploads/app_uploads/' . ltrim($relativeKey, '/');
    }

    public function exists(string $relativeKey): bool
    {
        return $this->filesystem->fileExists($relativeKey);
    }

    public function getFilesystem(): FilesystemOperator
    {
        return $this->filesystem;
    }
}