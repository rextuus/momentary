<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Tag;
use App\Entity\TagCategory;
use App\Entity\VideoScene;
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

    #[LiveProp] // Die Scene kann Symfony automatisch dehydrieren (via ID)
    public VideoScene $scene;

    #[LiveProp]
    public int $sceneNumber;

    #[LiveProp(writable: true)]
    public ?int $assignToChapterId = null;

    #[LiveProp(writable: true)]
    public string $newChapterTitle = '';

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function getCategories(): array
    {
        return $this->entityManager->getRepository(TagCategory::class)->findAll();
    }

    #[LiveAction]
    public function toggleTag(#[LiveArg] int $tagId): void
    {
        $tag = $this->entityManager->getRepository(Tag::class)->find($tagId);
        if (!$tag) return;

        if ($this->scene->getTags()->contains($tag)) {
            $this->scene->removeTag($tag);
        } else {
            $this->scene->addTag($tag);
        }

        $this->entityManager->flush();
    }

    public function getChapters(): array
    {
        return $this->entityManager->getRepository(\App\Entity\VideoChapter::class)->findBy(['video' => $this->scene->getVideo()]);
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
        $chapter->setStartSeconds(min($chapter->getStartSeconds(), $this->scene->getStartSeconds()));
        $chapter->setEndSeconds(max($chapter->getEndSeconds(), $this->scene->getEndSeconds()));

        $this->entityManager->flush();
        $this->assignToChapterId = null;
        
        return $this->redirectToRoute('video_timeline', ['id' => $this->scene->getVideo()->getId()]);
    }

    #[LiveAction]
    public function createChapterAndAssignFollowing(): ?Response
    {
        if (empty($this->newChapterTitle)) {
            return null;
        }

        $video = $this->scene->getVideo();
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
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function mergeWithNext(): Response
    {
        $video = $this->scene->getVideo();
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

        $video = $this->scene->getVideo();
        $scenesToDelete = [];
        $maxEndSeconds = $this->scene->getEndSeconds();

        // Alle Szenen zwischen targetScene und der aktuellen (inklusive) finden
        foreach ($video->getScenes() as $s) {
            if ($s->getSceneNumber() > $targetScene->getSceneNumber() && $s->getSceneNumber() <= $this->scene->getSceneNumber()) {
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
        $video = $this->scene->getVideo();
        $scenes = $video->getScenes()->toArray();
        
        // Sortieren nach Szenennummer absteigend, startend vor der aktuellen Szene
        usort($scenes, fn($a, $b) => $b->getSceneNumber() <=> $a->getSceneNumber());

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
        foreach ($this->scene->getVideo()->getScenes() as $s) {
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
        $video = $this->scene->getVideo();
        $this->entityManager->refresh($video);
        
        $scenes = $video->getScenes()->toArray();
        usort($scenes, fn($a, $b) => $a->getStartSeconds() <=> $b->getStartSeconds());

        foreach ($scenes as $index => $s) {
            $s->setSceneNumber($index + 1);
        }

        $this->entityManager->flush();
    }

    public function getFaces(): iterable
    {
        return $this->scene->getVideoFaces();
    }

    public function getDuration(): float
    {
        return round($this->scene->getEndSeconds() - $this->scene->getStartSeconds(), 1);
    }
}