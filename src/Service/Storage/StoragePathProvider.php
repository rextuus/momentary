<?php

namespace App\Service\Storage;

use App\Entity\File;
use App\Enum\FilePurpose;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StoragePathProvider
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(string:STORAGE_ROOT)%')]
        private readonly string $storageRoot
    ) {
    }

    /**
     * Gibt den absoluten Dateipfad auf dem Dateisystem zurück.
     */
    public function getAbsolutePath(File $file): string
    {
        return $this->getStorageRoot() . '/' . ltrim($file->getRelativePath(), '/');
    }

    /**
     * NEU: Gibt den absoluten Pfad direkt für eine gegebene relative Pfad-Zeichenkette zurück.
     */
    public function getAbsoluteFilePath(string $relativePath): string
    {
        return rtrim($this->getStorageRoot(), '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * Generiert einen relativen Pfad basierend auf dem Zweck und Dateinamen.
     */
    public function getRelativePath(FilePurpose $purpose, string $filename, ?int $entityId = null): string
    {
        return match ($purpose) {
            FilePurpose::VIDEO_SOURCE, FilePurpose::VIDEO_CONVERTED, FilePurpose::VIDEO_FRAME => $this->createVideoPath($entityId ?? 0, $filename),
            FilePurpose::THUMBNAIL_SCENE, FilePurpose::THUMBNAIL_VIDEO => $this->createThumbnailPath($filename),
            FilePurpose::IMAGE_PROFILE, FilePurpose::IMAGE_FACE => $this->createProfileImagePath($entityId ?? 0, $filename),
        };
    }

    /**
     * Gibt den konfigurierten Storage-Root-Pfad zurück.
     */
    public function getStorageRoot(): string
    {
        return str_starts_with($this->storageRoot, '/')
            ? $this->storageRoot
            : $this->projectDir . '/' . $this->storageRoot;
    }

    /**
     * Generiert einen relativen Pfad für ein Video.
     */
    public function createVideoPath(int $videoId, string $filename): string
    {
        return sprintf('videos/%d/%s', $videoId, $filename);
    }

    /**
     * Generiert einen relativen Pfad für Analyseeinzelbilder (Frames).
     */
    public function createFramePath(int $videoId, string $filename, bool $isRefinement = false): string
    {
        $folder = $isRefinement ? 'refinement_frames' : 'frames';
        return sprintf('videos/%d/%s/%s', $videoId, $folder, $filename);
    }

    /**
     * Generiert einen relativen Pfad für Thumbnails.
     */
    public function createThumbnailPath(string $filename): string
    {
        return sprintf('thumbnails/%s', $filename);
    }

    /**
     * Generiert einen relativen Pfad für Profil- oder Gesichtsbilder von Personen.
     */
    public function createProfileImagePath(int $personId, string $filename): string
    {
        return sprintf('persons/%d/%s', $personId, $filename);
    }

    /**
     * Gibt den relativen Pfad zum Import-Verzeichnis zurück.
     */
    public function getImportRelativePath(string $filename = ''): string
    {
        return $filename !== '' ? 'imports/' . ltrim($filename, '/') : 'imports';
    }

    /**
     * Gibt den absoluten Pfad zum Import-Verzeichnis zurück.
     */
    public function getImportAbsolutePath(string $filename = ''): string
    {
        $path = $this->getStorageRoot() . '/' . $this->getImportRelativePath($filename);
        return rtrim($path, '/');
    }
}