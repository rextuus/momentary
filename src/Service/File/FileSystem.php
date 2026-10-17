<?php

declare(strict_types=1);

namespace App\Service\File;

use League\Flysystem\FilesystemOperator;

readonly class FileSystem
{
    public function __construct(
        private FilesystemOperator $filesystem,
    ) {}

    public function getFilesystem(): FilesystemOperator
    {
        return $this->filesystem;
    }
}
