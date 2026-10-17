<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Entity\UserGroup;
use App\Entity\Video;
use App\Entity\VideoFace;
use App\Entity\VideoScene;
use App\Service\Video\Analyze\SceneThumbnailExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SceneSectionComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: ['title'])] // Die Scene kann Symfony automatisch dehydrieren (via ID)
    public VideoScene $scene;

    #[LiveProp]
    public int $sceneNumber;

    #[LiveProp(writable: true)]
    public ?int $assignToChapterId = null;

    #[LiveProp(writable: true)]
    public string $newChapterTitle = '';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SceneThumbnailExtractor $thumbnailExtractor,
    ) {}

    /**
     * @return list<TagCategory>
     */
    public function getCategories(): array
    {
        return $this->entityManager->getRepository(TagCategory::class)->findAll();
    }

    /**
     * @return list<UserGroup>
     */
    public function getGroups(): array
    {
        return $this->entityManager->getRepository(UserGroup::class)->findAll();
    }

    #[LiveAction]
    public function toggleTag(#[LiveArg] int $tagId): void
    {
        $tag = $this->entityManager->getRepository(Tag::class)->find($tagId);
        if (!$tag) {
            return;
        }

        $hasTag = false;
        foreach ($this->scene->getSceneTags() as $sceneTag) {
            if ($sceneTag->getTag() === $tag) {
                $hasTag = true;
                break;
            }
        }

        if ($hasTag) {
            $this->scene->removeTag($tag);
        } else {
            $this->scene->addTag($tag);
        }

        $this->entityManager->flush();
    }

    #[LiveAction]
    public function toggleGroup(#[LiveArg] int $groupId): void
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($groupId);
        if (!$group) {
            return;
        }

        if ($this->scene->getAllowedGroups()->contains($group)) {
            $this->scene->removeAllowedGroup($group);
        } else {
            $this->scene->addAllowedGroup($group);
        }

        $this->entityManager->flush();
    }

    #[LiveAction]
    public function togglePublic(): void
    {
        $this->scene->setIsPublic(!$this->scene->isPublic());
        $this->entityManager->flush();
    }

    /**
     * @return list<\App\Entity\VideoChapter>
     */
    public function getChapters(): array
    {
        return $this->entityManager
            ->getRepository(\App\Entity\VideoChapter::class)
            ->findBy(['video' => $this->video()]);
    }

    /**
     * @return list<array{path: string, timestamp: float}>
     */
    public function getFramesForScene(): array
    {
        $video = $this->video();
        $dir = $video->getCurrentFrameDirectory();

        if (!$dir || !is_dir($dir)) {
            return [];
        }

        $fps = $video->getAnalysisFps() ?? 0.2;
        if ($fps <= 0) {
            $fps = 0.2;
        }

        $frames = [];
        $files = glob($dir . '/frame_*.jpg');
        if (!$files) {
            return [];
        }
        sort($files);

        foreach ($files as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            $parts = explode('_', $stem);
            if (count($parts) < 2) {
                continue;
            }

            $number = (int) $parts[1];
            $timestamp = $number * (1 / $fps);

            if ($timestamp >= $this->scene->getStartSeconds() && $timestamp <= $this->scene->getEndSeconds()) {
                $relativePath = str_replace($this->getParameter('kernel.project_dir') . '/public/', '', $file);
                $frames[] = [
                    'path' => $relativePath,
                    'timestamp' => $timestamp,
                ];
            }
        }

        return $frames;
    }

    #[LiveAction]
    public function assignToChapter(): ?Response
    {
        if (!$this->assignToChapterId) {
            return null;
        }

        $chapter = $this->entityManager->getRepository(\App\Entity\VideoChapter::class)->find($this->assignToChapterId);
        if (!$chapter) {
            return null;
        }

        // Kapitel erweitern, um diese Szene einzuschließen
        $chapter->setStartSeconds(min(
            $chapter->getStartSeconds() ?? $this->scene->getStartSeconds(),
            $this->scene->getStartSeconds(),
        ));
        $chapter->setEndSeconds(max(
            $chapter->getEndSeconds() ?? $this->scene->getEndSeconds(),
            $this->scene->getEndSeconds(),
        ));

        $this->entityManager->flush();
        $this->assignToChapterId = null;

        return $this->redirectToRoute('video_timeline', ['id' => $this->video()->getId()]);
    }

    #[LiveAction]
    public function createChapterAndAssignFollowing(): ?Response
    {
        if (empty($this->newChapterTitle)) {
            return null;
        }

        $video = $this->video();
        $scenes = $video->getScenes();

        $maxEndTime = $this->scene->getEndSeconds();
        foreach ($scenes as $s) {
            if ($s->getSceneNumber() >= $this->scene->getSceneNumber()) {
                $maxEndTime = max($maxEndTime, $s->getEndSeconds());
            }
        }

        $chapter = new \App\Entity\VideoChapter();
        $chapter->setVideo($video);
        $chapter->setTitle($this->newChapterTitle);
        $chapter->setStartSeconds($this->scene->getStartSeconds());
        $chapter->setEndSeconds($maxEndTime);

        $this->entityManager->persist($chapter);
        $this->entityManager->flush();

        $this->newChapterTitle = '';

        return $this->redirectToRoute('video_timeline', ['id' => $video->getId()]);
    }

    #[LiveAction]
    public function updateTitle(): void
    {
        // Durch LiveProp(writable: true) wird der Titel automatisch im Objekt gesetzt.
        // Wir müssen nur noch flushen.
        $this->entityManager->persist($this->scene);
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function refreshThumbnail(): void
    {
        $this->thumbnailExtractor->extractSceneThumbnail($this->scene);
    }

    #[LiveAction]
    public function mergeWithNext(): Response
    {
        $video = $this->video();
        $nextScene = $this->findSceneByNumber($this->scene->getSceneNumber() + 1);

        if ($nextScene) {
            $this->performMerge($this->scene, $nextScene);
        }

        return $this->redirectToRoute('video_timeline', ['id' => $video->getId()]);
    }

    #[LiveAction]
    public function mergeEmptyBackwards(): ?Response
    {
        $targetScene = $this->getTargetSceneForBackwardsMerge();
        if (!$targetScene) {
            return null;
        }

        $video = $this->video();
        $scenesToDelete = [];
        $maxEndSeconds = $this->scene->getEndSeconds();

        // Alle Szenen zwischen targetScene und der aktuellen (inklusive) finden
        foreach ($video->getScenes() as $s) {
            if (
                $s->getSceneNumber() > $targetScene->getSceneNumber()
                && $s->getSceneNumber() <= $this->scene->getSceneNumber()
            ) {
                $scenesToDelete[] = $s;
            }
        }

        foreach ($scenesToDelete as $s) {
            // Sicherheitshalber Gesichter umhängen, falls doch welche da sind
            foreach ($s->getVideoFaces() as $face) {
                $face->setVideoScene($targetScene);
            }
            $this->entityManager->remove($s);
        }

        $targetScene->setEndSeconds($maxEndSeconds);
        $this->entityManager->flush();

        $this->renumberScenes();

        return $this->redirectToRoute('video_timeline', ['id' => $video->getId()]);
    }

    public function canMergeBackwards(): bool
    {
        // Aktuelle Szene muss leer sein
        if ($this->scene->getVideoFaces()->count() > 0) {
            return false;
        }

        return $this->getTargetSceneForBackwardsMerge() !== null;
    }

    private function getTargetSceneForBackwardsMerge(): ?VideoScene
    {
        $video = $this->video();
        $scenes = $video->getScenes()->toArray();

        // Sortieren nach Szenennummer absteigend, startend vor der aktuellen Szene
        usort($scenes, static fn($a, $b) => $b->getSceneNumber() <=> $a->getSceneNumber());

        foreach ($scenes as $s) {
            if ($s->getSceneNumber() >= $this->scene->getSceneNumber()) {
                continue;
            }

            if ($s->getVideoFaces()->count() > 0) {
                return $s;
            }
        }

        return null;
    }

    private function findSceneByNumber(int $number): ?VideoScene
    {
        foreach ($this->video()->getScenes() as $s) {
            if ($s->getSceneNumber() === $number) {
                return $s;
            }
        }
        return null;
    }

    private function performMerge(VideoScene $keep, VideoScene $remove): void
    {
        $keep->setEndSeconds($remove->getEndSeconds());

        foreach ($remove->getVideoFaces() as $face) {
            $face->setVideoScene($keep);
        }

        $this->entityManager->remove($remove);
        $this->entityManager->flush();

        $this->renumberScenes();
    }

    private function renumberScenes(): void
    {
        $video = $this->video();
        $this->entityManager->refresh($video);

        $scenes = $video->getScenes()->toArray();
        usort($scenes, static fn($a, $b) => $a->getStartSeconds() <=> $b->getStartSeconds());

        foreach ($scenes as $index => $s) {
            $s->setSceneNumber($index + 1);
        }

        $this->entityManager->flush();
    }

    /**
     * @return iterable<VideoFace>
     */
    public function getFaces(): iterable
    {
        return $this->scene->getVideoFaces();
    }

    /**
     * Szenen gehören laut Mapping immer zu einem Video (JoinColumn nullable: false).
     */
    private function video(): Video
    {
        return (
            $this->scene->getVideo() ?? throw new \LogicException(sprintf(
                'Scene %d has no video.',
                (int) $this->scene->getId(),
            ))
        );
    }

    public function getDuration(): float
    {
        return round($this->scene->getEndSeconds() - $this->scene->getStartSeconds(), 1);
    }
}
