<?php

declare(strict_types=1);

namespace App\Service\Video\Recording;

use App\Entity\RecordingSegment;
use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wiederkehrende Kameradaten über mehrere Kassetten zur Prüfung markieren (documentation/dv_aufnahmedatum.md, 6).
 * Nach leerem Pufferakku springen Camcorder auf ein Werksdatum und zählen weiter, dann tauchen dieselben Tage auf
 * verschiedenen Kassetten auf. Genauso normal ist es aber bei Hochzeit, Taufe oder Urlaub über mehrere Kassetten.
 * Deshalb nur ein Hinweis in der Oberfläche (RecordingSegment::recurringDate); das Datum gilt weiter.
 *
 * Hinweis für einen Kalendertag (Kamerazeit), wenn
 * - Segmente aus mindestens zwei Kassetten an diesem Tag kurz nach Mitternacht beginnen (vor NIGHT_UNTIL_HOUR), oder
 * - Segmente aus mindestens MIN_CASSETTES Kassetten an diesem Tag liegen.
 * Markiert werden die Segmente aller beteiligten Kassetten. Die Markierung wird nur gesetzt, nie automatisch entfernt.
 */
class RecurringRecordingDates
{
    public const int NIGHT_UNTIL_HOUR = 3;
    public const int MIN_CASSETTES = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function mark(Video $video): void
    {
        $zone = new \DateTimeZone(DvSubcodeReader::CAMERA_TIMEZONE);
        $days = [];
        foreach ($video->getRecordingSegments() as $segment) {
            $time = $segment->getCameraRecordedAt();
            if ($time !== null) {
                $days[$time->setTimezone($zone)->format('Y-m-d')] = true;
            }
        }

        $changed = false;
        foreach (array_keys($days) as $day) {
            $segments = $this->segmentsOnDay((string) $day, $zone);
            if (!self::isRecurring($segments, $zone)) {
                continue;
            }
            foreach ($segments as $segment) {
                if (!$segment->isRecurringDate()) {
                    $segment->setRecurringDate(true);
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }

    /**
     * @param list<RecordingSegment> $segments Segmente eines Kalendertags
     */
    public static function isRecurring(array $segments, \DateTimeZone $zone): bool
    {
        $cassettes = [];
        $nightCassettes = [];
        foreach ($segments as $segment) {
            $videoId = $segment->getVideo()?->getId() ?? spl_object_id($segment);
            $cassettes[$videoId] = true;
            $hour = (int) $segment->getCameraRecordedAt()?->setTimezone($zone)->format('G');
            if ($hour < self::NIGHT_UNTIL_HOUR) {
                $nightCassettes[$videoId] = true;
            }
        }

        return \count($nightCassettes) >= 2 || \count($cassettes) >= self::MIN_CASSETTES;
    }

    /**
     * @return list<RecordingSegment>
     */
    private function segmentsOnDay(string $day, \DateTimeZone $zone): array
    {
        $start = new \DateTimeImmutable($day . ' 00:00:00', $zone);

        return $this->entityManager
            ->createQueryBuilder()
            ->select('s')
            ->from(RecordingSegment::class, 's')
            ->where('s.cameraRecordedAt >= :start AND s.cameraRecordedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $start->modify('+1 day'))
            ->getQuery()
            ->getResult();
    }
}
