<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\File;
use App\Entity\Photo;
use App\Entity\User;
use App\Enum\FilePurpose;
use App\Message\AnalyzePhotoMessage;
use App\Repository\PhotoRepository;
use App\Service\Storage\StoragePathProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Übernimmt Fotodateien in den Storage und stößt die Analyse an. Doppelte Dateien (gleicher Inhalt) werden erkannt.
 */
class PhotoImporter
{
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif'];

    private const array MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PhotoRepository $photoRepository,
        private readonly StoragePathProvider $pathProvider,
        private readonly MessageBusInterface $bus,
    ) {}

    public static function isSupported(string $filename): bool
    {
        return \in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * @param string|null $originalName Dateiname beim Upload (sonst der Name von $path)
     */
    public function import(string $path, ?User $owner = null, ?string $originalName = null): PhotoImportResult
    {
        $name = $originalName ?? basename($path);
        if (!self::isSupported($name)) {
            throw new \InvalidArgumentException(sprintf('"%s" ist kein unterstütztes Bildformat.', $name));
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException(sprintf('"%s" konnte nicht gelesen werden.', $name));
        }
        $existing = $this->photoRepository->findOneBy(['fileHash' => $hash]);
        if ($existing !== null) {
            return new PhotoImportResult($existing, duplicate: true);
        }

        $photo = new Photo()
            ->setTitle(pathinfo($name, PATHINFO_FILENAME))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setOwner($owner)
            ->setFileHash($hash);
        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $relativePath = $this->pathProvider->createPhotoPath((string) $photo->getId(), 'original.' . $extension);
        $target = $this->pathProvider->getAbsoluteFilePath($relativePath);
        new Filesystem()->copy($path, $target, true);

        $file = new File();
        $file->setRelativePath($relativePath);
        $file->setMimeType(self::MIME_TYPES[$extension]);
        $file->setFileSize((int) filesize($target));
        $file->setPurpose(FilePurpose::PHOTO_SOURCE);
        $photo->setSourceFile($file);
        $this->entityManager->flush();

        $this->bus->dispatch(new AnalyzePhotoMessage((int) $photo->getId()));

        return new PhotoImportResult($photo, duplicate: false);
    }
}
