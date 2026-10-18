<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\TranscriptSegment;
use App\Entity\Video;
use App\Entity\VideoScene;
use App\Entity\VideoTranscript;
use App\Repository\VideoRepository;
use App\Service\Transcription\TranscriptionRequester;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class VideoTranscriptComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    #[LiveProp(writable: true)]
    public string $query = '';

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly TranscriptionRequester $requester,
    ) {}

    public function getTranscript(): ?VideoTranscript
    {
        $video = $this->videoRepository->find($this->video->getId()) ?? $this->video;

        return $video->getTranscript();
    }

    public function isPending(): bool
    {
        return $this->getTranscript()?->getStatus()->isPending() ?? false;
    }

    public function getBlockedReason(): ?string
    {
        return $this->requester->whyNot($this->video);
    }

    /**
     * Abschnitte nach Szenen gruppiert; Abschnitte außerhalb jeder Szene landen in einer Gruppe ohne Szene.
     *
     * @return list<array{scene: ?VideoScene, segments: list<TranscriptSegment>}>
     */
    public function getGroups(): array
    {
        $transcript = $this->getTranscript();
        if ($transcript === null) {
            return [];
        }

        $needle = mb_strtolower(trim($this->query));
        $scenes = $transcript->getVideo()->getScenes()->toArray();
        usort($scenes, static fn(VideoScene $a, VideoScene $b): int => $a->getStartSeconds() <=> $b->getStartSeconds());

        $groups = [];
        foreach ($transcript->getSegments() as $segment) {
            if ($needle !== '' && !str_contains(mb_strtolower($segment->getText()), $needle)) {
                continue;
            }
            // Szene, in der der Abschnitt beginnt
            $scene = null;
            foreach ($scenes as $candidate) {
                if (
                    $segment->getStartSeconds() >= (float) $candidate->getStartSeconds()
                    && $segment->getStartSeconds() < (float) $candidate->getEndSeconds()
                ) {
                    $scene = $candidate;
                    break;
                }
            }
            $key = $scene?->getId() ?? 0;
            $groups[$key] ??= ['scene' => $scene, 'segments' => []];
            $groups[$key]['segments'][] = $segment;
        }

        return array_values($groups);
    }

    public static function formatTime(float $seconds): string
    {
        $seconds = (int) floor($seconds);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
