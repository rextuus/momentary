<?php

namespace App\Service;

use App\Service\Storage\StoragePathProvider;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Service\PathConstants;

class ImageFileService
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private StoragePathProvider $pathProvider
    ) {}

    public function getFilesystem(): FilesystemOperator
    {
        return $this->filesystem;
    }

    public function getAbsolutePath(string $relativeFilePath): string
    {
        return $this->pathProvider->getAbsoluteFilePath($relativeFilePath);
    }
}
