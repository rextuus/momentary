<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\VideoScene;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class SceneSectionComponent
{
    public VideoScene $scene;
    public iterable $faces;
    public int $sceneNumber;

    // Muss PUBLIC sein!
    public function getDuration(): float
    {
        return round($this->scene->getEndSeconds() - $this->scene->getStartSeconds(), 1);
    }
}