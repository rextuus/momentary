<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Video;
use App\Repository\VideoSceneRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\Computed;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
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
        private VideoSceneRepository $sceneRepository
    ) {}

    #[LiveAction]
    public function loadMore(): void
    {
        error_log('loadMore action called. Old page: ' . $this->page);
        $this->page++;
        error_log('New page: ' . $this->page);
    }

    #[Computed]
    public function getScenes(): array
    {
        return $this->sceneRepository->findBy(
            ['video' => $this->video],
            ['startSeconds' => 'ASC'],
            $this->page * self::PAGE_SIZE,
            0
        );
    }
}
