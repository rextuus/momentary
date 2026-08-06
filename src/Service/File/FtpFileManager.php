<?php

namespace App\Service\File;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class FtpFileManager
{
    private const TARGET_DIR = '/var/www/html/public/uploads/import';

    public function __construct(
        #[Autowire(param: 'app.ftp_source_dirs')]
        private array $sourceDirs
    ) {
    }

    public function getSourceDirs(): array
    {
        return $this->sourceDirs;
    }

    public function listFiles(string $sourceDir): array
    {
        if (!in_array($sourceDir, $this->sourceDirs)) {
            throw new \InvalidArgumentException('Invalid source directory');
        }

        $fs = new Filesystem();
        if (!$fs->exists($sourceDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($sourceDir);

        $files = [];
        foreach ($finder as $file) {
            $files[] = [
                'filename' => $file->getFilename(),
                'path' => $file->getRealPath(),
                'size' => $file->getSize(),
            ];
        }

        return $files;
    }

    public function moveFile(string $sourcePath): void
    {
        // Security check: ensure the path is within one of the source dirs
        $isValid = false;
        foreach ($this->sourceDirs as $sourceDir) {
            if (str_starts_with($sourcePath, $sourceDir)) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            throw new \InvalidArgumentException('Invalid file path');
        }

        $fs = new Filesystem();
        if (!$fs->exists($sourcePath)) {
             throw new \RuntimeException('File not found');
        }

        if (!$fs->exists(self::TARGET_DIR)) {
            $fs->mkdir(self::TARGET_DIR);
        }

        $targetPath = self::TARGET_DIR . DIRECTORY_SEPARATOR . basename($sourcePath);

        if ($fs->exists($targetPath)) {
            throw new \RuntimeException('File already exists in target');
        }

        $fs->rename($sourcePath, $targetPath);
    }

    public function renameFile(string $oldPath, string $newName): void
    {
        // 1. Check if the file is in app_uploads
        $appUploadsDir = '/var/www/html/var/uploads/app_uploads';
        if (!str_starts_with($oldPath, $appUploadsDir)) {
             throw new \InvalidArgumentException('Renaming is only allowed for app_uploads.');
        }

        // 2. Perform rename
        $fs = new Filesystem();
        if (!$fs->exists($oldPath)) {
             throw new \RuntimeException('File not found');
        }

        $dir = dirname($oldPath);
        $newPath = $dir . DIRECTORY_SEPARATOR . $newName;

        if ($fs->exists($newPath)) {
            throw new \RuntimeException('File already exists');
        }

        $fs->rename($oldPath, $newPath);
    }
}
