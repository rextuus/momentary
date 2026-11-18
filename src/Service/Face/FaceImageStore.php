<?php

declare(strict_types=1);

namespace App\Service\Face;

use App\Entity\File;
use App\Entity\Medium;
use App\Enum\FilePurpose;
use App\Service\ImageFileService;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Legt das Bild, in dem Gesichter erkannt wurden (ganzer Frame), unter images/faces/<mediumId>/ ab.
 * Zugeschnitten wird erst beim Anzeigen (imgproxy, FaceCropExtension).
 */
class FaceImageStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageFileService $imageFileService,
        private readonly StoragePathProvider $pathProvider,
    ) {}

    public function store(Medium $medium, string $framePath): File
    {
        // Während der Erkennung kann die Datei verschwinden; ohne clearstatcache lieferte PHP das alte Ergebnis
        clearstatcache(true, $framePath);
        if (!file_exists($framePath)) {
            throw new \RuntimeException("Frame file disappeared before processing: {$framePath}");
        }

        $imageContent = file_get_contents($framePath);
        if ($imageContent === false) {
            throw new \RuntimeException("Frame file could not be read: {$framePath}");
        }

        $storagePath = $this->pathProvider->createFacePath(
            (string) $medium->getId(),
            Uuid::uuid4()->toString() . '.jpg',
        );
        $this->imageFileService->getFilesystem()->write($storagePath, $imageContent);

        $file = $this->entityManager->getRepository(File::class)->findOneBy(['relativePath' => $storagePath]);
        if (!$file) {
            $file = new File();
            $file->setRelativePath($storagePath);
            $file->setMimeType('image/jpeg');
            $file->setFileSize(\strlen($imageContent));
            $file->setPurpose(FilePurpose::IMAGE_FACE);
            $this->entityManager->persist($file);
        }

        return $file;
    }
}
