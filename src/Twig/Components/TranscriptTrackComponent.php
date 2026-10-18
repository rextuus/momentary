<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\TranscriptSegment;
use App\Entity\Video;
use App\Entity\VideoTranscript;
use App\Enum\TranscriptionStatus;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Sprach-Spur der Timeline: ein Balken über die Videolänge mit allen gesprochenen Abschnitten.
 */
#[AsTwigComponent]
final class TranscriptTrackComponent
{
    public Video $video;

    public function getTranscript(): ?VideoTranscript
    {
        $transcript = $this->video->getTranscript();

        return $transcript?->getStatus() === TranscriptionStatus::DONE ? $transcript : null;
    }

    /**
     * @return list<array{left: float, width: float, start: int, label: string, text: string}>
     */
    public function getBlocks(): array
    {
        $transcript = $this->getTranscript();
        if ($transcript === null || $transcript->getSegments()->isEmpty()) {
            return [];
        }

        $segments = array_values($transcript->getSegments()->toArray());
        $length = max(
            (float) ($this->video->getDuration() ?? 0.0),
            $transcript->getAudioSeconds(),
            ...array_map(static fn(TranscriptSegment $s): float => $s->getEndSeconds(), $segments),
        );

        return array_map(
            static fn(TranscriptSegment $s): array => [
                'left' => round(($s->getStartSeconds() / $length) * 100, 3),
                // Mindestbreite, damit auch kurze Ausrufe sichtbar und anklickbar bleiben
                'width' => round(max(0.5, (($s->getEndSeconds() - $s->getStartSeconds()) / $length) * 100), 3),
                'start' => (int) floor($s->getStartSeconds()),
                'label' => VideoTranscriptComponent::formatTime($s->getStartSeconds()),
                'text' => $s->getText(),
            ],
            $segments,
        );
    }

    /**
     * Gesprochener Anteil am Video in Prozent.
     */
    public function getSpeechShare(): int
    {
        $transcript = $this->getTranscript();
        $length = max((float) ($this->video->getDuration() ?? 0.0), $transcript?->getAudioSeconds() ?? 0.0);
        if ($transcript === null || $length <= 0.0) {
            return 0;
        }

        $spoken = 0.0;
        foreach ($transcript->getSegments() as $segment) {
            $spoken += $segment->getEndSeconds() - $segment->getStartSeconds();
        }

        return (int) min(100, round(($spoken / $length) * 100));
    }
}
