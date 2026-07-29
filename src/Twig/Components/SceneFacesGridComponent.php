<?php

namespace App\Twig\Components;

use App\Entity\VideoFace;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SceneFacesGridComponent
{
    /** @var iterable<VideoFace> */
    public iterable $faces = [];
}
