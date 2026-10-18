<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Service\Storage\StoragePathProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Dateien aus den SFTP-Upload-Verzeichnissen in den Import-Ordner verschieben (Übernahme dort, siehe ImportFolder).
 */
class FtpFileManager
{
    public function __construct(
        /** @var list<string> */
        #[Autowire(param: 'app.ftp_source_dirs')]
        private array $sourceDirs,
        private readonly StoragePathProvider $pathProvider,
    ) {}

    /**
     * @return list<string>
     */
    public function getSourceDirs(): array
    {
        return $this->sourceDirs;
    }

    /**
     * @return list<array{filename: string, path: string|false, size: int|false}>
     */
    public function listFiles(string $sourceDir): array
    {
        if (!in_array($sourceDir, $this->sourceDirs, strict: true)) {
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

        $targetDir = $this->pathProvider->getImportAbsolutePath();
        if (!$fs->exists($targetDir)) {
            $fs->mkdir($targetDir);
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . basename($sourcePath);

        if ($fs->exists($targetPath)) {
            throw new \RuntimeException('File already exists in target');
        }

        $fs->rename($sourcePath, $targetPath);
    }

    public function renameFile(string $oldPath, string $newName): void
    {
        // 1. Check if the file is in app_uploads
        $appUploadsDir = $this->pathProvider->getStorageRoot() . '/app_uploads';
        if (!str_starts_with($oldPath, $appUploadsDir)) {
            throw new \InvalidArgumentException('Renaming is only allowed for app_uploads.');
        }

        // 2. Perform rename
        $fs = new Filesystem();
        if (!$fs->exists($oldPath)) {
            throw new \RuntimeException('File not found');
        }

        $dir = dirname($oldPath);
        $oldExtension = pathinfo($oldPath, PATHINFO_EXTENSION);
        $newExtension = pathinfo($newName, PATHINFO_EXTENSION);

        $finalNewName = $newName;
        if (empty($newExtension) && !empty($oldExtension)) {
            $finalNewName = $newName . '.' . $oldExtension;
        }

        $newPath = $dir . DIRECTORY_SEPARATOR . $finalNewName;

        if ($fs->exists($newPath)) {
            throw new \RuntimeException('File already exists');
        }

        $fs->rename($oldPath, $newPath);
    }
}
