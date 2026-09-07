<?php

namespace App\Service;

use App\Service\Storage\StoragePathProvider;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class VideoFileService
{
    public function __construct(
        #[Autowire(service: 'video.storage')]
        private FilesystemOperator $filesystem,
        private StoragePathProvider $pathProvider
    ) {}

    // Gibt den absoluten Pfad für interne Prozesse (FFMPEG etc.) zurück
    public function getAbsolutePath(string| \App\Entity\File $path): string
    {
        if ($path instanceof \App\Entity\File) {
            return $this->pathProvider->getAbsoluteFilePath($path->getRelativePath());
        }
        
        return $this->pathProvider->getAbsoluteFilePath($path);
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