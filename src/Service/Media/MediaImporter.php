<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Medium;
use App\Entity\User;
use App\Entity\Video;
use App\Repository\PhotoRepository;
use App\Repository\VideoRepository;
use App\Service\Photo\PhotoImporter;
use App\Service\Search\MediumIndexer;
use App\Service\Tag\UploadBatchTagService;
use App\Service\Video\VideoCreationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ein Weg für alle Medien: erkennt an der Dateiendung, ob es ein Video oder ein Foto ist, und übernimmt es.
 * Benutzt vom Web-Upload und vom Import-Ordner. Doppelte Dateien (gleicher SHA-256) werden erkannt.
 */
class MediaImporter
{
    public const string TYPE_VIDEO = 'video';
    public const string TYPE_PHOTO = 'photo';

    public const array VIDEO_EXTENSIONS = [
        'mp4',
        'mov',
        'mkv',
        'webm',
        'avi',
        'm4v',
        'mts',
        'm2ts',
        'mpg',
        'mpeg',
        'wmv',
        'flv',
        '3gp',
    ];

    public function __construct(
        private readonly VideoCreationService $videoCreationService,
        private readonly VideoRepository $videoRepository,
        private readonly PhotoRepository $photoRepository,
        private readonly PhotoImporter $photoImporter,
        private readonly UploadBatchTagService $batchTagService,
        private readonly MediumIndexer $indexer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return self::TYPE_*|null null für nicht unterstützte Dateien
     */
    public static function typeOf(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match (true) {
            \in_array($extension, self::VIDEO_EXTENSIONS, true) => self::TYPE_VIDEO,
            \in_array($extension, PhotoImporter::EXTENSIONS, true) => self::TYPE_PHOTO,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return [...self::VIDEO_EXTENSIONS, ...PhotoImporter::EXTENSIONS];
    }

    public function findByHash(string $hash): ?Medium
    {
        return (
            $this->videoRepository->findOneBy(['fileHash' => $hash]) ?? $this->photoRepository->findOneBy([
                'fileHash' => $hash,
            ])
        );
    }

    /**
     * Datei übernehmen. Videos werden in den Storage verschoben, Fotos kopiert (der Aufrufer räumt auf).
     *
     * @param string      $originalName Dateiname beim Upload bzw. im Import-Ordner (bestimmt den Typ)
     * @param string|null $batch        Gruppen-Tag, z. B. der Name des hochgeladenen Ordners
     * @param string|null $title        eigener Titel (sonst der Dateiname)
     */
    public function import(
        string $path,
        string $originalName,
        ?User $owner = null,
        ?string $batch = null,
        ?string $title = null,
    ): MediaImportResult {
        $type = self::typeOf($originalName);
        if ($type === null) {
            throw new \InvalidArgumentException(sprintf('Dateityp „.%s“ wird nicht unterstützt.', pathinfo(
                $originalName,
                PATHINFO_EXTENSION,
            )));
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException(sprintf('„%s“ konnte nicht gelesen werden.', $originalName));
        }

        $existing = $this->findByHash($hash);
        if ($existing !== null) {
            return new MediaImportResult($existing, true, $this->tag($existing, $batch));
        }

        $medium = $type === self::TYPE_VIDEO
            ? $this->importVideo($path, $originalName, $hash, $owner, $batch, $title)
            : $this->photoImporter->import($path, $owner, $originalName)->photo;
        if ($type === self::TYPE_PHOTO && $title !== null && $title !== '') {
            $medium->setTitle($title);
            $this->entityManager->flush();
        }

        return new MediaImportResult($medium, false, $this->tag($medium, $batch, reindex: false));
    }

    private function importVideo(
        string $path,
        string $originalName,
        string $hash,
        ?User $owner,
        ?string $batch,
        ?string $title,
    ): Video {
        $video = new Video();
        $video->setTitle($this->uniqueVideoTitle(
            $title !== null && $title !== '' ? $title : pathinfo($originalName, PATHINFO_FILENAME),
            $batch,
            $hash,
        ));
        $video->setFileHash($hash);
        $video->setOwner($owner);

        // Testmodus: verschiebt die Datei, auch wenn sie nicht per HTTP hochgeladen wurde (Import-Ordner)
        $file = new UploadedFile($path, $originalName, null, null, true);
        $this->videoCreationService->createVideo($video, $file, null, null, true);

        return $video;
    }

    /**
     * Videotitel müssen eindeutig sein (Jellyfin-Export, Verarbeitung).
     */
    private function uniqueVideoTitle(string $title, ?string $batch, string $hash): string
    {
        if ($this->videoRepository->findOneBy(['title' => $title]) === null) {
            return $title;
        }
        if ($batch !== null && $batch !== '') {
            $title = $batch . ' – ' . $title;
        }
        if ($this->videoRepository->findOneBy(['title' => $title]) !== null) {
            $title .= ' (' . substr($hash, 0, 8) . ')';
        }

        return $title;
    }

    /**
     * Gruppen-Tag setzen. Fehler sind nicht kritisch: das Medium ist schon übernommen.
     *
     * @param bool $reindex für schon analysierte Medien (neue Medien kommen nach der Analyse in den Index)
     */
    public function tag(Medium $medium, ?string $batch, bool $reindex = true): ?string
    {
        $batch = trim((string) $batch);
        if ($batch === '') {
            return null;
        }

        try {
            $name = $this->batchTagService->tag($medium, $batch)->getName();
            if ($reindex) {
                $this->indexer->reindex([$medium]);
            }

            return $name;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Gruppen-Tag für Medium %d: %s', (int) $medium->getId(), $e->getMessage()));

            return null;
        }
    }
}
