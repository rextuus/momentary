<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Video;
use App\Entity\VideoChapter;
use App\Entity\VideoScene;
use App\Repository\VideoSceneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\Computed;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class TimelineComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    #[LiveProp]
    public int $page = 1;

    public const PAGE_SIZE = 10;

    public function getPageSize(): int
    {
        return self::PAGE_SIZE;
    }

    public function __construct(
        private VideoSceneRepository $sceneRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[LiveAction]
    public function updateChapterTitle(#[LiveArg] int $chapterId, #[LiveArg] string $newTitle): void
    {
        $chapter = $this->entityManager->getRepository(VideoChapter::class)->find($chapterId);
        if ($chapter) {
            $chapter->setTitle($newTitle);
            $this->entityManager->flush();
        }
    }

    #[LiveAction]
    public function loadMore(): void
    {
        error_log('loadMore action called. Old page: ' . $this->page);
        $this->page++;
        error_log('New page: ' . $this->page);
    }

    #[Computed]
    public function getItems(): array
    {
        if ($this->video->getChapters()->count() > 0) {
            $chapters = $this->video->getChapters();
            $result = [];
            foreach ($chapters as $chapter) {
                $scenes = $this->getScenesForChapter($chapter);
                $tags = [];
                $persons = [];
                foreach ($scenes as $scene) {
                    foreach ($scene->getTags() as $tag) {
                        $tags[$tag->getId()] = $tag;
                    }
                    foreach ($scene->getVideoFaces() as $face) {
                        if ($face->getPerson()) {
                            $personId = $face->getPerson()->getId();
                            if (!isset($persons[$personId])) {
                                $persons[$personId] = [
                                    'person' => $face->getPerson(),
                                    'count' => 0
                                ];
                            }
                            $persons[$personId]['count']++;
                        }
                    }
                }

                usort($persons, function ($a, $b) {
                    return $b['count'] <=> $a['count'];
                });

                $sortedPersons = [];
                foreach ($persons as $index => $personData) {
                    $sortedPersons[] = [
                        'person' => $personData['person'],
                        'isTop5' => $index < 5
                    ];
                }

                $result[] = [
                    'type' => 'chapter',
                    'data' => $chapter,
                    'tags' => array_values($tags),
                    'persons' => $sortedPersons,
                ];
            }
            return $result;
        }

        $scenes = $this->sceneRepository->findBy(
            ['video' => $this->video],
            ['startSeconds' => 'ASC'],
            $this->page * self::PAGE_SIZE,
            0
        );
        $result = [];
        foreach ($scenes as $scene) {
            $result[] = [
                'type' => 'scene',
                'data' => $scene,
            ];
        }
        return $result;
    }

    private function getScenesForChapter(VideoChapter $chapter): array
    {
        return $this->video->getScenes()->filter(function(VideoScene $scene) use ($chapter) {
            return $scene->getStartSeconds() >= $chapter->getStartSeconds() && $scene->getEndSeconds() <= $chapter->getEndSeconds();
        })->toArray();
    }
}
