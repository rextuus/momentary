<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\File;
use App\Entity\Medium;
use App\Enum\FilePurpose;
use App\Service\Video\Analyze\BetterVideoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hinweis „vermutlich schon vorhanden“ für den Import-Ordner, ohne jede Datei zu hashen (Videos sind oft mehrere GB).
 */
class KnownMedia
{
    /** @var array<int, true> */
    private array $sizes = [];

    /** @var array<int, true> */
    private array $times = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Größen und Kamerazeiten vorhandener Medien laden (einmal je Auflistung).
     */
    public function load(): static
    {
        $this->sizes = array_fill_keys($this->sourceSizes(), true);
        $this->times = array_fill_keys($this->cameraTimes(), true);

        return $this;
    }

    public function matches(string $filename, int $size): bool
    {
        $time = BetterVideoAnalyzer::recordedAtFromFilename($filename);

        return isset($this->sizes[$size]) || $time !== null && isset($this->times[$time->getTimestamp()]);
    }

    /**
     * Ältere Videos haben keinen Hash und als Original nur noch die konvertierte Datei: Dann hilft die Kamerazeit, die
     * bei Action-Cams und Android im Dateinamen steht.
     *
     * @return list<int> Kamerazeiten (Unix-Zeit) vorhandener Medien
     */
    private function cameraTimes(): array
    {
        $times = $this->entityManager
            ->createQueryBuilder()
            ->select('m.cameraTakenAt')
            ->from(Medium::class, 'm')
            ->where('m.cameraTakenAt IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn(mixed $time): int => $time instanceof \DateTimeInterface
            ? $time->getTimestamp()
            : (int) strtotime((string) $time . ' UTC'), $times));
    }

    /**
     * @return list<int> Dateigrößen der Originale schon importierter Videos und Fotos
     */
    private function sourceSizes(): array
    {
        return array_values(array_map(
            intval(...),
            $this->entityManager
                ->createQueryBuilder()
                ->select('DISTINCT f.fileSize')
                ->from(File::class, 'f')
                ->where('f.purpose IN (:purposes)')
                ->andWhere('f.fileSize > 0')
                ->setParameter('purposes', [FilePurpose::VIDEO_SOURCE, FilePurpose::PHOTO_SOURCE])
                ->getQuery()
                ->getSingleColumnResult(),
        ));
    }
}
