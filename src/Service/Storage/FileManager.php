<?php

namespace App\Service\Storage;

use App\Entity\File;
use App\Entity\Person;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Enum\FilePurpose;
use App\Enum\MimeType;
use App\Repository\FileRepository;

class FileManager
{
    public function __construct(
        private readonly StoragePathProvider $pathProvider,
        private readonly FileRepository $fileRepository
    ) {
    }

    public function createVideoSourceFile(Video $video, string $filename, MimeType $mimeType = MimeType::MP4): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::VIDEO_SOURCE,
            filename: $filename,
            directoryIdentifier: $video->getDirectoryHash() ?? (string)$video->getId(),
            mimeType: $mimeType->value
        );
    }

    public function createConvertedVideoFile(Video $video, string $filename, MimeType $mimeType = MimeType::MP4): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::VIDEO_CONVERTED,
            filename: $filename,
            directoryIdentifier: $video->getDirectoryHash() ?? (string)$video->getId(),
            mimeType: $mimeType->value
        );
    }

    public function createVideoThumbnailFile(Video $video, string $filename, MimeType $mimeType = MimeType::JPEG): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::THUMBNAIL_VIDEO,
            filename: $filename,
            directoryIdentifier: $video->getDirectoryHash() ?? (string)$video->getId(),
            mimeType: $mimeType->value
        );
    }

    public function createSceneThumbnailFile(VideoScene $scene, string $filename, MimeType $mimeType = MimeType::JPEG): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::THUMBNAIL_SCENE,
            filename: $filename,
            directoryIdentifier: (string)$scene->getId(),
            mimeType: $mimeType->value
        );
    }

    public function createProfileImageFile(Person $person, string $filename, MimeType $mimeType = MimeType::JPEG): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::IMAGE_PROFILE,
            filename: $filename,
            directoryIdentifier: (string)$person->getId(),
            mimeType: $mimeType->value
        );
    }

    public function createFaceFile(VideoFace $face, string $filename, MimeType $mimeType = MimeType::JPEG): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::IMAGE_FACE,
            filename: $filename,
            directoryIdentifier: (string)$face->getId(),
            mimeType: $mimeType->value
        );
    }

    /**
     * NEU: Erstellt ein allgemeines Thumbnail-File (nutzt Zweck THUMBNAIL_VIDEO ohne starre Video-ID Verknüpfung im Pfad, falls gewünscht).
     */
    public function createThumbnailFile(string $thumbnailName, MimeType $mimeType = MimeType::JPEG): File
    {
        return $this->createFileEntity(
            purpose: FilePurpose::THUMBNAIL_VIDEO,
            filename: $thumbnailName,
            directoryIdentifier: null,
            mimeType: $mimeType->value
        );
    }

    public function saveFile(File $file, bool $flush = true): void
    {
        $this->fileRepository->save($file, $flush);
    }

    public function deleteFile(File $file, bool $flush = true): void
    {
        $absolutePath = $this->pathProvider->getAbsolutePath($file);

        if (file_exists($absolutePath)) {
            @unlink($absolutePath);
        }

        $this->fileRepository->remove($file, $flush);
    }

    public function getFileSize(File $file): int
    {
        $absolutePath = $this->pathProvider->getAbsolutePath($file);

        if (file_exists($absolutePath)) {
            return filesize($absolutePath) ?: 0;
        }

        return 0;
    }

    public function fileExists(File $file): bool
    {
        return file_exists($this->pathProvider->getAbsolutePath($file));
    }

    private function createFileEntity(FilePurpose $purpose, string $filename, ?string $directoryIdentifier, string $mimeType): File
    {
        $relativePath = $this->pathProvider->getRelativePath($purpose, $filename, $directoryIdentifier);

        $file = new File();
        $file->setPurpose($purpose);
        $file->setRelativePath($relativePath);
        $file->setMimeType($mimeType);
        $file->setFileSize(0);

        return $file;
    }
}