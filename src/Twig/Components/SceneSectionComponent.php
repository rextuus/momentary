<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\VideoScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SceneSectionComponent
{
    use DefaultActionTrait;

    #[LiveProp] // Die Scene kann Symfony automatisch dehydrieren (via ID)
    public VideoScene $scene;

    #[LiveProp]
    public int $sceneNumber;

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function getFaces(): iterable
    {
        return $this->scene->getVideoFaces();
    }

    #[LiveAction]
    public function updateTitle(): void
    {
        // Durch LiveProp(writable: true) wird der Titel automatisch im Objekt gesetzt.
        // Wir müssen nur noch flushen.
        $this->entityManager->flush();
    }

    #[LiveAction]
    public function mergeWithNext(): void
    {
        $video = $this->scene->getVideo();
        $nextScene = $this->findSceneByNumber($this->scene->getSceneNumber() + 1);

        if ($nextScene) {
            $this->performMerge($this->scene, $nextScene);
        }
    }

    #[LiveAction]
    public function mergeEmptyBackwards(): void
    {
        $targetScene = $this->getTargetSceneForBackwardsMerge();
        if (!$targetScene) {
            return;
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

        header("Refresh:0");
        exit;
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

        header("Refresh:0");
        exit;
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

    public function getDuration(): float
    {
        return round($this->scene->getEndSeconds() - $this->scene->getStartSeconds(), 1);
    }
}