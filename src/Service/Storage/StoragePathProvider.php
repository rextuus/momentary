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
     * Gibt den absoluten Pfad direkt für eine gegebene relative Pfad-Zeichenkette zurück.
     */
    public function getAbsoluteFilePath(string $relativePath): string
    {
        return rtrim($this->getStorageRoot(), '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * Generiert einen relativen Pfad basierend auf dem Zweck und Dateinamen.
     */
    public function getRelativePath(FilePurpose $purpose, string $filename, ?string $directoryIdentifier = null): string
    {
        return match ($purpose) {
            FilePurpose::VIDEO_SOURCE, FilePurpose::VIDEO_CONVERTED, FilePurpose::VIDEO_FRAME => $this->createVideoPath($directoryIdentifier ?? '0', $filename),
            FilePurpose::THUMBNAIL_SCENE, FilePurpose::THUMBNAIL_VIDEO => $this->createThumbnailPath($filename, $directoryIdentifier),
            FilePurpose::IMAGE_PROFILE => $this->createProfileImagePath($directoryIdentifier ?? '0', $filename),
            FilePurpose::IMAGE_FACE => $this->createFacePath($directoryIdentifier ?? '0', $filename),
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
    public function createVideoPath(string $directoryIdentifier, string $filename): string
    {
        return sprintf('videos/%s/%s', $directoryIdentifier, $filename);
    }

    /**
     * Generiert einen relativen Pfad für Analyseeinzelbilder (Frames).
     */
    public function createFramePath(string $directoryIdentifier, string $filename, bool $isRefinement = false): string
    {
        $folder = $isRefinement ? 'refinement_frames' : 'frames';
        return sprintf('videos/%s/%s/%s', $directoryIdentifier, $folder, $filename);
    }

    /**
     * Generiert einen relativen Pfad für Thumbnails (optional mit Video-ID bezug).
     */
    public function createThumbnailPath(string $filename, ?string $directoryIdentifier = null): string
    {
        if ($directoryIdentifier !== null && $directoryIdentifier !== '') {
            return sprintf('images/videos/%s/thumbnails/%s', $directoryIdentifier, $filename);
        }

        return sprintf('images/thumbnails/%s', $filename);
    }

    /**
     * Generiert einen relativen Pfad für Gesichtsbilder.
     */
    public function createFacePath(string $directoryIdentifier, string $filename): string
    {
        return sprintf('images/faces/%s/%s', $directoryIdentifier, $filename);
    }

    /**
     * Generiert einen relativen Pfad für Profil- oder Gesichtsbilder von Personen.
     */
    public function createProfileImagePath(string $directoryIdentifier, string $filename): string
    {
        return sprintf('persons/%s/%s', $directoryIdentifier, $filename);
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
