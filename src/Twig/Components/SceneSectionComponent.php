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
        $nextScene = null;

        // Suche die Szene, die direkt nach dieser kommt
        foreach ($video->getScenes() as $s) {
            if ($s->getSceneNumber() === ($this->scene->getSceneNumber() + 1)) {
                $nextScene = $s;
                break;
            }
        }

        if ($nextScene) {
            // 1. Endzeit der aktuellen Szene erweitern
            $this->scene->setEndSeconds($nextScene->getEndSeconds());

            // 2. Alle Gesichter der nächsten Szene auf die aktuelle umbiegen
            foreach ($nextScene->getVideoFaces() as $face) {
                $face->setVideoScene($this->scene);
            }

            // 3. Die nächste Szene löschen
            $this->entityManager->remove($nextScene);
            $this->entityManager->flush();

            // Da sich die Struktur der Timeline geändert hat (eine Szene weniger),
            // laden wir die Seite einmal neu, damit die Liste aktuell ist.
            header("Refresh:0");
            exit;
        }
    }

    public function getDuration(): float
    {
        return round($this->scene->getEndSeconds() - $this->scene->getStartSeconds(), 1);
    }
}