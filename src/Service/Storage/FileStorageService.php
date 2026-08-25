<?php

namespace App\Service\Storage;

use App\Entity\File;
use Symfony\Component\Filesystem\Filesystem;

class FileStorageService
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly StoragePathProvider $pathProvider
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Erstellt das übergeordnete Verzeichnis für eine Datei, falls es nicht existiert.
     */
    public function ensureDirectoryExists(File $file): void
    {
        $absolutePath = $this->pathProvider->getAbsolutePath($file);
        $directory = dirname($absolutePath);

        if (!$this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory, 0775);
        }
    }

    /**
     * Löscht die physische Datei vom Dateisystem.
     */
    public function deleteFile(File $file): bool
    {
        $absolutePath = $this->pathProvider->getAbsolutePath($file);

        if ($this->filesystem->exists($absolutePath)) {
            $this->filesystem->remove($absolutePath);
            return true;
        }

        return false;
    }

    /**
     * Verschiebt eine Datei von einem relativen Pfad zu einem anderen.
     */
    public function moveFile(string $fromRelativePath, string $toRelativePath): void
    {
        $absoluteFrom = $this->pathProvider->getAbsoluteFilePath($fromRelativePath);
        $absoluteTo = $this->pathProvider->getAbsoluteFilePath($toRelativePath);

        $targetDir = dirname($absoluteTo);
        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir, 0775);
        }

        $this->filesystem->rename($absoluteFrom, $absoluteTo, true);
    }

    /**
     * Kopiert eine Datei von einem relativen Pfad zu einem anderen.
     */
    public function copyFile(string $fromRelativePath, string $toRelativePath): void
    {
        $absoluteFrom = $this->pathProvider->getAbsoluteFilePath($fromRelativePath);
        $absoluteTo = $this->pathProvider->getAbsoluteFilePath($toRelativePath);

        $targetDir = dirname($absoluteTo);
        if (!$this->filesystem->exists($targetDir)) {
            $this->filesystem->mkdir($targetDir, 0775);
        }

        $this->filesystem->copy($absoluteFrom, $absoluteTo, true);
    }

    /**
     * Gibt die Dateigröße in Bytes zurück.
     */
    public function getFileSize(File $file): int
    {
        $absolutePath = $this->pathProvider->getAbsolutePath($file);

        if ($this->filesystem->exists($absolutePath)) {
            return filesize($absolutePath) ?: 0;
        }

        return 0;
    }

    /**
     * Prüft physisch, ob die Datei existiert.
     */
    public function fileExists(File $file): bool
    {
        return $this->filesystem->exists($this->pathProvider->getAbsolutePath($file));
    }

    /**
     * Erstellt eine komplette Verzeichnisstruktur innerhalb des Storage-Roots.
     */
    public function createDirectoryStructure(string $relativeDirectory): void
    {
        $absoluteDir = $this->pathProvider->getAbsoluteFilePath($relativeDirectory);

        if (!$this->filesystem->exists($absoluteDir)) {
            $this->filesystem->mkdir($absoluteDir, 0775);
        }
    }
}