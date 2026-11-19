<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

use App\Entity\Medium;
use App\Entity\RecordingSegment;
use App\Entity\Video;
use App\Service\Video\Analyze\Mp4Converter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aufnahme-Segmente eines Videos aus der Quelldatei lesen (nur DV) und speichern.
 *
 * Gelesen wird bei jedem Verarbeiten, solange die Quelldatei DV ist; ist sie schon umgewandelt, bleiben die
 * gespeicherten Segmente. Von Hand angelegte Segmente überschreibt keine Analyse. Kann die Datei nicht gelesen
 * werden, läuft die Verarbeitung weiter wie bei einer DV-Datei ohne Subcodes.
 */
class RecordingSegments
{
    public function __construct(
        private readonly DvSubcodeReader $reader,
        private readonly Mp4Converter $mp4Converter,
        private readonly RecurringRecordingDates $recurringDates,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return bool ob Segmente neu gelesen wurden
     */
    public function read(Video $video, string $path): bool
    {
        if (($this->mp4Converter->probe($path)['video']['codec'] ?? null) !== 'dvvideo') {
            return false;
        }
        foreach ($video->getRecordingSegments() as $segment) {
            if ($segment->getSource() === RecordingSegment::SOURCE_MANUAL) {
                return false;
            }
        }

        try {
            $subcodes = $this->reader->read($path);
        } catch (\RuntimeException $e) {
            $this->logger->warning('DV-Subcodes von Video {id} nicht lesbar: {message}', [
                'id' => $video->getId(),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $video->replaceRecordingSegments(RecordingSegmentBuilder::build($subcodes));
        $this->entityManager->flush();
        $this->recurringDates->mark($video);

        return true;
    }

    /**
     * Aufnahmezeit des Videos: Beginn des ersten Segments mit verlässlichem Datum.
     */
    public static function firstReliableDate(Video $video): ?\DateTimeImmutable
    {
        foreach ($video->getRecordingSegments() as $segment) {
            if ($segment->hasReliableDate()) {
                return $segment->getRecordedAt();
            }
        }

        return null;
    }

    /**
     * DV-Datei, deren Segmente kein verlässliches Datum haben: Dateiname und creation_time sind dann meist nur das
     * Datum des Digitalisierens.
     */
    public static function isUndatedDv(Video $video): bool
    {
        return !$video->getRecordingSegments()->isEmpty() && self::firstReliableDate($video) === null;
    }

    /**
     * Hinweise zur Aufnahmezeit einer Kassette (CameraClock::warnings).
     *
     * @return list<string>
     */
    public static function warnings(Video $video): array
    {
        $warnings = [];
        if (
            self::isUndatedDv($video)
            && \in_array($video->getDateSource(), [Medium::DATE_FILENAME, Medium::DATE_METADATA], true)
        ) {
            $warnings[] =
                'Die DV-Kassette enthält kein Aufnahmedatum (z. B. analog überspielt): Die Zeit stammt '
                . 'vermutlich vom Digitalisieren.';
        }
        $segments = $video->getRecordingSegments();
        $factory = $segments->filter(static fn(RecordingSegment $segment): bool => $segment->isSuspicious())->count();
        if ($factory > 0) {
            $warnings[] = sprintf(
                '%d Aufnahme(n) auf der Kassette zeigen den 1. Januar kurz nach Mitternacht: vermutlich Werkseinstellung '
                . 'der Uhr, das Datum wird nicht verwendet.',
                $factory,
            );
        }
        $recurring = $segments
            ->filter(static fn(RecordingSegment $segment): bool => $segment->isRecurringDate())
            ->count();
        if ($recurring > 0) {
            $warnings[] = sprintf(
                'Bitte prüfen: %d Aufnahme(n) liegen an einem Tag, der auch auf anderen Kassetten vorkommt (normal bei '
                . 'Feiern und Urlauben, aber auch typisch für eine zurückgesprungene Uhr).',
                $recurring,
            );
        }

        return $warnings;
    }
}
