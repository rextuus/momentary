<?php

declare(strict_types=1);

namespace App\Service\Video\Progress;

use App\Entity\Video;
use App\Entity\VideoProcessingStep;
use App\Enum\VideoStatus;
use Psr\Clock\ClockInterface;

/**
 * Berechnet den Fortschritt eines Videos über alle Pipeline-Schritte.
 *
 * Wo die Anzahl der Elemente bekannt ist (Frames, Szenen), wird gezählt. Sonst wird die bisherige Laufzeit mit
 * der erwarteten Dauer verglichen; dauert ein Schritt länger als erwartet, nähert sich der Balken langsam 99 %,
 * statt stehen zu bleiben.
 */
class VideoProgressCalculator
{
    /** Anteil, den ein zeitgeschätzter Schritt bei Erreichen der erwarteten Dauer anzeigt */
    private const float TIME_BASED_ON_SCHEDULE = 0.9;

    private const array COUNTED_UNITS = [
        'analyzing_faces_initial' => 'Frames',
        'analyzing_faces_refinement' => 'Frames',
        'refining_splitting' => 'Szenen',
        'tagging_scenes' => 'Szenen',
        'extracting_thumbnails' => 'Szenen',
    ];

    public function __construct(
        private readonly StepDurationEstimator $estimator,
        private readonly ClockInterface $clock,
    ) {}

    public function calculate(Video $video): VideoProgress
    {
        $status = $video->getStatus();
        $currentIndex = array_search($status, VideoStatus::pipelineSteps(), true);
        $now = $this->clock->now()->getTimestamp();

        $steps = [];
        $current = null;
        $weightTotal = 0.0;
        $weightDone = 0.0;
        $remaining = 0.0;

        foreach (VideoStatus::pipelineSteps() as $index => $step) {
            $entity = $this->findStepEntity($video, $step);
            $expected = $this->estimator->expectedSeconds($video, $step);
            $weightTotal += $expected;

            $state = $this->stateFor($status, $currentIndex, $index, $entity);

            if ($state === StepProgress::ACTIVE) {
                $current = $this->activeStep($video, $step, $entity, $expected, $now);
                $weightDone += $expected * ($current->fraction ?? 0.0);
                $remaining += $this->remainingForActive($current, $expected);
                $steps[] = $current;

                continue;
            }

            if ($state === StepProgress::DONE) {
                $weightDone += $expected;
            } elseif ($state === StepProgress::PENDING && $currentIndex !== false) {
                $remaining += $expected;
            }

            $seconds = $state === StepProgress::DONE ? $entity?->getDuration() : null;
            $steps[] = new StepProgress(
                $step,
                $state,
                null,
                false,
                $this->detailFor($state, $seconds, $video, $step),
                $seconds,
            );
        }

        $percent = match (true) {
            $status === VideoStatus::COMPLETED => 100,
            $weightTotal <= 0.0 => 0,
            default => min(99, (int) floor(($weightDone / $weightTotal) * 100)),
        };

        return new VideoProgress($steps, $percent, $current, $currentIndex !== false ? (int) round($remaining) : null);
    }

    private function stateFor(
        VideoStatus $status,
        int|false $currentIndex,
        int $index,
        ?VideoProcessingStep $entity,
    ): string {
        if ($status === VideoStatus::COMPLETED) {
            return StepProgress::DONE;
        }

        if ($status === VideoStatus::ERROR) {
            return match (true) {
                $entity?->getErrorMessage() !== null => StepProgress::ERROR,
                $entity?->getFinishedAt() !== null => StepProgress::DONE,
                default => StepProgress::PENDING,
            };
        }

        if ($currentIndex === false) {
            return StepProgress::PENDING;
        }

        return match (true) {
            $index < $currentIndex => StepProgress::DONE,
            $index === $currentIndex => StepProgress::ACTIVE,
            default => StepProgress::PENDING,
        };
    }

    private function activeStep(
        Video $video,
        VideoStatus $step,
        ?VideoProcessingStep $entity,
        float $expected,
        int $now,
    ): StepProgress {
        $startedAt = $entity?->getStartedAt()?->getTimestamp() ?? $now;
        $elapsed = max(0, $now - $startedAt);

        $counted = $this->countedProgress($video, $step);
        if ($counted !== null && $counted['total'] > 0) {
            $fraction = min(0.99, $counted['done'] / $counted['total']);

            return new StepProgress(
                $step,
                StepProgress::ACTIVE,
                $fraction,
                true,
                sprintf(
                    '%d von %d %s',
                    $counted['done'],
                    $counted['total'],
                    self::COUNTED_UNITS[$step->value] ?? 'Elementen',
                ),
                $elapsed,
            );
        }

        $ratio = $elapsed / $expected;
        $fraction = $ratio <= 1.0
            ? self::TIME_BASED_ON_SCHEDULE * $ratio
            : self::TIME_BASED_ON_SCHEDULE + ((0.99 - self::TIME_BASED_ON_SCHEDULE) * (1 - exp(1 - $ratio)));

        $detail = sprintf(
            'läuft seit %s · üblich ca. %s',
            VideoProgress::formatSeconds($elapsed),
            VideoProgress::formatSeconds((int) round($expected)),
        );

        return new StepProgress($step, StepProgress::ACTIVE, $fraction, false, $detail, $elapsed);
    }

    /**
     * @return array{done: int, total: int}|null
     */
    private function countedProgress(Video $video, VideoStatus $step): ?array
    {
        if ($step === VideoStatus::EXTRACTING_THUMBNAILS) {
            $total = $video->getScenes()->count();
            $done = $video
                ->getScenes()
                ->filter(static fn($scene) => $scene->getThumbnailFile() !== null)
                ->count();

            return $total > 0 && $done > 0 ? ['done' => $done, 'total' => $total] : null;
        }

        return $video->getProgressFor($step);
    }

    private function remainingForActive(StepProgress $current, float $expected): float
    {
        $elapsed = (float) ($current->seconds ?? 0);
        $fraction = $current->fraction ?? 0.0;

        if ($current->counted && $fraction > 0.0) {
            return ($elapsed / $fraction) * (1 - $fraction);
        }

        return max(0.0, $expected - $elapsed);
    }

    private function detailFor(string $state, ?int $seconds, Video $video, VideoStatus $step): string
    {
        return match ($state) {
            StepProgress::DONE => $seconds !== null ? 'fertig in ' . VideoProgress::formatSeconds($seconds) : 'fertig',
            StepProgress::ERROR => 'fehlgeschlagen',
            default => 'ca. '
                . VideoProgress::formatSeconds((int) round($this->estimator->expectedSeconds($video, $step))),
        };
    }

    private function findStepEntity(Video $video, VideoStatus $step): ?VideoProcessingStep
    {
        foreach ($video->getProcessingSteps() as $entity) {
            if ($entity->getStep() === $step) {
                return $entity;
            }
        }

        return null;
    }
}
