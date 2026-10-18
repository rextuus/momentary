<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\CameraClockOffset;
use App\Entity\Medium;
use App\Service\Search\MediumIndexer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Falsch gehende Kamera-Uhren: gemerkte Abweichungen anwenden und unplausible Aufnahmezeiten erkennen.
 */
class CameraClock
{
    /** Davor gab es keine Digitalkameras in der Familie: Uhr nach leerem Akku auf Werkseinstellung */
    public const string EARLIEST_PLAUSIBLE = '2000-01-01';

    /** Größter Abstand der Aufnahmen, aus denen eine Abweichung gemerkt wird */
    private const string MAX_REMEMBERED_SPAN = '31 days';

    /** @var list<CameraClockOffset>|null */
    private ?array $offsets = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediumIndexer $indexer,
    ) {}

    /**
     * Gemerkte Abweichung anwenden, falls eine passt. Von Hand korrigierte Zeiten bleiben unangetastet.
     *
     * @return bool ob die Aufnahmezeit verschoben wurde
     */
    public function adjust(Medium $medium): bool
    {
        if ($medium->getDateSource() === Medium::DATE_MANUAL) {
            return false;
        }
        $offset = $this->findOffset($medium);
        $cameraTime = $medium->getCameraTakenAt();
        if ($offset === null || $cameraTime === null) {
            return false;
        }

        $corrected = $cameraTime->modify(sprintf('%+d seconds', $offset->getOffsetSeconds()));
        if (
            $medium->getDateSource() === Medium::DATE_CAMERA_OFFSET
            && $medium->getTakenAt()?->getTimestamp() === $corrected->getTimestamp()
        ) {
            return false;
        }
        $medium->setRecordedAt($corrected, Medium::DATE_CAMERA_OFFSET);

        return true;
    }

    public function findOffset(Medium $medium): ?CameraClockOffset
    {
        foreach ($this->offsets() as $offset) {
            if ($offset->matches($medium)) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Abweichung aus einer Korrektur merken: Kameras und Zeitfenster der verschobenen Medien.
     *
     * @throws \DomainException wenn sich nichts Sinnvolles merken lässt (Meldung für den Benutzer)
     *
     * @param list<Medium> $media verschobene Medien
     */
    public function remember(
        Medium $anchor,
        \DateTimeImmutable $correct,
        array $media,
        ?string $label,
    ): CameraClockOffset {
        $anchorTime = $anchor->getCameraTakenAt();
        $times = array_values(array_filter(array_map(
            static fn(Medium $m): ?\DateTimeImmutable => $m->getCameraTakenAt(),
            $media,
        )));
        if ($anchorTime === null || $times === []) {
            throw new \DomainException('Keine Abweichung gemerkt: Die Zeit laut Kamera ist unbekannt.');
        }
        // Eine Kamera-Uhr geht über Wochen gleich falsch, nicht über Jahre: dann ist etwas anderes im Spiel
        if (min($times)->modify('+' . self::MAX_REMEMBERED_SPAN) < max($times)) {
            throw new \DomainException(sprintf(
                'Keine Abweichung gemerkt: Die Aufnahmen liegen laut Kamera mehr als %s auseinander (%s bis %s). '
                . 'Ausreißer bitte zuerst einzeln korrigieren.',
                str_replace('days', 'Tage', self::MAX_REMEMBERED_SPAN),
                min($times)->format('d.m.Y'),
                max($times)->format('d.m.Y'),
            ));
        }

        $offset = new CameraClockOffset(
            array_map(static fn(Medium $m): ?string => $m->getCamera(), $media),
            min($times),
            max($times),
            $correct->getTimestamp() - $anchorTime->getTimestamp(),
            $label,
        );
        $this->entityManager->persist($offset);
        $this->entityManager->flush();
        $this->offsets = null;

        return $offset;
    }

    /**
     * Vorhandene Medien, auf die die Abweichung passt, aber (noch) nicht angewendet ist.
     *
     * @return list<Medium>
     */
    public function pending(CameraClockOffset $offset): array
    {
        $from = $offset->getCameraFrom()->modify('-' . CameraClockOffset::MARGIN);
        $to = $offset->getCameraTo()->modify('+' . CameraClockOffset::MARGIN);
        $candidates = $this->entityManager
            ->createQueryBuilder()
            ->select('m')
            ->from(Medium::class, 'm')
            ->where('m.cameraTakenAt BETWEEN :from AND :to')
            ->andWhere('m.dateSource IS NULL OR m.dateSource NOT IN (:corrected)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('corrected', [Medium::DATE_MANUAL, Medium::DATE_CAMERA_OFFSET])
            ->orderBy('m.cameraTakenAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($candidates, $offset->matches(...)));
    }

    /**
     * @return int Anzahl verschobener Medien
     */
    public function applyToExisting(CameraClockOffset $offset): int
    {
        $changed = [];
        foreach ($this->pending($offset) as $medium) {
            $cameraTime = $medium->getCameraTakenAt();
            if ($cameraTime !== null) {
                $medium->setRecordedAt(
                    $cameraTime->modify(sprintf('%+d seconds', $offset->getOffsetSeconds())),
                    Medium::DATE_CAMERA_OFFSET,
                );
                $changed[] = $medium;
            }
        }
        $this->entityManager->flush();
        $this->indexer->reindex($changed);

        return \count($changed);
    }

    /**
     * Hinweise, warum die Aufnahmezeit falsch sein könnte.
     *
     * @return list<string>
     */
    public function warnings(Medium $medium): array
    {
        $warnings = [];
        $time = $medium->getCameraTakenAt();
        if ($medium->getDateSource() !== Medium::DATE_MANUAL && $time !== null) {
            if ($time < new \DateTimeImmutable(self::EARLIEST_PLAUSIBLE)) {
                $warnings[] = sprintf(
                    'Die Kamera meldet %s: Ihre Uhr war vermutlich nicht gestellt (z. B. nach leerem Akku).',
                    $time->format('Y'),
                );
            } elseif ($time > new \DateTimeImmutable('+1 day')) {
                $warnings[] = 'Die Aufnahmezeit liegt in der Zukunft: Die Uhr der Kamera ging vor.';
            }
        }
        if (\in_array(
            $medium->getDateSource(),
            [Medium::DATE_EXIF, Medium::DATE_FILENAME, Medium::DATE_METADATA],
            true,
        )) {
            $offset = $this->findOffset($medium);
            if ($offset !== null) {
                $warnings[] = sprintf(
                    'Für diese Kamera ist eine Abweichung von %s gemerkt, hier aber noch nicht angewendet.',
                    $offset->getOffsetLabel(),
                );
            }
        }

        return $warnings;
    }

    /**
     * @return list<CameraClockOffset> neueste zuerst: eine spätere Korrektur gewinnt
     */
    private function offsets(): array
    {
        return $this->offsets ??= $this->entityManager->getRepository(CameraClockOffset::class)->findBy([], [
            'id' => 'DESC',
        ]);
    }
}
