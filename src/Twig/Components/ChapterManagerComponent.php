<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Video;
use App\Entity\VideoChapter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class ChapterManagerComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public Video $video;

    #[LiveProp(writable: true)]
    public string $newChapterTitle = '';

    #[LiveProp(writable: true)]
    public ?int $startSceneId = null;

    #[LiveProp(writable: true)]
    public ?int $endSceneId = null;

    #[LiveProp]
    public ?int $editingChapterId = null;

    #[LiveProp(writable: true)]
    public string $editingTitle = '';

    #[LiveProp(writable: true)]
    public ?int $editingStartSceneId = null;

    #[LiveProp(writable: true)]
    public ?int $editingEndSceneId = null;

    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * @return array<VideoChapter>
     */
    public function getChapters(): array
    {
        $chapters = $this->video->getChapters()->toArray();
        usort($chapters, fn($a, $b) => $a->getStartSeconds() <=> $b->getStartSeconds());
        return $chapters;
    }

    #[LiveAction]
    public function createChapter(): void
    {
        if (empty($this->newChapterTitle) || !$this->startSceneId || !$this->endSceneId) {
            return;
        }

        $startScene = $this->entityManager->getRepository(\App\Entity\VideoScene::class)->find($this->startSceneId);
        $endScene = $this->entityManager->getRepository(\App\Entity\VideoScene::class)->find($this->endSceneId);

        if (!$startScene || !$endScene) {
            return;
        }

        // Zeiten ermitteln (Sicherstellen, dass Start < Ende)
        $startTime = min($startScene->getStartSeconds(), $endScene->getStartSeconds());
        $endTime = max($startScene->getEndSeconds(), $endScene->getEndSeconds());

        $chapter = new VideoChapter();
        $chapter->setVideo($this->video);
        $chapter->setTitle($this->newChapterTitle);
        $chapter->setStartSeconds($startTime);
        $chapter->setEndSeconds($endTime);

        $this->entityManager->persist($chapter);
        $this->entityManager->flush();

        $this->newChapterTitle = '';
        $this->startSceneId = null;
        $this->endSceneId = null;
    }

    #[LiveAction]
    public function editChapter(#[LiveArg] int $id): void
    {
        $chapter = $this->entityManager->getRepository(VideoChapter::class)->find($id);
        if (!$chapter) {
            return;
        }

        $this->editingChapterId = $id;
        $this->editingTitle = $chapter->getTitle();

        // Szenen anhand der Zeiten finden
        $startScene = $this->entityManager->getRepository(\App\Entity\VideoScene::class)->findOneBy([
            'video' => $this->video,
            'startSeconds' => $chapter->getStartSeconds()
        ]);
        $endScene = $this->entityManager->getRepository(\App\Entity\VideoScene::class)->findOneBy([
            'video' => $this->video,
            'endSeconds' => $chapter->getEndSeconds()
        ]);

        $this->editingStartSceneId = $startScene?->getId();
        $this->editingEndSceneId = $endScene?->getId();
    }

    #[LiveAction]
    public function saveChapter(): void
    {
        if (!$this->editingChapterId) {
            return;
        }

        $chapter = $this->entityManager->getRepository(VideoChapter::class)->find($this->editingChapterId);
        if (!$chapter) {
            return;
        }

        $startScene = $this->entityManager->getRepository(\App\Entity\VideoScene::class)->find($this->editingStartSceneId);
        $endScene = $this->entityManager->getRepository(\App\Entity\VideoScene::class)->find($this->editingEndSceneId);

        if (!$startScene || !$endScene) {
            return;
        }

        $startTime = min($startScene->getStartSeconds(), $endScene->getStartSeconds());
        $endTime = max($startScene->getEndSeconds(), $endScene->getEndSeconds());

        $chapter->setTitle($this->editingTitle);
        $chapter->setStartSeconds($startTime);
        $chapter->setEndSeconds($endTime);

        $this->entityManager->flush();

        $this->cancelEdit();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingChapterId = null;
        $this->editingTitle = '';
        $this->editingStartSceneId = null;
        $this->editingEndSceneId = null;
    }

    #[LiveAction]
    public function deleteChapter(#[LiveArg] int $id): void
    {
        $chapter = $this->entityManager->getRepository(VideoChapter::class)->find($id);
        if ($chapter && $chapter->getVideo() === $this->video) {
            $this->entityManager->remove($chapter);
            $this->entityManager->flush();
        }
    }
}
