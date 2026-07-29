<?php

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ImageFileService
{
    public function __construct(
        private FilesystemOperator $filesystem,
        #[Autowire('%kernel.project_dir%/public/uploads')]
        private string $basePath
    ) {}

    public function getFilesystem(): FilesystemOperator
    {
        return $this->filesystem;
    }

    public function getAbsolutePath(string $relativeFilePath): string
    {
        return $this->basePath . '/' . $relativeFilePath;
    }
}
